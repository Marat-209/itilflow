<?php

namespace GlpiPlugin\Itilflow;

use CommonITILObject;
use CronTask;
use Session;

/** Сохранённый запрос штатного статуса; не переводит маршрут на следующий этап. */
final class StatusSync
{
    public const NONE = 'none';
    public const QUEUED = 'queued';
    public const EXECUTING = 'executing';
    public const APPLIED = 'applied';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const UNKNOWN = 'unknown';

    /** Внутренний openStep движка уже подтвердил полномочие на переход. */
    public static function request(Step $step, int $targetStatus, int $sourceActor): array
    {
        self::inside();
        $context = self::context($step);
        if ($context === null || $sourceActor <= 0 || $sourceActor !== (int) Session::getLoginUserID()) {
            throw new \RuntimeException('Не подтверждён источник перехода этапа.');
        }
        [$instance, $item, $stage] = $context;
        if ($targetStatus !== (int) $stage->fields['ticket_status']) {
            throw new \RuntimeException('Цель синхронизации отличается от снимка этапа.');
        }
        // Исполнитель дочерней заявки не обязан иметь доступ к родительской; здесь её права не проверяются.
        if ($targetStatus === 0) { return self::result(self::NONE, 'not_requested'); }
        if (!self::validTarget($item, $targetStatus)) {
            throw new \RuntimeException('Целевой статус этапа не поддерживается.');
        }
        if (($step->fields['status_sync_state'] ?? self::NONE) !== self::NONE) {
            if (!self::bindingValid($step, $instance)) {
                throw new \RuntimeException('Прежняя связь синхронизации не подтверждена.');
            }
            return self::existing($step, $item);
        }
        $fields = ['status_sync_state'=>self::QUEUED, 'status_sync_target'=>$targetStatus,
            'status_sync_actor'=>$sourceActor, 'status_sync_before'=>(int)$item->fields['status'],
            'status_sync_after'=>(int)$item->fields['status'], 'status_sync_caller'=>$sourceActor,
            'status_sync_error'=>'awaiting_native_cron',
            'status_sync_definition_sha'=>(string)$instance->fields['definition_sha256']];
        $fields['status_sync_fingerprint'] = self::fingerprint($step, $instance, $fields);
        self::save($step, $fields);
        return self::result(self::QUEUED, 'awaiting_native_cron');
    }

    /** Только явная команда с сохранённой квитанцией; UNKNOWN/executing нельзя вернуть в очередь. */
    public static function retry(Step $step): array
    {
        self::inside();
        $context = self::context($step);
        if ($context === null) { return self::result(self::CANCELLED, 'stale_context'); }
        [$instance, $item] = $context;
        if (!Authorizer::isAdministrator() || Authorizer::itemError($item, UPDATE) !== null) {
            return self::result(self::FAILED, 'retry_denied');
        }
        if (!self::bindingValid($step, $instance)) { return self::result(self::UNKNOWN, 'binding_invalid'); }
        if ($step->fields['status_sync_state'] !== self::FAILED) { return self::existing($step, $item); }
        // После стороннего изменения статуса требуется новое решение; прежняя цель его не затирает.
        if ((int)$item->fields['status'] !== (int)$step->fields['status_sync_before']) {
            return self::result(self::FAILED, 'status_changed_since_request');
        }
        self::save($step, ['status_sync_state'=>self::QUEUED, 'status_sync_error'=>'explicit_retry',
            'status_sync_caller'=>(int)Session::getLoginUserID()]);
        return self::result(self::QUEUED, 'awaiting_native_cron');
    }

    /** Движок открывает внутренний контекст; обработчику принадлежат два уровня штатных транзакций. */
    public static function runCron(Step $step, CronTask $task): array
    {
        self::assertCron($task);
        if (!Engine::isInside()) { throw new \LogicException('Нет внутреннего вызова движка.'); }
        $tx = new TransactionScope();
        try {
            $context = self::context($step);
            if (($step->fields['status_sync_state'] ?? '') !== self::QUEUED) {
                $tx->commit(); return self::result(self::NONE, 'not_queued');
            }
            if ($context === null || !self::bindingValid($step, $context[0])) {
                self::save($step, ['status_sync_state'=>self::CANCELLED,'status_sync_error'=>'stale_context']);
                $tx->commit(); return self::result(self::CANCELLED,'stale_context');
            }
            self::save($step, ['status_sync_state'=>self::EXECUTING,'status_sync_error'=>'execution_unconfirmed']);
            $tx->commit(); // Сначала фиксируем начало исполнения, затем меняем штатный статус.
        } catch (\Throwable $e) {
            try { $tx->rollback(); } catch (\Throwable $ignored) {}
            return self::result(self::UNKNOWN,'claim_unknown');
        }
        // Сбой после этой точки оставляет EXECUTING: выборка больше не берёт такой запрос.
        $tx = new TransactionScope();
        try {
            self::assertCron($task);
            $context = self::context($step);
            if ($context === null || $step->fields['status_sync_state'] !== self::EXECUTING
                || !self::bindingValid($step, $context[0])) {
                self::save($step, ['status_sync_state'=>self::CANCELLED,'status_sync_error'=>'stale_after_claim']);
                $tx->commit(); return self::result(self::CANCELLED,'stale_after_claim');
            }
            [$instance, $item] = $context;
            $actorRow = self::lockedRow(\User::getTable(),(int)$step->fields['status_sync_actor']);
            if ($actorRow===null || empty($actorRow['is_active']) || !empty($actorRow['is_deleted'])) {
                self::record($step,self::CANCELLED,'source_actor_inactive',(int)$item->fields['status']);
                $tx->commit(); return self::result(self::CANCELLED,'source_actor_inactive');
            }
            $target=(int)$step->fields['status_sync_target']; $actual=(int)$item->fields['status'];
            if ($actual === $target) {
                self::record($step,self::APPLIED,'already_at_target',$actual);
                $tx->commit(); return self::result(self::APPLIED,'already_at_target');
            }
            if ($actual !== (int)$step->fields['status_sync_before']) {
                self::record($step,self::FAILED,'status_changed_since_request',$actual);
                $tx->commit(); return self::result(self::FAILED,'status_changed_since_request');
            }
            // Контекст автоматического действия создан ядром GLPI; профиль и флаги не подменяются.
            // Цель системного действия закреплена снимком маршрута; штатные правила продолжают работать.
            $updated=$item->update(['id'=>$item->getID(),'status'=>$target]);
            if (!$item->getFromDB($item->getID())) { throw new \RuntimeException('Не удалось прочитать результат записи'); }
            $actual=(int)$item->fields['status'];
            $state=$updated === true && $actual === $target ? self::APPLIED : self::FAILED;
            $code=$state === self::APPLIED ? 'verified' : ($updated === true ? 'native_mismatch' : 'native_rejected');
            self::record($step,$state,$code,$actual);
            $tx->commit(); return self::result($state,$code);
        } catch (\Throwable $e) {
            // После ошибки нет новых записей. Начало исполнения сохраняется и при неизвестном исходе отката.
            try { $tx->rollback(); } catch (\Throwable $ignored) {}
            return self::result(self::UNKNOWN,'execution_unknown');
        }
    }

    public static function assertCron(CronTask $task): void
    {
        if (!Session::isCron() || Session::isInventory()
            || (string)Session::getLoginUserID(false) !== 'cron_itilflow_status_sync'
            || $task->getID()<=0) {
            throw new \RuntimeException('Не подтверждён штатный контекст автоматического действия.');
        }
        $row=self::lockedRow(CronTask::getTable(),(int)$task->getID());
        if ($row === null || $row['itemtype'] !== Cron::class
            || $row['name'] !== 'itilflow_status_sync'
            || (int)$row['mode'] !== CronTask::MODE_EXTERNAL
            || (int)$row['state'] !== CronTask::STATE_RUNNING) {
            throw new \RuntimeException('Не подтверждён штатный контекст автоматического действия.');
        }
    }

    private static function inside(): void
    {
        if (!Engine::isInside()) { throw new \LogicException('Нет внутреннего вызова движка.'); }
        TransactionScope::requireActive();
    }

    /** Порядок блокировок как у перехода: экземпляр → шаг → заявка. Права человека на родителя не требуются. */
    private static function context(Step $step): ?array
    {
        global $DB;
        self::inside();
        // До блокировок не создаём снимок обычным SELECT. Переданные IDs — только адрес поиска.
        $iid=(int)($step->fields['plugin_itilflow_instances_id']??0);
        $sid=(int)$step->getID();
        if ($iid<=0 || $sid<=0) { throw new \RuntimeException('Адрес этапа не подтверждён.'); }
        $instanceRow=self::lockedRow(Instance::getTable(),$iid);
        $stepRow=self::lockedRow(Step::getTable(),$sid);
        $i=new Instance();
        if ($instanceRow===null || $stepRow===null) { return null; }
        $i->fields=$instanceRow; $step->fields=$stepRow;
        if (!DefinitionSnapshot::enabled($i)
            || DefinitionSnapshot::load($i) === null || $i->fields['state'] !== Instance::RUNNING
            || $step->fields['state'] !== Step::RUNNING || !empty($step->fields['superseded_by_attempt_id'])
            || (int)$step->fields['plugin_itilflow_instances_id'] !== $iid
            || (int)$step->fields['attempt_id'] !== (int)$i->fields['current_attempt_id']
            || (int)$step->fields['plugin_itilflow_stages_id'] !== (int)$i->fields['plugin_itilflow_stages_id_current']) { return null; }
        $attemptRow=self::lockedRow(Attempt::getTable(),(int)$i->fields['current_attempt_id']);
        $stage=DefinitionSnapshot::stage($i,(int)$step->fields['plugin_itilflow_stages_id']);
        $type=(string)$i->fields['itemtype'];
        if ($attemptRow === null || $attemptRow['state'] !== Attempt::RUNNING
            || (int)$attemptRow['plugin_itilflow_instances_id']!==$iid
            || (int)$step->fields['attempt_id'] !== (int)$attemptRow['id']
            || (int)$step->fields['pass_number'] !== (int)$attemptRow['number']
            || $stage === null || !in_array($type,['Ticket','Change','Problem'],true)) { return null; }
        $item=new $type();
        $itemRow=self::lockedRow($item::getTable(),(int)$i->fields['items_id']);
        if ($itemRow===null) { return null; }
        $item->fields=$itemRow;
        $runningResult=$DB->doQuery('SELECT * FROM '.$DB->quoteName(Step::getTable())
            .' WHERE plugin_itilflow_instances_id='.$iid." AND state='running' ORDER BY id FOR UPDATE");
        if ($runningResult===false) { throw new \RuntimeException('Состав текущих шагов не прочитан.'); }
        $running=$DB->fetchAssoc($runningResult);
        if ((int)$DB->numrows($runningResult)!==1 || (int)($running['id']??0)!==$sid
            || !empty($item->fields['is_deleted'])
            || (int)$item->fields['entities_id']!==(int)$i->fields['entities_id']
            || in_array((int)$item->fields['status'],array_merge($item::getSolvedStatusArray(),$item::getClosedStatusArray()),true)) { return null; }
        return [$i,$item,$stage];
    }

    /** Используем именно актуальную строку блокирующего чтения, а не последующий обычный снимок. */
    private static function lockedRow(string $table,int $id): ?array
    {
        global $DB;
        if ($id<=0) { return null; }
        $result=$DB->doQuery('SELECT * FROM '.$DB->quoteName($table).' WHERE id='.$id.' FOR UPDATE');
        if ($result===false) { throw new \RuntimeException('Блокирующее чтение не выполнено.'); }
        if ((int)$DB->numrows($result)!==1) { return null; }
        return $DB->fetchAssoc($result);
    }

    private static function validTarget(CommonITILObject $item,int $target): bool
    {
        return $target>0 && array_key_exists($target,Stage::getStatusChoices($item->getType()))
            && !in_array($target,array_merge($item::getSolvedStatusArray(),$item::getClosedStatusArray()),true);
    }

    private static function fingerprint(Step $step,Instance $i,array $row): string
    {
        // Связь для проверки целостности; это не подпись и не реконструкция полномочия на штатную визу.
        return hash('sha256',json_encode(['authorized_open_step',(int)$i->getID(),$i->fields['itemtype'],
            (int)$i->fields['items_id'],(int)$i->fields['entities_id'],(int)$step->getID(),
            (int)$step->fields['attempt_id'],(int)$step->fields['pass_number'],(int)$step->fields['plugin_itilflow_stages_id'],
            $row['status_sync_definition_sha'],(int)$row['status_sync_target'],(int)$row['status_sync_before'],
            (int)$row['status_sync_actor']],JSON_THROW_ON_ERROR));
    }

    private static function bindingValid(Step $step,Instance $i): bool
    {
        $stage=DefinitionSnapshot::stage($i,(int)$step->fields['plugin_itilflow_stages_id']);
        $type=(string)$i->fields['itemtype'];
        if (!in_array($type,['Ticket','Change','Problem'],true)) { return false; }
        $item=new $type();
        return $stage!==null && (int)($step->fields['status_sync_actor']??0)>0
            && (int)($step->fields['status_sync_target']??0)===(int)$stage->fields['ticket_status']
            && self::validTarget($item,(int)$step->fields['status_sync_target'])
            && hash_equals((string)$i->fields['definition_sha256'],(string)($step->fields['status_sync_definition_sha']??''))
            && hash_equals(self::fingerprint($step,$i,$step->fields),(string)($step->fields['status_sync_fingerprint']??''));
    }

    private static function save(Step $step,array $fields): void
    {
        self::inside();
        if (!$step->update(['id'=>$step->getID()]+$fields) || !$step->getFromDB($step->getID())) { throw new \RuntimeException('Состояние синхронизации не сохранено.'); }
        foreach($fields as $key=>$value) {
            $actual=$step->fields[$key]??null;
            if($value===null ? $actual!==null : (string)$actual!==(string)$value) { throw new \RuntimeException('Состояние синхронизации не подтверждено.'); }
        }
    }

    private static function record(Step $step,string $state,string $code,?int $after): void
    {
        // Системный обработчик не меняет исходного участника и человека, запросившего повтор.
        self::save($step,['status_sync_state'=>$state,'status_sync_error'=>$code,'status_sync_after'=>$after]);
    }
    private static function result(string $state,string $code): array { return ['state'=>$state,'code'=>$code,'verified'=>$state===self::APPLIED]; }
    private static function existing(Step $step,CommonITILObject $item): array
    {
        $state=(string)$step->fields['status_sync_state'];
        if($state===self::APPLIED && (int)$item->fields['status']!==(int)$step->fields['status_sync_target']) { return self::result(self::FAILED,'status_changed_after_sync'); }
        return self::result($state,$state===self::EXECUTING ? 'execution_unknown' : 'existing_request');
    }
    public static function describe(Step $step): ?array
    {
        if(!$step->getFromDB($step->getID())) { return null; }
        $i=$step->getInstance();
        if($i===null || !DefinitionSnapshot::enabled($i) || Authorizer::instanceError($i,'read')!==null
            || $step->fields['state']!==Step::RUNNING || (int)$step->fields['attempt_id']!==(int)$i->fields['current_attempt_id']) { return null; }
        $state=(string)($step->fields['status_sync_state']??self::NONE);
        if($state===self::NONE) { return null; }
        $item=$i->getItilItem();
        if($state===self::APPLIED && $item!==null && (int)$item->fields['status']===(int)$step->fields['status_sync_target']) { return null; }
        return ['title'=>$state===self::QUEUED ? 'Обновление статуса ожидается' : 'Статус заявки требует проверки',
            'message'=>$state===self::QUEUED ? 'Этап открыт. Обновление статуса ещё не подтверждено; пока учитывается текущий статус заявки.'
                : (Session::getCurrentInterface()==='central'
                    ? 'Проверьте фактический статус. После устранения известного отказа повторите только синхронизацию; при неизвестном исходе сначала установите результат записи.'
                    : 'Ответственному нужно проверить статус заявки. Результат вашего действия по маршруту сохранён отдельно.'),
            'queued'=>$state===self::QUEUED,
            'unknown'=>in_array($state,[self::UNKNOWN,self::EXECUTING],true)];
    }
}
