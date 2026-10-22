<?php
namespace GlpiPlugin\Itilflow;

/** Решение отдела: ревизии с автором; не заменяет голос штатного согласования. */
final class Decision
{
    public static function items(Step $step): array
    {
        if ($step->getInstance() === null || DefinitionSnapshot::schemaOf($step->getInstance()) < 3) { return []; }
        $raw = $step->getStage()?->fields['decision_items'] ?? null;
        $items = BranchConfiguration::decisionItems($raw);
        if ($items === null) { throw new \RuntimeException('Настройка решения повреждена.'); }
        return $items;
    }
    public static function read(Step $step): array
    {
        $raw = (string) ($step->fields['decision'] ?? '');
        if ($raw === '') { return ['version' => 1, 'revision' => 0, 'answers' => [], 'history' => []]; }
        $value = json_decode($raw, true);
        if (is_array($value) && ($value['version'] ?? null) === 1 && is_int($value['revision'] ?? null) && $value['revision'] >= 0
            && is_array($value['answers'] ?? null) && is_array($value['history'] ?? null)
            && array_is_list($value['history'])) { return $value; }
        throw new \RuntimeException('История решения повреждена.');
    }
    public static function normalize(array $items, array $answers): ?array
    {
        if (BranchConfiguration::decisionItems($items) === null) { return null; }
        $keys = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['key'] ?? null) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $item['key'])
                || isset($keys[$item['key']]) || !is_string($item['label'] ?? null) || trim($item['label']) === '') { return null; }
            $keys[$item['key']] = true;
        }
        if (array_diff_key($answers, $keys) || count($answers) !== count($keys)) { return null; }
        foreach ($answers as $v) { if (!is_bool($v)) { return null; } }
        ksort($answers); return $answers;
    }
    public static function ready(Step $step): bool
    {
        $items = self::items($step);
        return $items === [] || self::normalize($items, self::read($step)['answers']) !== null;
    }
    public static function revisionMatches(Step $step, ?int $expected): bool
    {
        return self::items($step) === [] || ($expected !== null && self::read($step)['revision'] === $expected && self::ready($step));
    }
    public static function canWrite(Step $step): bool
    {
        if (self::items($step) === [] || StructuredAccess::context($step) === null || Outcome::forStep($step->getID()) !== null
            || ($_SESSION['glpiactiveprofile']['interface'] ?? '') !== 'central') { return false; }
        $parent = $step->getInstance()?->getItilItem();
        if ($parent === null || Authorizer::itemError($parent, READ) !== null) { return false; }
        if (Authorizer::isAdministrator()) { return true; }
        $stage = $step->getStage();
        if ($step->fields['execution_mode'] === Stage::MODE_INLINE) { return Authorizer::stepError($step, 'complete') === null; }
        if ($step->fields['execution_mode'] === Stage::MODE_APPROVAL || ($stage->fields['department_result_source'] ?? '') === 'native_validation') {
            foreach (StructuredAccess::roster($step) ?? [] as $row) {
                $type = $row['_type']; $v = new $type(); $v->fields = $row;
                if (ValidationAuthority::canAnswer($v) && $v->can($v->getID(), UPDATE)) { return true; }
            }
            return false;
        }
        return StructuredAccess::answerError($step) === null;
    }
    public static function next(array $old, array $items, array $answers, int $expected, int $actor, string $at): ?array
    {
        $normal = self::normalize($items, $answers);
        if ($normal === null || $actor <= 0 || ($old['revision'] ?? null) !== $expected || !is_array($old['history'] ?? null)) { return null; }
        $revision = $expected + 1;
        $history = $old['history']; $history[] = ['revision' => $revision, 'answers' => $normal, 'actor' => $actor, 'at' => $at];
        return ['version' => 1, 'revision' => $revision, 'answers' => $normal, 'history' => $history];
    }
    /** HTML-формы передают только строки 0/1; пустой ответ не становится false. */
    public static function fromPost(mixed $input): ?array
    {
        if (!is_array($input)) { return null; }
        $out = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || !in_array($value, ['0','1'], true)) { return null; }
            $out[$key] = $value === '1';
        }
        return $out;
    }
    public static function validateItems(array $items): bool
    {
        return BranchConfiguration::decisionItems($items) !== null;
    }
    public static function revisionFromPost(mixed $input): ?int
    {
        return is_string($input) && preg_match('/^(0|[1-9][0-9]*)$/D', $input)
            && (string) (int) $input === $input ? (int) $input : null;
    }
}
