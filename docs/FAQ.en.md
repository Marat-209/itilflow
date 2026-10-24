# FAQ

English · **[Русский](FAQ.md)**

The interface is Russian only; labels are quoted in Russian with the English
meaning in brackets ([glossary](REFERENCE.en.md#1-words)).

## Start

**A route does not start — where to begin?**
Open the route: the readiness line at the top names what is missing, with
a link to the stage.

**The form created a ticket, but there is no route.**
Two routes fit, none, or the answer to a question in the condition is
unknown. The readiness line of the form's routes shows the reason and the
number of such tickets. Fix “Запускать, если” (Start if); on this ticket the
route can be started by hand (“Маршрут этапов” tab → “Запустить маршрут” —
Start route).

**The business rule action shows an empty route list.**
A rule sees routes of its own and parent entities only if they have child
entities = Yes. Turn it on or create the route in the rule's entity.

**The rule fired, but the ticket has no route.**
Check: the route is enabled; “Для чего маршрут” (Route for) matches the
ticket type; the entity fits; a rule higher up did not stop processing. If
the ticket's form has a route with “Запускать, если”, the rule does not start
the route.

**An unknown answer did not choose the “no” branch.**
By design: no answer is not “no”. The stage's “Если ответа нет” (If no answer)
decides what to do.

## Stages

**The route stopped as soon as it reached a stage.**
Most often the stage's group is not visible in the entity of the ticket or
child ticket. Another cause — a deadline or SLA after 18 January 2038. Fix the
stage and choose “Возобновить маршрут” (Resume route) in the tab's “…” menu.

**A group is missing from the stage's list.**
In the group form, Visible in a ticket → Assigned to is not Yes.

**A field is missing from the stage form.**
The form shows the fields of the chosen “Как выполняется” (How it runs). Rare
ones are in the collapsed “Дополнительно” (More): order, ticket status, SLA,
“Нужно одобрений, %” (Approvals needed, %), “Этап можно пропустить” (Stage can
be skipped).

**No “Завершить этап” (Complete stage) on an approval or a child ticket.**
By design: an approval is closed by the approver's answer, a child ticket by
“Отметить результат” (Record result). Without an answer — only “Закрыть
принудительно…” (Force close) with a reason.

**A child ticket cannot be solved by hand.**
Open its “Результат отдела” (Department result) tab → “Отметить результат”.
It then solves itself.

**An approval request cannot be deleted.**
While the stage runs, its request cannot be deleted. If the approval is not
needed, choose “Закрыть принудительно…” in the stage row menu or “Прервать
маршрут…” (Abort route).

**Everyone answered, but the stage is still Waiting for approval.**
GLPI rounds “Нужно одобрений, %” this way (for example, 2 of 3 at 67 %). The
route administrator closes the stage. Also check for mandatory stage questions
without an answer.

**The approver answered, but the stage did not move.**
The answer is saved but the route has not taken it yet — for example, with
simultaneous answers. The “Сроки этапов” (Stage deadlines) automatic action
catches the stage up within 15 minutes; check that GLPI's cron works. If the
answer came from someone who is no longer an addressee (left the group, the
substitution ended), the check leaves the stage alone — the route
administrator decides: “Перенести ответ согласующего” (Transfer the
approver's answer) in the stage menu.

**The requester answered the clarification, but the route is waiting.**
The requester's public followup or email continues the route by itself if
an approval stage returned the ticket; if the answer was not taken at once,
“Сроки этапов” catches it up within 15 minutes. If the assignee returned it or the
approver no longer fits the ticket, the main ticket's assignee or the route
administrator clicks “Продолжить” (Continue).

**An assignee or approver can do nothing.**
The assignee has only a self-service profile in the ticket's entity — a
central-interface profile is needed ([RIGHTS.en.md](RIGHTS.en.md)).
Self-service is enough for an approver, but they need the GLPI right
“Утверждения заявок” (Ticket approvals).

## Statuses and the end of the route

**The ticket status does not follow the stages.**
`itilflow_status_sync` is disabled or there is no system cron for
`front/cron.php`. **Setup → Automatic actions.**

**The ticket cannot be solved.**
The route is not complete or is “Остановлен” (Stopped) after a refusal. The
route administrator chooses “Начать заново с этапа…” (Restart from stage) or
“Прервать маршрут…”.

**The child ticket has a different SLA than the stage.**
A GLPI business rule with an SLA action overrode it. Exclude the stage's
category from the rule or remove the SLA from the stage.

## Installation

**After replacing the files no routes show, the plugin is disabled.**
The version in the files differs from the database — GLPI waits for an
update. Run `plugin:install` and `plugin:activate` (or Update in the plugin
list, not Uninstall).

**Old screens after an upgrade.**
Template cache: `php bin/console cache:clear` from the GLPI directory. With
`opcache.validate_timestamps=0` reset OPcache too.

**The plugin's files return 404.**
The archive was built on Windows. Rebuild it on Linux or set permissions:
`find itilflow -type d -exec chmod 755 {} +`.

**An old route's page says “Маршрут прежнего порядка” (Old-mode route).**
The route was created long ago. “Перевести на новый порядок” (Convert to the
new mode) fills the new fields; started tickets finish the old way.

## General

**Why is the interface Russian only?**
The strings are in the code; there is no translation catalogue.

**Are parallel stages possible?**
No, a route is sequential. A stage can run by condition or be skipped if
allowed.

**Can a route be edited while tickets run on it?**
Yes. Running tickets keep the settings they started with; changes apply to
new ones.

**After import a form or question was not found.**
Copying a GLPI form changes its internal keys. Choose the form by hand, then
the destination, approval step and questions ([TRANSFER.en.md](TRANSFER.en.md)).
