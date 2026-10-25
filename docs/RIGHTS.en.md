# Rights

English · **[Русский](RIGHTS.md)**

The plugin adds two rights to GLPI profiles. Everything else comes from GLPI:
access to the entity and ticket, assignment as assignee and as approver.

## Plugin rights

**Administration → Profiles → ‹profile› → the “Маршруты этапов” (Stage routes)
tab.**

| Right | Checkbox | What it gives |
|---|---|---|
| “Маршруты этапов” | Read | the “Маршруты этапов” menu, route settings and scheme, report, export |
| | Update | route administrator: setting up routes and stages, managing the route on a ticket |
| | Create | new routes; with Update — import |
| | Purge | deleting routes and stages |
| “Закрытие этапа без ответа” (Closing a stage without an answer) | “Закрыть принудительно” (Force close) | close the current stage without an answer, with a reason; solve the ticket before the route is complete — with an entry in “Журнал маршрута” (Route log) |

- The tab exists only for central-interface profiles. It is changed by
  whoever has the GLPI right Profiles: update.
- The menu item appears after logging in again.
- On installation “Маршруты этапов” goes to profiles with General setup;
  “Закрытие этапа без ответа” — to nobody. An upgrade does not change rights.

![Profile, the “Маршруты этапов” tab](img/profile-rights.png)

## Who may do what

Every action is checked on the server; a button is shown only to whoever is
allowed. No right opens a ticket someone cannot see or lets them answer for
another approver.

| Who | May | Needs |
|---|---|---|
| route administrator | setup; on a ticket — manual start, “Прервать маршрут…” (Abort route), “Возобновить маршрут” (Resume route), “Начать заново с этапа…” (Restart from stage), “Закрыть принудительно…” for tasks and approvals, “Засчитать согласование” (Count this approval) for an already answered approval | Read and Update; update right on the ticket |
| assignee of a “Задача” (Task) stage | “Завершить этап” (Complete stage), “Пропустить” (Skip), “Передать этап…” (Reassign stage) | central-interface profile in the ticket's entity |
| ticket assignee | “Указать согласующего” (Set approver), “Засчитать согласование”, “Выполнять этап?” (Run the stage?), “Продолжить” (Continue) after clarification | the same |
| child ticket assignee | “Отметить результат” (Record result), “Ответить инициатору” (Answer the requester) | profile in the child ticket's entity |
| approver | “Одобрить” (Approve), “Отказать” (Refuse), “Вернуть на уточнение” (Return for clarification), answers to “Вопросы этапа” (Stage questions) and “Сохранить и одобрить” (Save and approve) | GLPI right “Утверждения заявок” (Ticket approvals); self-service is enough |
| requester | see the route tab if enabled; answer a clarification with a followup; “Отозвать заявку” (Withdraw ticket) if allowed | access to their ticket |
| duty administrator | “Закрыть принудительно…” for any current stage, including a child ticket; solve the ticket before the route is complete | “Закрытие этапа без ответа” |

“Продолжить” is needed only if the assignee asked for clarification, the
approver no longer fits the ticket or the requester did not answer with a
followup. If an approval stage returned the ticket, the requester's public
followup or email continues the route.

A reason is required for “Передать этап…”, “Закрыть принудительно…”,
“Указать согласующего”, “Засчитать согласование”, “Этап не нужен” (Stage not
needed), “Прервать маршрут…” and “Отозвать заявку”.

With a self-service profile in the ticket's entity an assignee can neither
complete nor reassign a stage. If the user set in a “Дочерняя заявка” (Child
ticket) stage has no profile in its entity, the child ticket is not created.

## GLPI approval right

When the route sends requests itself (“Задан в этапе” — Set in the stage,
“Указывает исполнитель заявки” — Set by the ticket's assignee), it checks
each approver: the account is active and has a profile in the ticket's entity
with the right “Утверждения заявок” (Assistance tab): create or approve
requests.

- Without the right a user is left out of the requests — unless an active
  substitute has it.
- For “Любой из группы” (Any group member) one member with the right is
  enough.
- The readiness line names approvers who fail the check.

Whoever GLPI addressed the request to may answer: the approver, a group
member or an active substitute.
