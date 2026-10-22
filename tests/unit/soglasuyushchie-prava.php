<?php

/** Полномочие адресата и срок замещения: чистые контрпримеры, без сессии и БД. */
require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Authorizer;

$now = '2026-01-10 12:00:00';
$user = ['id' => 101, 'is_active' => 1, 'is_deleted' => 0, 'begin_date' => null, 'end_date' => null];
$grants = [['profiles_id' => 4, 'entities_id' => 7, 'is_recursive' => 0]];
$bits = TicketValidation::getValidateRights();
$rights = [4 => $bits[0]];
$reason = static fn(array $u, array $g = [], array $r = []) => Authorizer::approvalAccountReason(
    $u, $g ?: $grants, $r ?: $rights, 7, [0, 2], $bits, $now);

ok($reason($user) === 'available', 'право согласования в организации адресата');
ok($reason([]) === 'missing', 'неизвестный адресат недоступен');
ok($reason(array_replace($user, ['is_deleted' => 1])) === 'deleted', 'удалённый адресат недоступен');
ok($reason(array_replace($user, ['is_active' => 0])) === 'inactive', 'неактивный адресат выделен для пометки');
ok($reason(array_replace($user, ['begin_date' => '2026-01-11 00:00:00'])) === 'outside_period', 'будущая активация не считается доступной');
ok($reason(array_replace($user, ['end_date' => '2026-01-09 23:59:59'])) === 'outside_period', 'истёкшая учётная запись недоступна');
ok($reason(array_replace($user, ['begin_date' => $now, 'end_date' => $now])) === 'available', 'границы срока учётной записи включены');
ok($reason($user, [['profiles_id' => 4, 'entities_id' => 8, 'is_recursive' => 0]]) === 'no_entity', 'профиль чужой организации не подходит');
ok($reason($user, [['profiles_id' => 4, 'entities_id' => 2, 'is_recursive' => 1]]) === 'available', 'рекурсивный профиль предка подходит');
ok($reason($user, [['profiles_id' => 4, 'entities_id' => 2, 'is_recursive' => 0]]) === 'no_entity', 'нерекурсивный профиль предка не подходит');
ok($reason($user, $grants, [4 => 0]) === 'no_validation_right', 'сам профиль не даёт право согласования');
$mixed = [['profiles_id' => 4, 'entities_id' => 8, 'is_recursive' => 0], ['profiles_id' => 5, 'entities_id' => 7, 'is_recursive' => 0]];
ok($reason($user, $mixed, [4 => $bits[0], 5 => 0]) === 'no_validation_right', 'право из чужой организации не переносится на местный профиль');
ok($reason($user, $mixed, [4 => 0, 5 => $bits[0]]) === 'available', 'достаточно одного подходящего местного профиля');
ok(Authorizer::approvalAccountReason($user, [], $rights, 7, [0, 2], $bits, $now) === 'no_entity', 'пустой состав профилей не даёт доступ');

$window = ['substitution_start_date' => null, 'substitution_end_date' => null];
ok(Authorizer::substitutionWindow($window, $now), 'открытое окно замещения');
ok(Authorizer::substitutionWindow(array_replace($window, ['substitution_start_date' => $now, 'substitution_end_date' => $now]), $now), 'native границы замещения включительны');
ok(!Authorizer::substitutionWindow(array_replace($window, ['substitution_start_date' => '2026-01-11 00:00:00']), $now), 'будущий заместитель не считается действующим');
ok(!Authorizer::substitutionWindow(array_replace($window, ['substitution_end_date' => '2026-01-09 23:59:59']), $now), 'истёкший заместитель не считается действующим');
ok(!Authorizer::substitutionWindow(['substitution_start_date' => '2026-01-11 00:00:00', 'substitution_end_date' => '2026-01-09 00:00:00'], $now), 'перевёрнутое окно не расширяет native право');
$nativeBits = array_merge(TicketValidation::getCreateRights(), TicketValidation::getValidateRights());
$createOnly = TicketValidation::getCreateRights()[0];
ok(Authorizer::approvalAccountReason($user, $grants, [4 => $createOnly], 7, [0, 2], $nativeBits, $now) === 'available', 'назначенный адресат с правом создания проходит native canUpdate');
ok(Authorizer::approvalAccountReason($user, [], [4 => $createOnly], 7, [0, 2], $nativeBits, $now) === 'no_entity', 'право создания не отменяет организацию');

// Заявка в корзине. Маршрут можно посмотреть в центральном интерфейсе, прервать — только
// администратору маршрутов; остальные действия маршрута и все действия этапа закрыты.
$korzina = static fn(string $action, bool $central = true, bool $admin = true): ?string
    => Authorizer::trashError($action, true, $central, $admin);
$vse = ['read', 'abort', 'halt', 'resume', 'reopen', 'withdraw', ''];
ok(array_filter(array_map(static fn($a) => Authorizer::trashError($a, false, false, false), $vse)) === [],
    'заявка не в корзине — корзина ничего не запрещает');
ok($korzina('read') === null && $korzina('read', true, false) === null, 'маршрут заявки в корзине можно посмотреть');
ok($korzina('read', false) !== null, 'в самообслуживании маршрут заявки в корзине не показывается');
ok($korzina('abort') === null, 'администратор маршрутов может прервать маршрут заявки в корзине');
ok($korzina('abort', true, false) !== null && $korzina('abort', false) !== null,
    'прервать маршрут заявки в корзине не может никто, кроме администратора маршрутов');
$zakryto = array_values(array_filter(['halt', 'resume', 'reopen', 'withdraw', ''], static fn($a) => $korzina($a) === null));
ok($zakryto === [], 'остановить, продолжить, отозвать и действия этапа у заявки в корзине недоступны', implode(', ', $zakryto));
$tekst = (string) $korzina('halt');
ok(str_contains($tekst, 'корзин') && preg_match('/администратор(у)? маршрутов/u', $tekst) === 1,
    'отказ говорит, что заявка в корзине и кто может прервать маршрут', $tekst);
itog();
