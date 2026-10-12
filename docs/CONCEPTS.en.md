# Concepts

English · **[Русский](CONCEPTS.md)**

The plugin's interface is Russian only; Russian labels are given in
«quotes» where it helps to find them on screen.

## Route, stage, run, attempt

| Term | What it is | Where you see it |
|---|---|---|
| **Route** («Маршрут») | the process definition: item type, entity, order enforcement, outcome handling, requester flags | Administration → Stage routes |
| **Stage** («Этап») | one step of the procedure: who, in which mode, where, with what deadline and report | the route's "Stages" tab |
| **Run** | a route started on a specific ticket (change, problem) | the ticket's "Stage route" tab |
| **Step** | one stage passed in one run: who, when, what was done, how long it took | the stage row, the approval sheet |
| **Attempt** | (new handling) one pass through the stages; after clarification the next attempt starts in the same ticket | "attempt N" label, "Stage history" |
| **Outcome** | (new handling) a stage result: "Done", "Refused" or "Return for clarification", with public text for the requester | the "Result" column |

Number stages in steps of 10 («Порядок»): a new stage between 10 and 20 gets
15 without renumbering. Two stages with the same number are not allowed — the
second is moved to the end with a warning.

**The core rule.** A stage's object is created when the stage is entered.
Future stages do not physically exist, so there is nothing to mark as done —
order is enforced by the data model.

## Stage modes

| Mode | What is created | When to use |
|---|---|---|
| **Task in the ticket** («Задача в заявке») | a task for a group or person inside the same ticket; the group is added to the ticket's assignees for the duration of the stage | work inside one department; the final answer to the requester |
| **Child ticket** («Дочерняя заявка») | a separate ticket in the target entity, linked to the original as a child | the stage goes to another department; it needs its own SLA and visibility |
| **Approval** («Согласование») | a stock GLPI approval request in the original ticket | sign-off by a manager, resource owner, budget holder |

How a stage completes:

| Mode | Previous handling | New handling ("Explicit outcomes") |
|---|---|---|
| Task in the ticket | the responsible person: **Answer → Complete stage** (or "Complete stage" in the stage row); marking the task done if the report is not mandatory | the same |
| Child ticket | solving the child ticket | the department's outcome: **"Record result" → Done / Refused / Return for clarification**, or addressed approvals in the child ticket; the child ticket is then solved automatically with the same text |
| Approval | the approver's answer: Approve / Refuse | the approver's answer; it immediately becomes the stage's public result |

The generic "Complete" and "Skip" never close an approval or a child ticket,
in either handling. Such a stage can only be closed without an answer by force,
with the right and a reason (see [RIGHTS.en.md](RIGHTS.en.md)).

### Who approves

The stage's **"Who approves"** field («Кто согласует»):

- **Set in the stage (group or person)** — the approver is known in advance.
- **Named during processing** — the stage opens and waits until the ticket's
  assignee or a process administrator names the approvers and the basis for
  the choice ("Why them"). "Name the approver" («Указать согласующего») is in
  the stage row and in the ticket timeline's **Answer** menu.

Several approvers can be named — each gets a separate approval. How many are
needed is set by **"Approval threshold, %"**: 100 — all, 50 — half. A refusal
rejects the stage as soon as the threshold becomes unreachable. Approvers
cannot be swapped once the approvals exist. Who named whom and why is shown in
the timeline and on the approval sheet.

Approvers need no special profile right: GLPI 11 shows the answer buttons to
whoever is assigned as approver (including groups and substitutes).

## Two outcome handlings

The route's **"Outcome handling"** field («Обработка исходов отделов»):

| | Previous handling («Прежний порядок») | Explicit outcomes and new attempts («Явные исходы и новые попытки») |
|---|---|---|
| Child-ticket outcome | its solution | an explicit answer by the assignee, or addressed approvals in it; the child ticket's status alone is not an outcome |
| Refusal | "On refusal": stop, return to a stage, abort | "Negative outcome": stop or abort; going back is a separate "Return for clarification" action |
| Clarification from the requester | none | return to an allowed point, wait for the answer, new attempt |
| End of the route | the solution block is lifted; the assignee writes the solution | the ticket is solved automatically with the result |
| Ticket status per stage | set immediately when the stage opens | set by the `itilflow_status_sync` automatic action |
| Definition | the version number grows, but running tickets read the current stage settings | a full snapshot of the definition is fixed at start |

The new handling is enabled explicitly and applies to new starts only. It can
be enabled once every stage has the fields of the "Department outcome and new
attempt" block filled in — otherwise the route will not save and says what is
missing.

## Outcomes, refusal, clarification (new handling)

### Department outcome

A child-ticket stage has an **"Outcome source"** field («Источник исхода»):

- **Explicit answer by the assigned technician** — the child ticket's assignee
  (personally or through a group) clicks "Record result" and writes the result
  the requester will see. An internal comment is optional.
- **Addressed stock approval in the child ticket** — approvals are created in
  the child ticket for the stage's responsible group and/or person; their
  answers decide the outcome by the stage's threshold.

The child ticket cannot be solved by hand without an outcome: GLPI shows that
the outcome is recorded on the route tab. Once the outcome is recorded, the
child ticket is solved automatically with the same text.

### Refusal

The **"Negative outcome"** field («Отрицательный исход»):

- **Stop after refusal** — the route halts; a process administrator decides
  what next: a new attempt from an allowed point, or aborting the route.
- **Abort after refusal** — the route ends, and the ticket is solved with the
  text "Refused at stage «…»" and the reason.

Unanswered approvals of the current stage are withdrawn on refusal, return,
withdrawal and abort, so they do not linger with the approvers.

### Return for clarification and new attempt

The **"Allowed return points"** field («Допустимые точки возврата») lists this
or earlier stages from which the route may continue after clarification.
A new stage can be selected only after it has been saved once.

1. The department's technician or the approver chooses **"Return for
   clarification"** and writes what to clarify with the requester.
2. The route waits: the requester sees "Please reply with a comment in this
   ticket" and replies with an ordinary follow-up.
3. The main ticket's assignee or a process administrator clicks **"Continue
   after clarification"**, briefly states what was clarified and picks the
   stage to continue from.
4. A new attempt starts in the same ticket. Earlier outcomes and approvals stay
   in the "Stage history".

After a refusal, abort or completion a process administrator can start a new
attempt from the row menu ("New attempt…"). A solved or closed ticket must
first be reopened the stock GLPI way.

## Answer to the requester

**New handling.** When the last stage is done, the ticket is solved
automatically (in Russian):

> Заявка выполнена: все этапы маршрута «…» пройдены.
> Итог: <result of the last stage>

The result is the public result of the last department or approval. If the
route ends with a "Task in the ticket" stage (e.g. "Answer to the requester")
and stage progress is written to the timeline, that stage's report becomes the
result. A refusal with "Abort after refusal" and a withdrawal by the requester
also end in a solution with the reason; open department work is then solved
with a note that it is no longer needed. Aborting the route by an
administrator writes the reason to the timeline; the ticket solution is then
written the stock way.

Accepting or refusing the solution follows GLPI rules and is up to the
requester. The plugin does not close tickets automatically.

**Previous handling.** After the last stage the solution block is lifted and
the ticket's assignee writes the solution.

## Ticket status per stage

The stage's **"Item status at this stage"** field: which status the ticket gets
when the stage starts ("Approval", "Pending" and so on). "Do not change" — the
plugin leaves the status alone (GLPI itself may still change it by its own
rules). Terminal statuses are not offered: solving is the whole route's job.

- Previous handling — the status is set immediately when the stage opens.
- New handling — the status request is queued and applied by the
  `itilflow_status_sync` automatic action (every minute, needs a system cron).
  Until it is confirmed, the ticket's actual status is what counts. A failed
  change is visible to the administrator and does not create a second stage;
  retry via "Retry status change".

## Order enforcement

The plugin works on two GLPI levels: it revokes rights on objects of another
or not-yet-opened stage (form, Kanban, mass actions, API), and it cancels an
object change inside the save when it breaks the order.

The route's **"Control mode"** decides what happens to an order violation:

| Mode | Behaviour |
|---|---|
| **Log** («Журнал») | the action goes through, the violation is logged |
| **Warn** («Предупреждение») | the action goes through with a warning, the violation is logged |
| **Block** («Запрет») | the action is cancelled with an explanation |

The mode concerns order only. Authority — access to the ticket and entity,
current assignee, approval addressee — is always checked, in every mode.
Entries are visible to process administrators in the "Out-of-order actions"
block under the route table.

**"Block resolution until the route is complete"** — while the route is not
done, the ticket cannot be solved and no solution can be added.

## Entities

The stage's **"Where the stage runs"** field:

- **Parent ticket's entity** — the stage stays where the ticket is.
- **Fixed entity** — always the given one (a central service).
- **Assignee group's entity** — derived from the group.

The responsible group must be visible in the target entity. GLPI lets you
assign a group from another entity without an error, and the ticket would hang
with a group that cannot see it. The plugin checks this and halts the route
with a message instead of creating a dead ticket.

## Deadlines

- **Child ticket SLA** — the stock deadline with countdown and calendar.
  A task has no SLA in GLPI, so use the child-ticket mode for a stage that needs
  a countdown.
- **Stage deadline, minutes** — a deadline on the route's working calendar.
  `itilflow_deadlines` marks overdue steps: flags the step, adds a private
  follow-up, logs an entry. It does not block work.
- **Stage effort** — the task's planned duration, not a deadline.

Regardless of mode, the plugin records the actual duration of every step.

## The route tab on the ticket

One table: # · Stage (with a mode label) · Responsible · Result · Deadline.

- **Result** — a state label ("Now", "Ahead", "Done", "Refused", "Return for
  clarification", "Waiting for clarification", "Skipped", "Withdrawn",
  "Aborted", "Not run"), one line of result and its author. Earlier attempts
  fold into the "Stage history".
- **Action** — a button in the current stage's row, the form unfolds under the
  row: "Complete stage", "Record result", "Approve or refuse", "Name the
  approver", "Continue after clarification", "Explain to the requester". Only
  those who may act see the button.
- **Row menu "…"** — "Hand over stage…", "Force close…", "New attempt…",
  "Retry status change", "Reconcile approver's answer".
- **Header** — "Approval sheet", "Abort route…", "Resume route".
- **The requester** in self-service sees a simplified table (if "Show the route
  tab to the requester" is on) and a "Withdraw ticket" button (if "Requester
  may withdraw the ticket" is on).
- **A department's child ticket** shows the row of its own stage with the
  outcome form.

On a phone the table folds into cards.

## Approval sheet and report

- **Approval sheet** — a printable summary: per step the responsible person,
  hand-over with reason, who closed it, start, deadline, end, duration,
  overdue flag, result and report.
- **Bottleneck report** — **Administration → Stage routes → Bottlenecks**: per
  stage the number of passes, mean, median and maximum duration, overdue
  steps, returns, hand-overs. The bottleneck is where the maximum is far above
  the median.

## Definition version

Every save of a route increments its version number. In the previous handling
the version number is not a snapshot: running tickets read the current stage
settings, so a substantially changed procedure is best set up as a separate
route, keeping the old one until its tickets are done. In the new handling a
full snapshot of the definition is fixed at start and edits do not replace it.
