<?php

namespace GlpiPlugin\Itilflow;

/** Дополняет native canAnswer текущими адресатами; не расширяет native полномочие. */
final class ValidationAuthority
{
    public static function canAnswer(\CommonITILValidation $validation): bool
    {
        global $DB;
        $uid = (int) \Session::getLoginUserID();
        if ($uid <= 0) { return false; }
        $scope = TransactionScope::hasActive() ? null : new TransactionScope();
        try {
            if (!CurrentRead::load($validation, (int) $validation->getID())) {
                if ($scope !== null) { $scope->commit(); }
                return false;
            }
            $users = [$uid];
            // Точные источники CommonITILValidation::getTargetCriteriaForUser GLPI 11.0.8.
            foreach (CurrentRead::find(new \ValidatorSubstitute(), ['users_id_substitute' => $uid]) as $binding) {
                $query = $DB->doQuery('SELECT * FROM ' . $DB->quoteName(\User::getTable())
                    . ' WHERE id=' . (int) $binding['users_id']
                    . ' AND (substitution_start_date IS NULL OR substitution_start_date<=NOW())'
                    . ' AND (substitution_end_date IS NULL OR substitution_end_date>=NOW()) FOR UPDATE');
                $user = $DB->fetchAssoc($query);
                if ($user) { $users[] = (int) $user['id']; }
            }
            $users = array_values(array_unique($users));
            $target = (int) ($validation->fields['items_id_target'] ?? 0);
            $kind = $validation->fields['itemtype_target'] ?? '';
            $current = $kind === \User::class && in_array($target, $users, true);
            if ($kind === \Group::class && $target > 0) {
                $current = count(CurrentRead::find(new \Group_User(), ['groups_id' => $target, 'users_id' => $users])) > 0;
            }
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
}
