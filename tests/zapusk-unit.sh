#!/bin/sh
# «Чистые» проверки плагина: без базы и без сети, в одноразовом контейнере образа GLPI.
# Рабочая копия монтируется только для чтения туда, где GLPI ищет плагин.
# Аргументы — имена проверок из tests/unit без .php; без аргументов — все.
# Код выхода 0 — все проверки прошли.
#
#   sh tests/zapusk-unit.sh
#   sh tests/zapusk-unit.sh srok vidimost
#
# Образ: GLPI_IMAGE, по умолчанию glpi/glpi:11.0.8.

# В Git Bash под Windows docker нужен путь вида C:/…, его даёт pwd -W.
KOREN=$(cd "$(dirname "$0")/.." && (pwd -W 2>/dev/null || pwd))
OBRAZ=${GLPI_IMAGE:-glpi/glpi:11.0.8}
V=/var/www/glpi/plugins/itilflow

if [ "$#" -gt 0 ]; then
    SPISOK="$*"
else
    SPISOK=$(cd "$(dirname "$0")/unit" && ls *.php | grep -v '^_' | sed 's/[.]php$//')
fi

PROVAL=0
# Синтаксис всех PHP-файлов плагина.
if ! MSYS_NO_PATHCONV=1 docker run --rm --network none --entrypoint sh -v "$KOREN:$V:ro" "$OBRAZ" \
        -c "cd $V && for f in \$(find . -name '*.php'); do php -l \$f >/dev/null || exit 1; done"; then
    echo "НЕ ok синтаксис PHP"
    PROVAL=1
else
    echo "ok синтаксис PHP"
fi
for T in $SPISOK; do
    echo "== $T"
    if ! MSYS_NO_PATHCONV=1 docker run --rm --network none --entrypoint php -v "$KOREN:$V:ro" "$OBRAZ" "$V/tests/unit/$T.php"; then
        PROVAL=1
    fi
done
[ "$PROVAL" -eq 0 ] && echo "ИТОГ: всё пройдено" || echo "ИТОГ: есть провалы"
exit "$PROVAL"
