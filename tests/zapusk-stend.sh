#!/bin/sh
# Проверки плагина с базой GLPI: копия рабочей ветки кладётся во временный
# каталог контейнера GLPI, каждая проверка идёт от веб-пользователя в одной
# транзакции и откатывается. Установленный плагин и файлы GLPI не меняются.
# Аргументы — имена проверок из tests/stend без .php; без аргументов — все.
#
#   sh tests/zapusk-stend.sh
#   sh tests/zapusk-stend.sh formy krug
#
# Контейнер: GLPI_CONTAINER, по умолчанию glpi.

KOREN=$(cd "$(dirname "$0")/.." && (pwd -W 2>/dev/null || pwd))
KONTEJNER=${GLPI_CONTAINER:-glpi}
KOPIYA=/tmp/itilflow-proverka

MSYS_NO_PATHCONV=1 docker exec -u root "$KONTEJNER" rm -rf "$KOPIYA"
MSYS_NO_PATHCONV=1 docker exec -u root "$KONTEJNER" mkdir -p "$KOPIYA"
for CHAST in src templates front tests hook.php setup.php; do
    MSYS_NO_PATHCONV=1 docker cp "$KOREN/$CHAST" "$KONTEJNER:$KOPIYA/" >/dev/null
done
MSYS_NO_PATHCONV=1 docker exec -u root "$KONTEJNER" chmod -R a+rX "$KOPIYA"

if [ "$#" -gt 0 ]; then
    SPISOK="$*"
else
    SPISOK=$(cd "$(dirname "$0")/stend" && ls *.php | grep -v '^_' | sed 's/[.]php$//')
fi

PROVAL=0
for T in $SPISOK; do
    echo "== $T"
    if ! MSYS_NO_PATHCONV=1 docker exec -u www-data "$KONTEJNER" php "$KOPIYA/tests/stend/$T.php"; then
        PROVAL=1
    fi
done
[ "$PROVAL" -eq 0 ] && echo "ИТОГ: всё пройдено" || echo "ИТОГ: есть провалы"
exit "$PROVAL"
