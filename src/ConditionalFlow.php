<?php
namespace GlpiPlugin\Itilflow;

/** Команды схемы 3; старые снимки не проходят через эти переходы. */
trait ConditionalFlow
{
    /** Пропуск не подменяет исход и не может скрывать уже созданную работу. */
    private static function notNeededIsValid(Instance $instance, array $row): bool
    {
        $note = json_decode((string) ($row['condition_note'] ?? ''), true);
        return DefinitionSnapshot::schemaOf($instance) >= 3 && empty($row['artifact_items_id'])
            && empty($row['approver_items_ids']) && empty($row['approval_expected_count'])
            && Outcome::forStep((int) $row['id']) === null && is_array($note)
            && (($note['truth'] ?? '') === 'no' || (($note['truth'] ?? '') === 'unknown'
                && ($note['manual']['choice'] ?? '') === 'skip' && !empty($note['manual']['actor'])
                && trim((string) ($note['manual']['reason'] ?? '')) !== ''));
    }
    private static function stageCondition(Step $step): array
    {
        $raw = $step->getStage()?->fields['run_if'] ?? null;
        if ($raw === null || $raw === '') { return []; }
        $rule = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($rule) ? $rule : ['version' => 0];
    }
    public static function conditionEvaluation(Step $step): array
    {
        $context = self::conditionContext($step);
        $result = Condition::evaluate(self::stageCondition($step), $context);
        if (!empty($context['form_facts']['recovery_required']) && !self::recoveryAcknowledged($step, $context['form_facts'])) {
            $result = ['truth' => 'unknown', 'reasons' => ['callback_failed'],
                'input_revision' => hash('sha256', Condition::encode([$result['input_revision'], $context['form_facts']]))];
        }
        return $result;
    }
    public static function recoveryDigest(array $facts): string
    {
        return hash('sha256', Condition::encode($facts));
    }
    private static function recoveryAcknowledged(Step $step, array $facts): bool
    {
        $digest = self::recoveryDigest($facts);
        foreach ($step->getInstance()?->getAttemptSteps() ?? [] as $row) {
            if ((int) $row['attempt_id'] !== (int) $step->fields['attempt_id'] || (int) $row['ranking'] >= (int) $step->fields['ranking']) { continue; }
            $note = json_decode((string) ($row['condition_note'] ?? ''), true);
            if (is_array($note) && ($note['manual']['recovery_digest'] ?? '') === $digest
                && !empty($note['manual']['actor']) && trim((string) ($note['manual']['reason'] ?? '')) !== ''
                && in_array($note['manual']['choice'] ?? '', ['run','skip'], true)) { return true; }
        }
        return false;
    }
    public static function conditionContext(Step $step): array
    {
        $instance = $step->getInstance(); $item = $instance?->getItilItem();
        $attempt = $instance ? Attempt::current($instance) : null; $stages = [];
        if ($instance === null || $item === null || $attempt === null) { return []; }
        foreach ($instance->getSteps() as $row) {
            if ((int) $row['ranking'] >= (int) $step->fields['ranking']) { continue; }
            $current = (int) $row['attempt_id'] === (int) $attempt->getID();
            $retained = !$current && (int) $row['ranking'] < (int) ($attempt->fields['preserved_before_rank'] ?? 0)
                && empty($row['superseded_by_attempt_id']) && in_array($row['state'], [Step::DONE, Step::NOT_NEEDED], true);
            if (!$current && !$retained) { continue; }
            $prior = new Step(); $prior->fields = $row; $decision = Decision::read($prior);
            $stages[(int) $row['plugin_itilflow_stages_id']] = ['state' => $row['state'], 'attempt_id' => (int) $row['attempt_id'],
                'retained' => $retained, 'decision_revision' => $decision['revision'], 'answers' => $decision['answers']];
        }
        return ['definition' => $instance->fields['definition_sha256'], 'attempt_id' => (int) $attempt->getID(),
            'stages' => $stages, 'ticket' => ['category_id' => (int) ($item->fields['itilcategories_id'] ?? 0), 'type' => (int) ($item->fields['type'] ?? 0)],
            'form_facts' => FormFacts::load($instance)];
    }
    /** Возвращает true только когда можно создавать объекты этапа. */
    private static function conditionBeforeOpen(Instance $instance, Step $step): bool
    {
        if (DefinitionSnapshot::schemaOf($instance) < 3) { return true; }
        $rule = self::stageCondition($step); $context = self::conditionContext($step);
        $hasForm = count(array_filter($rule['clauses'] ?? [], static fn($c): bool => is_array($c) && ($c['source'] ?? '') === 'form_answer')) > 0;
        if ($hasForm && ($context['form_facts']['status'] ?? '') === 'pending') {
            self::mustUpdate($step, ['id' => $step->getID(), 'condition_note' => Condition::encode(['truth' => 'unknown', 'reasons' => ['awaiting_form_callback'], 'input_revision' => '', 'evaluated_at' => self::now()])]);
            return false;
        }
        $evaluation = self::conditionEvaluation($step);
        $old = json_decode((string) ($step->fields['condition_note'] ?? ''), true);
        $manual = $old['manual'] ?? null;
        if (is_array($manual) && ($old['input_revision'] ?? '') === $evaluation['input_revision'] && ($manual['choice'] ?? '') === 'run') { return true; }
        self::mustUpdate($step, ['id' => $step->getID(), 'condition_note' => Condition::encode($evaluation + ['evaluated_at' => self::now()])]);
        if ($evaluation['truth'] === 'yes' || ($evaluation['truth'] === 'unknown' && !in_array('callback_failed', $evaluation['reasons'], true) && ($step->getStage()?->fields['unknown_policy'] ?? 'run') === 'run')) { return true; }
        if ($evaluation['truth'] === 'no') {
            self::mustUpdate($step, ['id' => $step->getID(), 'state' => Step::NOT_NEEDED, 'date_end' => self::now()]);
            self::advance($instance);
        } elseif (($old['input_revision'] ?? null) !== $evaluation['input_revision']) {
            if (!self::auditAction($instance, $step, 'condition_wait', false, implode(', ', $evaluation['reasons']))) {
                throw new \RuntimeException('Ожидание решения не записано в журнал.');
            }
        }
        return false;
    }
    public static function resumeFormFacts(Instance $instance): void
    {
        TransactionScope::requireActive();
        if (CurrentRead::load($instance, (int) $instance->getID()) && DefinitionSnapshot::schemaOf($instance) >= 3
            && $instance->fields['state'] === Instance::RUNNING) { self::advance($instance); }
    }
    public static function conditionCanChoose(Step $step, bool $skip = false): bool
    {
        $instance = $step->getInstance(); $item = $instance?->getItilItem();
        if ($instance === null || $item === null || DefinitionSnapshot::schemaOf($instance) < 3
            || $instance->fields['state'] !== Instance::RUNNING || $step->fields['state'] !== Step::PENDING
            || ($_SESSION['glpiactiveprofile']['interface'] ?? '') !== 'central' || Authorizer::itemError($item, UPDATE) !== null
            || $instance->getCurrentStep() !== null) { return false; }
        if (($step->getStage()?->fields['unknown_policy'] ?? '') !== 'ask' && empty(FormFacts::load($instance)['recovery_required'])) { return false; }
        $attempt = Attempt::current($instance);
        if ($attempt === null || $attempt->fields['state'] !== Attempt::RUNNING || (int) $step->fields['attempt_id'] !== (int) $attempt->getID()) { return false; }
        foreach ($instance->getAttemptSteps() as $row) {
            if ($row['state'] === Step::PENDING) { if ((int) $row['id'] !== (int) $step->getID()) { return false; } break; }
        }
        if ($skip && ($step->fields['execution_mode'] === Stage::MODE_APPROVAL
            || ($step->getStage()?->fields['department_result_source'] ?? '') === 'native_validation')) { return Authorizer::isAdministrator(); }
        return Authorizer::isAdministrator() || Authorizer::isAssignee($item);
    }
    public static function chooseCondition(Step $step, string $choice, string $reason, string $revision, string $key, int $attempt): array
    {
        $instance = $step->getInstance(); if ($instance === null) { return ['status' => 'rejected']; }
        return self::structuredCommand($instance, 'choose_condition', $key, $attempt, [$step->getID(),$choice,$reason,$revision],
            static function () use ($instance,$step,$choice,$reason,$revision): array {
                if (!CurrentRead::load($step, (int) $step->getID()) || !in_array($choice,['run','skip'],true) || trim(strip_tags($reason)) === ''
                    || !self::conditionCanChoose($step,$choice==='skip')) { return self::structuredResult('rejected',$instance); }
                $context = self::conditionContext($step);
                $rule = self::stageCondition($step);
                $hasForm = count(array_filter($rule['clauses'] ?? [], static fn($c): bool => is_array($c) && ($c['source'] ?? '') === 'form_answer')) > 0;
                if ($hasForm && ($context['form_facts']['status'] ?? '') === 'pending') { return self::structuredResult('rejected',$instance); }
                $evaluation = self::conditionEvaluation($step);
                if ($evaluation['truth'] !== 'unknown' || !hash_equals($evaluation['input_revision'],$revision)) { return self::structuredResult('rejected',$instance); }
                $evaluation['manual'] = ['choice'=>$choice,'reason'=>trim(strip_tags($reason)),'actor'=>(int)\Session::getLoginUserID(),'at'=>self::now()];
                if (in_array('callback_failed', $evaluation['reasons'], true)) { $evaluation['manual']['recovery_digest'] = self::recoveryDigest($context['form_facts']); }
                self::mustUpdate($step,['id'=>$step->getID(),'condition_note'=>Condition::encode($evaluation)]);
                if ($choice==='skip') { self::mustUpdate($step,['id'=>$step->getID(),'state'=>Step::NOT_NEEDED,'date_end'=>self::now()]); }
                self::advance($instance);
                return self::structuredResult('applied',$instance,$step);
            });
    }
    private static function prepareDecisionForOutcome(Step $step, ?int $revision, ?array $answers, bool $required = true): bool
    {
        if (Decision::items($step) === []) { return true; }
        $old = Decision::read($step);
        if ($revision === null || $old['revision'] !== $revision) { return false; }
        if ($answers !== null) {
            if (!Decision::canWrite($step)) { return false; }
            $next = Decision::next($old, Decision::items($step), $answers, $revision, (int) \Session::getLoginUserID(), self::now());
            if ($next === null) { return false; }
            self::mustUpdate($step, ['id' => $step->getID(), 'decision' => Condition::encode($next)]);
        }
        return !$required || Decision::ready($step);
    }
    public static function saveDecision(Step $step, array $answers, int $revision, string $key, int $attempt, int $approveId = 0): array
    {
        $instance=$step->getInstance(); if($instance===null){return ['status'=>'rejected'];}
        return self::structuredCommand($instance,'save_decision',$key,$attempt,[$step->getID(),$answers,$revision,$approveId],
            static function() use($instance,$step,$answers,$revision,$approveId): array {
                if(!CurrentRead::load($step,(int)$step->getID()) || !Decision::canWrite($step)){return self::structuredResult('rejected',$instance);}
                $next=Decision::next(Decision::read($step),Decision::items($step),$answers,$revision,(int)\Session::getLoginUserID(),self::now());
                if($next===null){return self::structuredResult('rejected',$instance);}
                if($approveId>0){
                    $rows=StructuredAccess::roster($step,true); $row=$rows[$approveId]??null;
                    if($row===null){return self::structuredResult('rejected',$instance);}
                    $v=new $row['_type'](); $v->fields=$row;
                    if(!ValidationAuthority::canAnswer($v) || !$v->can($approveId,UPDATE)){return self::structuredResult('rejected',$instance);}
                }
                self::mustUpdate($step,['id'=>$step->getID(),'decision'=>Condition::encode($next)]);
                if($approveId>0 && !$v->update(['id'=>$approveId,'status'=>\CommonITILValidation::ACCEPTED,'users_id_validate'=>(int)\Session::getLoginUserID()])){
                    throw new \RuntimeException('Ответ согласующего не сохранён.');
                }
                if(CurrentRead::load($step,(int)$step->getID()) && $step->fields['state']===Step::RUNNING){
                    $aggregate=StructuredAccess::nativeAggregate($step);
                    if(in_array($aggregate,[Outcome::COMPLETED,Outcome::REFUSED],true)){
                        foreach(StructuredAccess::roster($step,true)??[] as $row){
                            if((int)$row['status']===($aggregate===Outcome::COMPLETED?3:4)){
                                $v=new $row['_type'](); $v->fields=$row;
                                if(!self::transferStructuredVote($instance,$step,$v)){throw new \RuntimeException('Итог согласования не перенесён.');}
                                break;
                            }
                        }
                    }
                }
                return self::structuredResult('applied',$instance,$step);
            });
    }
}
