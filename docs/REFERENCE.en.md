# Reference

English · **[Русский](REFERENCE.md)**

What the plugin has and what it is called on screen. A step-by-step example —
[TUTORIAL.en.md](TUTORIAL.en.md), rights — [RIGHTS.en.md](RIGHTS.en.md).
The interface is Russian only: screen labels are quoted in Russian with the
English meaning in brackets; section 1 is the glossary.

1. [Words](#1-words)
2. [How a stage runs](#2-how-a-stage-runs)
3. [Who approves](#3-who-approves)
4. [Conditions and stage questions](#4-conditions-and-stage-questions)
5. [Starting a route](#5-starting-a-route)
6. [Result, refusal, clarification](#6-result-refusal-clarification)
7. [Deadlines and ticket status](#7-deadlines-and-ticket-status)
8. [Readiness and scheme](#8-readiness-and-scheme)
9. [Out-of-order actions](#9-out-of-order-actions)
10. [Who sees what](#10-who-sees-what)

## 1. Words

Where GLPI has a word for a concept, the plugin uses the GLPI word.

| On screen | Meaning |
|---|---|
| “маршрут” (route), menu “Маршруты этапов” (Stage routes), ticket tab “Маршрут этапов” (Stage route) | an order of stages for tickets |
| “этап” (stage); “Как выполняется” (How it runs): “Задача” (Task), “Дочерняя заявка” (Child ticket), “Согласование” (Approval) | one part of the work |
| “инициатор заявки” (requester) | GLPI's “Инициатор запроса” (Requester) |
| “исполнитель” (assignee); “Группа” (Group), “Группа отдела” (Department group) | who does the work; GLPI's technician group |
| “согласующий” (approver) | who answers an approval request |
| “администратор маршрутов” (route administrator) | whoever has “Маршруты этапов”: Read and Update |
| child / main ticket | a ticket for a department group / the ticket the route runs on |
| “этап согласования” (approval step) | a GLPI approval step — always in full |
| “Не нужен по условию” (Not needed) / “Пропущен” (Skipped) | the condition does not hold / skipped by hand |
| “попытка” (attempt) | another pass through the stages in the same ticket |
| “Назначение формы” (Form destination) | GLPI's form destination |

## 2. How a stage runs

| “Как выполняется” | What is created | How it ends |
|---|---|---|
| “Задача” (Task) | a task in this ticket; the group becomes the ticket's assignee for the stage | “Завершить этап” (Complete stage) and “Что сделано” (What was done) |
| “Дочерняя заявка” (Child ticket) | a ticket for the department group, “‹stage› — к заявке №N” (‹stage› — for ticket No. N); the stage row links to it as “№…” | “Результат отдела” (Department result) → “Отметить результат” (Record result); the child ticket solves itself |
| “Согласование” (Approval) | a GLPI approval request in this ticket | “Одобрить” (Approve) or “Отказать” (Refuse); result “Согласовано” (Approved) or “Отклонено” (Refused) |

- Stages are numbered in tens. Change the number in “Дополнительно” (More) →
  “Порядок” (Order) or with the ↑ ↓ arrows in the stage list.
- Child ticket: “Где создать дочернюю заявку” (Where to create the child
  ticket) — in the main ticket's entity, the group's entity or a given one; the
  department group must be visible there. “Как отдел сообщает результат” (How
  the department reports the result) — “Исполнитель отмечает результат”
  (Assignee records the result) or “Согласованием в дочерней заявке” (By
  approval in the child ticket).
- An approval or child ticket without an answer is closed only by “Закрыть
  принудительно…” (Force close) with a reason; such a stage's result is
  “Выполнено” (Done).

## 3. Who approves

The “Кто согласует” (Who approves) field:

- **“Задан в этапе”** (Set in the stage) — “Согласующие — пользователи”
  (Approvers — users) or “Или согласующие группы” (Or approver groups), at
  most 50 requests. “Любой из группы” (Any group member) — one request to the
  group; “Каждый в группе” (Each group member) — one to each member.
- **“Указывает исполнитель заявки”** (Set by the ticket's assignee) — the
  assignee clicks “Указать согласующего” (Set approver) and gives a reason.
- **“Согласование из формы”** (Approval from the form) — the GLPI form sends
  the request. The route needs a form and destination, and the destination an
  approval step; choose the same step in “Этап согласования в форме”
  (Approval step in the form). Such a stage has no condition.

“Нужно одобрений, %” (Approvals needed, %, in “Дополнительно”): 100 — all,
50 — half; counted as GLPI does. Before sending, the route checks each
approver ([RIGHTS.en.md](RIGHTS.en.md#glpi-approval-right)).

An approval from the form counts by itself only if the form created all
requests of that approval step. Otherwise the ticket's assignee clicks
“Засчитать согласование” (Count this approval) with a reason; an already
answered one is counted by the route administrator.

## 4. Conditions and stage questions

**“Когда выполнять”** (When to run): “Всегда” (Always) or “Только если
выполнены условия” (Only if conditions are met) — otherwise the stage is
“Не нужен по условию” (Not needed): nothing is created and the route moves on.

- “+ Добавить условие” (Add condition) — up to three; “Нужны” (Required):
  “все условия” (all) or “хотя бы одно” (at least one).
- “Что проверяем” (What to check): “Вопросы этапов выше” (Questions of earlier
  stages), “Ответы формы · ‹section›” (Form answers), “Этап не нужен по
  условию” (Stage not needed), “Категория заявки” (Ticket category).
- Under each condition — a sentence: “Этап выполняется, если на этапе 20
  ответили «да» на «Нужна закупка?».” (The stage runs if stage 20 answered
  “yes” to “Purchase needed?”.)
- A condition is checked when the route reaches the stage and refers only to
  earlier stages.

| What to check | Comparisons |
|---|---|
| list, radio, checkboxes | any of; none of; unknown |
| user, group, category | any of; filled; not filled; unknown |
| text, file | filled (attached); not filled; unknown |
| date | earlier / later than N days after submission; filled; not filled; unknown |
| number | equal; not equal; less; not greater; greater; not less |
| question of an earlier stage | yes; no; unknown |
| earlier stage | not needed; ran |

**“Если ответа нет”** (If no answer) — there is no answer if the ticket is
not from a form or the question was hidden. An unknown answer is never “no”.
Options: “выполнять этап” (run the stage) or “спросить исполнителя заявки”
(ask the ticket's assignee: “Выполнять этап?” — Run the stage? → “Выполнить
этап” or “Этап не нужен” with a reason; for an approval, the route
administrator decides).

**“Вопросы этапа”** (Stage questions) — up to four yes/no questions. The
assignee answers in “Завершить этап” or “Отметить результат”, the approver —
“Сохранить ответы” (Save answers) or “Сохранить и одобрить” (Save and
approve). Conditions of later stages rely on the answers.

## 5. Starting a route

- **From a form answer** (tickets only) — the “Запуск маршрута” (Route start)
  block: “Форма” (Form, then save), “Назначение формы” (Form destination),
  “Запускать, если” (Start if): “Не запускать по форме” (Not started from the
  form), “Всегда” or “Только если выполнены условия”.
- **By a business rule** — **Administration → Rules → Business rules for
  tickets**, action “Запустить маршрут этапов” (Start stage route). A rule sees
  routes of its own entity and of parent entities with child entities = Yes.
- **Manually** — the “Маршрут этапов” tab → “Запустить маршрут” (Start route),
  by the route administrator.

**Choice by form.** For each ticket of the form, enabled routes with this form
and destination that fit the entity are taken. Exactly one fits — it starts.
Two, none, or an unknown answer — no route starts, the submission is not
blocked, the ticket stays with the destination group; the readiness line
shows such tickets for the last 30 days.

**One start path per form.** If the form has a route with “Запускать, если”,
a rule does not start the route for that form's tickets — the readiness of
a route started by rules names such forms. Conditions of the
routes of one form must exclude each other — readiness shows overlaps and
gaps, and the block lists “Другие маршруты этой формы” (Other routes of this
form).

**From a rule to a form:** choose the form, destination and “Запускать,
если”; check readiness; send a test ticket; disable the rule.

## 6. Result, refusal, clarification

**Refusal** — “Что делать при отказе” (On refusal):

- “Отказать инициатору и закончить маршрут” (Refuse the requester and end the
  route) — the ticket is solved with “Отказ на этапе «…».” (Refused at stage
  “…”) and the reason;
- “Остановить маршрут” (Stop the route) — the route administrator decides.

Unanswered requests of the stage are withdrawn on refusal, return,
withdrawal and abort; given answers stay.

**Return for clarification.** “Куда вернуть после уточнения” (Where to return
after clarification) — from which stages the route may continue; empty — no
return.

1. The child ticket's assignee or the approver writes what to clarify and
   picks “С этапа” (From stage) if there are several.
2. “Ждём ответа инициатора” (Waiting for the requester; the requester sees
   “Ждём вашего ответа”, Waiting for your answer): the requester answers
   with a public followup in the ticket.
3. If an approval stage returned the ticket, the route continues by itself,
   also from an email through the mail collector: the stage reopens and the
   same approvers (including the approver from the form) get a new request —
   everyone who was asked, including those who had already approved; the
   “Нужно одобрений” (Approvals needed) value stays. A second followup changes
   nothing. If the answer was not taken at once (simultaneous actions,
   a failure), “Сроки этапов” (Stage deadlines) continues the route.
4. If the assignee returned it, the approver no longer fits the ticket or the
   answer did not come as a followup, the main ticket's assignee or the route
   administrator clicks “Продолжить” (Continue). No stage choice: it was
   chosen at the return.

Then a new attempt starts; earlier results are in “История этапа” (Stage
history).

**Route administrator's menu:**

- “Начать заново с этапа…” (Restart from stage, row menu) — after a refusal,
  abort or the end of the route; reopen a solved ticket in GLPI first.
- “Возобновить маршрут” (Resume route, header menu) — only after a failure:
  the group is not visible, the deadline does not fit. It is absent after a
  refusal — use “Начать заново с этапа…” or “Прервать маршрут…”.
- “Прервать маршрут…” (Abort route) — with a reason for the requester; the
  ticket is then solved the usual way.

**End of the route:** the ticket solves itself — “Заявка выполнена: все этапы
маршрута «…» пройдены. Результат: …” (Ticket done: all stages of route “…”
passed. Result: …). The requester accepts the solution as usual. “Отозвать
заявку” (Withdraw ticket, if allowed) aborts the route and solves the ticket
with the reason.

## 7. Deadlines and ticket status

- “Срок этапа” (Stage deadline) — in working days or hours of the route
  calendar; empty — no deadline. Every 15 minutes “Сроки этапов” (Stage
  deadlines, `itilflow_deadlines`) marks overdue stages: a flag, a followup,
  a route log entry.
- The same action catches up an approval stage when the approver's answer
  is saved but the stage did not move (for example, with simultaneous
  answers) — if the answer came from the current addressee of the request
  (or their substitute) — and continues the route from a requester's answer
  the route did not take at once. All running stages are checked. A working
  GLPI cron is needed.
- Deadlines and SLAs ending after 18 January 2038 are not saved.
- “Статус заявки на этапе” (Ticket status at the stage, in “Дополнительно”) is
  set by `itilflow_status_sync` within a minute; “Не менять” (Keep) leaves it.

## 8. Readiness and scheme

**The readiness line** above the route form and the stage list: “Готов
к запуску · 4 этапа · запускается по ответу формы «…»” (Ready to start ·
4 stages · started from form “…”) or “Не готов к запуску: N проблем” (Not
ready: N problems) with a link to the stage. For whoever may edit the route
it is recalculated without saving, as is “Что дальше” (What next) on the
stage form.

**“Схема”** (Scheme) — a route tab: stages top to bottom, returns on the left,
bypasses on the right. On a ticket — the route as it was at start.

**The version** grows with every save; started tickets follow their own
version.

## 9. Out-of-order actions

“Если действие не по порядку этапов” (Out-of-order actions, in the route's
“Дополнительно”): “Только записать” (Log only) → an entry in “Журнал маршрута”
(Route log); “Предупредить” (Warn) — plus a warning; “Запретить” (Deny) — the
action is cancelled. Ticket rights are always checked.

Start with “Только записать”, review the log after a month, then
“Предупредить” and “Запретить”.

## 10. Who sees what

| Who | What they see on the “Маршрут этапов” tab |
|---|---|
| assignees and approvers (central interface) | all stages, “если …” (if …), reasons for “Не нужен по условию”, stage questions, “Лист согласования” (Approval sheet) |
| requester | the table without conditions and stage questions — if “Вкладка маршрута у инициатора” (Route tab for the requester) is on; “Отозвать заявку” |
| approver in self-service | “Одобрить или отказать” (Approve or refuse) in their row |
| route administrator | also “Схема”, “Журнал маршрута”, the “…” menu |

An action button is shown only to whoever may perform it. On a phone rows
become cards. A child ticket shows its stage on the “Результат отдела” tab.

**“Лист согласования”** (Approval sheet) — a printable summary by stage,
with the approver's comment.
**“Узкие места маршрутов”** (Route bottlenecks) — a link above the route
list: stage durations, overdue stages, refusals; a bottleneck is where the
maximum is much greater than the median.
