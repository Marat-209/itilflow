# First route step by step

English · **[Русский](TUTORIAL.md)**

One example from setup to a solved ticket. All names are fictional — use your
own. What each field means — [REFERENCE.en.md](REFERENCE.en.md). The
interface is Russian only; labels are quoted in Russian with the English
meaning in brackets.

**The route “Пример: выдача оборудования”** (Example: equipment issue) starts
from an answer to the form “Пример: заказ оборудования” (Example: equipment
request):

| No. | Stage | How it runs | Who | Note |
|---|---|---|---|---|
| 10 | “Согласование руководителя” (Manager approval) | Approval | the manager from the form answer | — |
| 20 | “Подготовить” (Prepare) | Task | group “Исполнители” (Assignees) | stage question “Нужна закупка?” (Purchase needed?) |
| 25 | “Согласовать закупку” (Approve purchase) | Approval | group “Согласующие” (Approvers) | only if stage 20 answered “yes” |
| 30 | “Выдача” (Issue) | Child ticket | group “Исполнители” | then the ticket solves itself |

## Step 0. Prepare GLPI

Everything except the plugin is created the standard GLPI way: the entity,
groups and users — **Administration**, the approval step — **Setup →
Dropdowns**, the form — **Administration → Forms**.

- The plugin is installed, cron and `itilflow_status_sync` are on
  ([INSTALL.en.md](INSTALL.en.md)); you have “Маршруты этапов” (Stage routes):
  Read and Update.
- Entity “Пример” (Example); in it the groups “Исполнители” and
  “Согласующие” with Visible in a ticket → Assigned to = Yes.
- Users: a requester, their manager, an assignee (central-interface profile in
  “Пример”, member of “Исполнители”), an approver (member of “Согласующие”).
- A GLPI approval step “Согласование руководителя”.
- The form “Пример: заказ оборудования”: “Что нужно” (What is needed — list
  “Монитор” / Monitor, “Гарнитура” / Headset, “Доступ к сетевой папке” /
  Network folder access), “Срочно” (Urgent — yes / no), “Руководитель”
  (Manager — user), “Комментарий” (Comment); the first three are mandatory.
  The screenshot in step 9 also shows “Когда нужно” (When needed) and “Что ещё
  сделать” (What else to do) — optional; the route does not use them, you can
  skip them.
  Destination “Заявка” (Ticket) creates a ticket in “Пример” for the group “Исполнители”;
  approval — the “Руководитель” answer, approval step “Согласование
  руководителя”.

## Step 1. Route

**Administration → Маршруты этапов → Add:** “Название маршрута” (Route name) —
“Пример: выдача оборудования”, entity “Пример”, “Маршрут включён” (Route
enabled) — Yes (already set). After “Добавить” (Add) the readiness line says
“Не готов к запуску: 1 проблема” (Not ready to start: 1 problem) with the item
“Нет ни одного этапа.” (No stages yet.)

In the “Запуск маршрута” (Route start) block choose “Форма” (Form) —
“Пример: заказ оборудования” and save — the form destinations appear.
“Назначение формы” (Form destination) — “«Заявка» — создаётся всегда”
(“Заявка” — always created). Leave “Запускать, если” (Start if) at
“Не запускать по форме” (Not started from the form) for now — step 7 turns
it on.

![New route: readiness line, main fields, “Запуск маршрута”, collapsed “Дополнительно”](img/route-new.png)

## Step 2. Stage 10

“Этапы” (Stages) tab → “Добавить этап” (Add stage). The form shows only the
fields of the chosen “Как выполняется” (How it runs).

| Field | Value |
|---|---|
| Название этапа (Stage name) | Согласование руководителя |
| Как выполняется | Согласование (Approval) |
| Кто согласует (Who approves) | Согласование из формы (Approval from the form) |
| Этап согласования в форме (Approval step in the form) | Согласование руководителя |

Keep the default for “Если отказ” (On refusal): “Отказать инициатору
и закончить маршрут” (Refuse the requester and end the route).

![Stage 10: “Согласование из формы” and “Этап согласования в форме”](img/stage-10-approval.png)

## Step 3. Stage 20

| Field | Value |
|---|---|
| Название этапа | Подготовить |
| Как выполняется | Задача (Task) |
| Отчёт при завершении (Report on completion) | Обязательно (Required) |
| Группа (Group) | Исполнители |
| Срок этапа (Stage deadline) | 1 working day |

“Вопросы этапа” (Stage questions) → “+ Добавить вопрос” (Add question) →
“Нужна закупка?”.

![Stage 20: “Задача”, group, stage question “Нужна закупка?”](img/stage-20-question.png)

## Step 4. Stage 25

| Field | Value |
|---|---|
| Название этапа | Согласовать закупку |
| Как выполняется | Согласование |
| Кто согласует | Задан в этапе (Set in the stage) |
| Или согласующие группы (Or approver groups) | Согласующие, “Любой из группы” (Any group member) |
| Дополнительно → Порядок (More → Order) | 25 |

“Когда выполнять” (When to run) — “Только если выполнены условия” (Only if
conditions are met); “Что проверяем” (What to check) — “20. Подготовить —
«Нужна закупка?»”, “Сравнение” (Comparison) — “да” (yes). The sentence under
it: “Этап выполняется, если на этапе 20 ответили «да» на «Нужна закупка?».”
“Если ответа нет” (If no answer) — “выполнять этап” (run the stage). “Что
дальше” (What next) → “После этапа 20 «Подготовить» — сюда попадают так”
(After stage 20 — how you get here): “Выполнено → этап 25 «Согласовать
закупку» (если 20: «Нужна закупка?» — да), затем маршрут закончен” (Done →
stage 25 if yes, then the route ends). Stage 30 does not exist yet — it
appears here after step 5.

![Stage 25: “Когда выполнять”, condition, sentence, “Если ответа нет”](img/stage-25-condition.png)

## Step 5. Stage 30

| Field | Value |
|---|---|
| Название этапа | Выдача |
| Как выполняется | Дочерняя заявка (Child ticket) |
| Задание отделу (Task for the department) | issue what was prepared |
| Группа отдела (Department group) | Исполнители |
| Где создать дочернюю заявку (Where to create the child ticket) | В организации основной заявки (In the main ticket's entity) |
| Как отдел сообщает результат (How the department reports the result) | Исполнитель отмечает результат (Assignee records the result) |

![Stage 30: “Дочерняя заявка” — task, group, where to create, result, “Если отказ”](img/stage-30-child.png)

## Step 6. Check the setup

“Этапы”: “Готов к запуску · запуск вручную” (Ready to start · started manually;
the form start is enabled in step 7), the “Когда” (When) column shows the
condition of stage 25, arrows ↑ ↓ change the order.
“Схема” (Scheme): the bypass of stage 25 on the right. In stage 25, “Что
дальше” after stage 20 now ends with “затем этап 30 «Выдача»” (then stage
30): on “no” the route goes straight to stage 30.

![The “Этапы” tab: readiness line, “Когда”, arrows](img/stages-list.png)

![The “Схема” tab: four stages and the bypass of stage 25](img/route-scheme.png)

![Stage 25: “Что дальше” — after stage 20, stage 25 by condition, then stage 30](img/stage-25-next.png)

## Step 7. Start from a form answer

“Запуск маршрута” → “Запускать, если” — “Только если выполнены условия”;
“Что нужно” — “любой из” (any of) — “Монитор”, “Гарнитура”. Save. The
readiness line: “Готов к запуску · 4 этапа · запускается по ответу формы
«Пример: заказ оборудования»” (… started from the form …). No business rule
is needed.

Below it, until the second route exists, there is a note: “Назначение «Заявка»:
если «Что нужно» — «Доступ к сетевой папке», не запускается ни один маршрут —
заявка останется без маршрута.” (if the answer is “Network folder access”, no
route starts — the ticket stays without a route). This is not an error: the
second route takes such tickets, and the note disappears.

The second route “Пример: доступ к папке” (Example: folder access) — same
form and destination, the same stage 10, stage 20 a task “Открыть доступ”
(Grant access); “Запускать, если” — “Доступ к сетевой папке”. Both routes
list each other in “Другие маршруты этой формы” (Other routes of this form).
If the conditions overlap, the route is not ready.

![“Запуск маршрута”: form, destination, condition, “Другие маршруты этой формы”](img/route-form-start.png)

## Step 8. Rights

**Administration → Profiles → ‹profile› → “Маршруты этапов”.** The route
administrator gets Read and Update. The assignee needs no plugin rights, only
a central-interface profile in “Пример”. The manager and approvers need the
GLPI right “Утверждения заявок” (Ticket approvals); self-service is enough
([RIGHTS.en.md](RIGHTS.en.md)).

![Profile, the “Маршруты этапов” tab](img/profile-rights.png)

## Step 9. Requester

Test as each participant, not as the administrator.

The requester submits the form: “Что нужно” — “Монитор”, “Срочно” — “Нет”
(No), “Руководитель” — their manager. On the “Маршрут этапов” (Stage route)
tab, with the counter of passed stages “0/4”: stage 10 is “Сейчас” (Current),
the others “Впереди” (Upcoming). The requester does not see conditions or stage
questions. On a phone — cards.

![The form in the service catalog](img/form-submit.png)

![The tab for the requester: 10 “Сейчас”, the others “Впереди”](img/ticket-initiator.png)

![The requester's tab on a phone](img/ticket-initiator-phone.png)

## Step 10. Manager

Stage 10 row → “Одобрить или отказать” (Approve or refuse) → “Одобрить”
(Approve), or answer the request in the ticket timeline. Stage 10 becomes
“Согласовано” (Approved), stage 20 starts.

![Stage 10 row: “Одобрить или отказать”](img/ticket-approve.png)

## Step 11. Assignee

Stage 20 row → “Завершить этап” (Complete stage): answer “Нужна закупка?”,
fill in “Что сделано” (What was done), “Завершить этап”. If the assignee can
no longer see the ticket afterwards, their home page opens. Here, on “Нет”,
stage 30 is run by the same group “Исполнители”, so the assignee stays on the
ticket.

![“Завершить этап”: stage question and “Что сделано”](img/ticket-task-complete.png)

## Step 12. Two branches

- “Да” (Yes) — stage 25: a request to the group “Согласующие”, any member
  approves.
- “Нет” (No) — stage 25 is “Не нужен по условию” (Not needed) with the reason
  “20: «Нужна закупка?» — нет”; stage 30 starts at once.

![Stage 25 “Не нужен по условию”, stage 30 “Сейчас”](img/ticket-not-needed.png)

## Step 13. Child ticket

The child ticket “Выдача — к заявке №…” (Handover — for ticket No. …) appears in
“Пример”; the stage 30 row of the main ticket links to it as “№…”. The assignee:
“Результат отдела” (Department result) tab → “Отметить результат” (Record
result) → “Ответ инициатору” (Answer to the requester) → “Выполнено” (Done).
The child ticket solves itself.

![Child ticket: “Результат отдела” and “Отметить результат”](img/child-result.png)

## Step 14. Result

The main ticket solves itself: “Заявка выполнена: все этапы маршрута «Пример:
выдача оборудования» пройдены. Результат: …” (Ticket done: all stages passed.
Result: …). The requester accepts the solution.

![The “Заявка выполнена…” solution for the requester](img/ticket-solution.png)

## Step 15. The tab for the route administrator

On the solved ticket the header shows “Все этапы пройдены, заявка решена.”
(All stages passed, the ticket is solved), “Нужно пройти: 3 из 4 этапов
(1 не нужен по условию).” (To pass: 3 of 4 stages, 1 not needed), “Лист
согласования” (Approval sheet) and “Пройден” (Passed); below the table —
“Схема”. “Журнал маршрута” (Route log) appears under it when it has entries:
actions out of stage order, stops, overdue stages.

While the route runs there are also “…” menus: in the header — “Прервать
маршрут…” (Abort route), in the current stage row — “Передать этап…”
(Reassign stage), “Закрыть принудительно…” (Force close), as permitted
([RIGHTS.en.md](RIGHTS.en.md)). A solved ticket has none of these menus.

![The tab for the route administrator while stage 30 runs](img/ticket-tab-admin.png)

![“Лист согласования” of a completed ticket](img/approval-sheet.png)

## Next

- Refusal, clarification, “Начать заново с этапа…” (Restart from stage) —
  [REFERENCE.en.md](REFERENCE.en.md#6-result-refusal-clarification).
- Start by a rule and manually —
  [REFERENCE.en.md](REFERENCE.en.md#5-starting-a-route).
- Transfer to another GLPI — [TRANSFER.en.md](TRANSFER.en.md); problems —
  [FAQ.en.md](FAQ.en.md).
