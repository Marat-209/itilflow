<?php

namespace GlpiPlugin\Itilflow;

/** Подтверждение относится к показанному составу и ответам, а не просто к номеру запроса. */
final class ManualAdoptionConfirmation
{
    public static function digest(array $rows, array $native): string
    {
        $tuple = [];
        foreach ($rows as $row) {
            $entry = [];
            foreach (['id', 'tickets_id', 'entities_id', 'itils_validationsteps_id', 'items_id_target', 'status', 'users_id_validate'] as $key) {
                $entry[$key] = (int) ($row[$key] ?? 0);
            }
            foreach (['itemtype_target', 'validation_date', 'comment_validation'] as $key) {
                $entry[$key] = (string) ($row[$key] ?? '');
            }
            $tuple[] = $entry;
        }
        usort($tuple, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return hash('sha256', json_encode([
            'rows' => $tuple, 'itemtype' => (string) ($native['itemtype'] ?? ''),
            'items_id' => (int) ($native['items_id'] ?? 0), 'id' => (int) ($native['id'] ?? 0),
            'validationsteps_id' => (int) ($native['validationsteps_id'] ?? 0),
            'percent' => (int) ($native['minimal_required_validation_percent'] ?? -1),
        ], JSON_THROW_ON_ERROR));
    }

    public static function accepts(array $rows, array $native, ?string $confirmation): bool
    {
        $answered = count(array_filter($rows, static fn(array $r): bool => (int) ($r['status'] ?? 0) !== 2)) > 0;
        if ($confirmation === null) { return !$answered; }
        return preg_match('/\A[a-f0-9]{64}\z/D', $confirmation) === 1
            && hash_equals(self::digest($rows, $native), $confirmation);
    }

    public static function fromInput(array $input): ?string
    {
        $value = $input['answered_confirmation'] ?? null;
        return ($input['confirm_answered'] ?? null) === '1' && is_string($value)
            && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1 ? $value : null;
    }

    public static function answerDate(array $row): string
    {
        $date = (string) ($row['validation_date'] ?? '');
        return preg_match('/\A(\d{4})-(\d{2})-(\d{2})(?: |$)/', $date, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $m[3] . '.' . $m[2] : 'дата не указана';
    }
}
