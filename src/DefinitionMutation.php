<?php

namespace GlpiPlugin\Itilflow;

/** Изменение определения и его снимок сериализуются по одной строке процесса. */
trait DefinitionMutation
{
    private function definitionMutation(array $input, callable $operation)
    {
        global $DB;
        $scope = null;
        // Сколько ошибок уже было: если проверка назвала причину, общей фразы не добавляем.
        $errors = count($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? []);
        try {
            $scope = new TransactionScope();
            $ids = [];
            if ($this instanceof Process) {
                $ids[] = (int) ($input['id'] ?? $this->getID());
            } else {
                $stored = new Stage();
                if ((int) ($input['id'] ?? 0) > 0 && !$stored->getFromDB((int) $input['id'])) { throw new \RuntimeException('Этап недоступен.'); }
                $ids[] = (int) ($stored->fields['plugin_itilflow_processes_id'] ?? 0);
                $ids[] = (int) ($input['plugin_itilflow_processes_id']
                    ?? $stored->fields['plugin_itilflow_processes_id'] ?? 0);
            }
            $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
            sort($ids);
            foreach ($ids as $id) {
                $result = $DB->doQuery('SELECT id FROM ' . $DB->quoteName(Process::getTable()) . ' WHERE id=' . $id . ' FOR UPDATE');
                if ((int) $DB->numrows($result) !== 1) { throw new \RuntimeException('Определение недоступно.'); }
            }
            if ((int) ($input['id'] ?? 0) > 0 && !$this->getFromDB((int) $input['id'])) {
                throw new \RuntimeException('Определение недоступно.');
            }
            $value = $operation();
            if (!$value) { $scope->rollback(); return $value; }
            // Первая половина перестановки стрелками (Stage::move): на миг у двух этапов один номер,
            // версию и полноту маршрута проверит второе сохранение пары по итоговому порядку.
            if ($this instanceof Stage && !Stage::versionDeferred((int) ($input['id'] ?? 0))) {
                foreach ($ids as $id) {
                    $process = new Process();
                    if (!$process->getFromDB($id) || !$process->update(['id' => $id])) {
                        throw new \RuntimeException('Версия определения не сохранена.');
                    }
                }
            }
            $scope->commit();
            return $value;
        } catch (\Throwable $e) {
            if ($scope !== null) { try { $scope->rollback(); } catch (\Throwable $ignored) {} }
            TransactionScope::assertHealthy();
            if (count($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? []) <= $errors) {
                // Причины нет на экране — она в журнале: ошибку SQL GLPI 11 бросает, но сам не пишет.
                \Toolbox::logInFile('itilflow', sprintf("Изменение определения не подтверждено: %s: %s\n",
                    get_class($e), $e->getMessage()), true, false);
                \Session::addMessageAfterRedirect('Изменения не сохранены. Обновите страницу и повторите.', false, ERROR);
            }
            return false;
        }
    }

    public function add(array $input, $options = [], $history = true)
    {
        return $this->definitionMutation($input, fn() => parent::add($input, $options, $history));
    }

    public function update(array $input, $history = true, $options = [])
    {
        return $this->definitionMutation($input, fn() => parent::update($input, $history, $options));
    }

    public function delete(array $input, $force = false, $history = true)
    {
        return $this->definitionMutation($input, fn() => parent::delete($input, $force, $history));
    }
}
