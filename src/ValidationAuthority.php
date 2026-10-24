<?php

namespace GlpiPlugin\Itilflow;

/** Дополняет native canAnswer текущими адресатами; не расширяет native полномочие. */
final class ValidationAuthority
{
    public static function canAnswer(\CommonITILValidation $validation): bool
    {
        $uid = (int) \Session::getLoginUserID();
        if ($uid <= 0) { return false; }
        $scope = TransactionScope::hasActive() ? null : new TransactionScope();
        try {
            if (!CurrentRead::load($validation, (int) $validation->getID())) {
                if ($scope !== null) { $scope->commit(); }
                return false;
            }
            $current = self::addressedTo($uid, $validation->fields);
            // Native canAnswer делает plain SELECT. Его false не повышается до true;
            // его stale true не принимается без текущей адресности выше.
            $allowed = $current && $validation->canAnswer();
            if ($scope !== null) { $scope->commit(); }
            return $allowed;
        } catch (\Throwable $e) {
            if ($scope !== null) { try { $scope->rollback(); } catch (\Throwable $ignored) {} }
            TransactionScope::assertHealthy();
            throw $e;
        }
    }

    /**
     * Запрос сейчас адресован пользователю $uid: он адресат, участник группы-адресата или действующий
     * заместитель адресата (или участника группы). Точные источники
     * CommonITILValidation::getTargetCriteriaForUser GLPI 11.0.8, без сессии: этим же проверяется, что
     * ответ, который догоняет сверка «Сроки этапов», дал настоящий согласующий (1.13.2).
     */
    public static function addressedTo(int $uid, array $validation): bool
    {
        global $DB;
        if ($uid <= 0) { return false; }
        $users = [$uid];
        foreach (CurrentRead::find(new \ValidatorSubstitute(), ['users_id_substitute' => $uid]) as $binding) {
            // Строка отсутствующего согласующего — общей блокировкой, как и другие справочники (1.13.2): два заместителя одного человека отвечают одновременно и не ждут друг друга.
            $query = $DB->doQuery('SELECT * FROM ' . $DB->quoteName(\User::getTable())
                . ' WHERE id=' . (int) $binding['users_id']
                . ' AND (substitution_start_date IS NULL OR substitution_start_date<=NOW())'
                . ' AND (substitution_end_date IS NULL OR substitution_end_date>=NOW())' . CurrentRead::lockClause(\User::class));
            $user = $DB->fetchAssoc($query);
            if ($user) { $users[] = (int) $user['id']; }
        }
        $users = array_values(array_unique($users));
        $target = (int) ($validation['items_id_target'] ?? 0);
        $kind = $validation['itemtype_target'] ?? '';
        if ($kind === \User::class) { return in_array($target, $users, true); }
        return $kind === \Group::class && $target > 0
            && count(CurrentRead::find(new \Group_User(), ['groups_id' => $target, 'users_id' => $users])) > 0;
    }
}
