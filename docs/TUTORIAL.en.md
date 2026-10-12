# Setting up a route step by step

English · **[Русский](TUTORIAL.md)**

Two illustrative examples. Entity, group and category names are made up —
use your own. The interface is Russian only; Russian labels are given in
«quotes» where it helps to find them on screen.

- **Example 1** — "Request with approval": manager approval → work by
  a department → answer to the requester. New outcome handling: refusal,
  return for clarification, the result as the ticket solution.
- **Example 2** — "Work inside one department": three tasks in one ticket,
  previous handling. The simplest setup.

Before you start: [installation](INSTALL.en.md) and the **Stage routes** right
in your own profile ([RIGHTS.en.md](RIGHTS.en.md)).

---

## Example 1. Request with approval

| # | Stage | Mode | Who | Refusal | Return for clarification |
|---|---|---|---|---|---|
| 10 | Manager approval | Approval | named during processing | abort | to stage 10 |
| 20 | Department work | Child ticket | group "Service department" | abort | to stage 10 or 20 |
| 30 | Answer to the requester | Task in the ticket | group "First line" | stop | — |

### Step 1. GLPI dropdowns

The route relies on stock GLPI dropdowns — prepare them first.

**Entities.** For the example: a root entity holding the requesters, and
a child entity "Service department" where the child ticket goes.

**Groups** (**Administration → Groups**):

| Group | Where | Flags |
|---|---|---|
| First line | root entity, **visible in child entities** | "Can be assigned to tickets" |
| Service department | entity "Service department" | "Can be assigned to tickets" |

Without "Can be assigned to tickets" the group is missing from the stage's
list. The group of a "Task in the ticket" stage must be visible in the
ticket's entity — hence "visible in child entities". Put people in the groups
(the "Users" tab).

**Categories** (**Setup → Dropdowns → Ticket categories**), both visible in
child entities:

1. "Request with approval" — the rule starts the route on it.
2. "Route stage" — a service category for stage child tickets.

**Profiles.** Each participant needs a technician profile in the entity where
their object lives: the approver and the first line in the ticket's entity,
the department's technician in "Service department". A self-service profile is
enough to answer approvals and to withdraw one's own ticket, but not to work
on stages.

**Calendar and SLA** — optional: a calendar for the stage deadline, an SLA for
the child ticket.

### Step 2. The route

**Administration → Stage routes → Add**

| Field | Value | Why |
|---|---|---|
| Route name | Request with approval | seen by technicians and the requester |
| Item type | Ticket | do not change after the first starts |
| Entity | root | |
| Visible in child entities | **Yes** | otherwise a rule in a child entity cannot see the route |
| Control mode | Log | start with Log, later Warn, then Block |
| Block resolution until the route is complete | Yes | otherwise the route is only a hint |
| Write stage progress to the ticket timeline | Yes | the requester sees progress; needed for stage 30's report to become the result |
| Show the route tab to the requester | Yes | |
| Requester may withdraw the ticket | Yes | |
| Working calendar | optional | for the stage deadline |
| Outcome handling | **Previous handling** for now | we switch in step 4 |
| Route is active | Yes | an inactive route is assigned by the rule but never starts |

Save.

![Route form (screenshot of 1.6–1.7, fictional data; 1.8.0 fields not shown)](img/ui-route-form.png)

### Step 3. Stages

The **"Stages"** tab → "Add stage". The **"Department outcome and new attempt"**
block at the bottom of the form applies only with the new handling, but can be
filled in right away.

**Stage 10 — Manager approval**

| Field | Value |
|---|---|
| Stage name | Manager approval |
| Order | 10 |
| Execution mode | Approval |
| Item status at this stage | Approval |
| Who approves | Named during processing |
| Approval threshold, % | 100 |
| Instructions | what the approver should check |
| Negative outcome | Abort after refusal |

Leave group and person empty: the ticket's assignee names the approver.

**Stage 20 — Department work**

| Field | Value |
|---|---|
| Stage name | Department work |
| Order | 20 |
| Execution mode | Child ticket |
| Responsible group | Service department |
| Item status at this stage | Pending |
| Where the stage runs | Fixed entity → "Service department" |
| Child ticket category | Route stage |
| Child ticket SLA | optional |
| Instructions | what to do and what to write as the result |
| Outcome source | Explicit answer by the assigned technician |
| Negative outcome | Abort after refusal |

**Stage 30 — Answer to the requester**

| Field | Value |
|---|---|
| Stage name | Answer to the requester |
| Order | 30 |
| Execution mode | Task in the ticket |
| Responsible group | First line |
| Where the stage runs | Parent ticket's entity |
| Completion report | Ask, mandatory |
| Instructions | write the answer for the requester |
| Negative outcome | Stop after refusal |

With the new handling "Negative outcome" is mandatory on every stage, even
where refusals never happen.

**Return points.** A stage can be chosen as a return point only after it has
been saved once. Open stage 10 and select "Manager approval" in "Allowed
return points"; open stage 20 and select both 10 and 20. Stage 30 has no
return points — a task in the ticket cannot return.

![Stage form (screenshot of 1.6–1.7, fictional data; the "Department outcome and new attempt" block is not shown)](img/ui-stage-form.png)

### Step 4. New outcome handling

Open the route and set **"Outcome handling" → "Explicit outcomes and new
attempts"**. If a stage lacks an outcome source or a negative outcome, or its
return points are invalid, the route will not save and says what to fill in.

The new handling applies to starts after the save. Tickets already running
finish the previous way.

### Step 5. Start by a rule

**Administration → Rules → Business rules for tickets → Add**

- **Criterion:** Category — is — "Request with approval".
- **Actions:**
  - Start stage route («Запустить маршрут этапов») — assign — "Request with
    approval";
  - assign the assignee group "First line" with a stock action of the same or
    another rule. The ticket needs an assignee: they name the approver and
    continue the route after clarification.

![Route start chain](img/start-chain.en.svg)

If the list of routes in the rule action is empty, the route lives in another
entity and is not flagged "Visible in child entities". Mind the rule order and
"Stop processing": a rule higher up may take the ticket first.

A route can also be started by hand: the ticket's **"Stage route"** tab → pick
the route → "Start route" (requires the Stage routes right).

**Catalogue form (optional).** In **Administration → Forms** create a form and,
in its destination, set the category "Request with approval" as a specific
value — the requester cannot pick the wrong category. In GLPI 11 the catalogue
is at `/ServiceCatalog`, a form at `/Form/Render/<form id>`.

### Step 6. Check

Walk the route as each participant, not as an administrator: as an
administrator you see neither visibility limits nor missing rights.

**Normal path**

1. **The requester** creates a ticket in "Request with approval". On the
   "Stage route" tab stage 10 is "Now", waiting for the ticket's assignee to
   name the approver.
2. **First line** (the ticket's assignee): in the stage 10 row (or **Answer →
   Name the approver**) picks a person and writes the basis in "Why them".
3. **The approver**: "Approve or refuse" in the stage row → "Approve" (or the
   stock approval answer in the timeline). Stage 10 is "Done", stage 20 opens,
   and a child ticket "[Этап 20, попытка 1] …" appears in "Service department".
4. **The department's technician** opens the child ticket, its route tab →
   "Record result" → writes the result → "Done". The child ticket is solved
   with the same text, stage 30 opens.
5. **First line**: "Complete stage" in the stage 30 row (or **Answer → Complete
   stage**), fills in "What was done".
6. The ticket is solved with "all stages of the route are done" and the result.
   The requester accepts the solution the stock way.

**Refusal.** The approver refuses with a comment — the ticket is solved with
"Refused at stage «Manager approval»" and the reason.

**Return for clarification.**

1. The department's technician: "Record result" → writes what to clarify →
   "Return for clarification".
2. The requester sees a request to reply with a comment, and replies.
3. First line: "Continue after clarification" → briefly what was clarified →
   which stage to continue from (10 or 20).
4. A new attempt opens; the earlier result is in the "Stage history".

**Also check**

- The child ticket cannot be solved by hand without an outcome — GLPI shows
  a message.
- While the route is not done the ticket cannot be solved (in Log mode the
  attempt passes but lands in "Out-of-order actions").
- The requester can withdraw the ticket with a reason — it is solved with that
  reason.
- The ticket status changes to "Approval" and "Pending" within a minute if
  `itilflow_status_sync` is enabled.
- The **approval sheet** (button in the tab header) shows the whole run.

![Approval sheet (screenshot of 1.6–1.7, fictional data)](img/ui-approval-sheet.png)

---

## Example 2. Work inside one department

All stages are tasks in one ticket, previous outcome handling. Suits an
internal procedure of one unit.

| # | Stage | Mode | Group | Notes |
|---|---|---|---|---|
| 10 | Triage | Task in the ticket | First line | optional report |
| 20 | Do the work | Task in the ticket | Service department | stage deadline 480 minutes |
| 30 | Check the result | Task in the ticket | First line | **optional stage**, mandatory report |

1. **Groups.** Both groups are visible in the tickets' entity ("visible in
   child entities" from a parent entity): the task lives in the ticket itself,
   and for the duration of the stage the plugin adds the group to its
   assignees.
2. **Route.** As in example 1, "Outcome handling" — "Previous handling", with
   your own category and rule.
3. **Stages.** Mode "Task in the ticket", "Where the stage runs" — "Parent
   ticket's entity". Stage 20 has "Stage deadline, minutes" = 480 and the route
   has a working calendar: `itilflow_deadlines` marks it overdue. Stage 30 has
   "Optional stage" = Yes.
4. **Walk-through.** Each stage's assignee clicks "Complete stage" in the stage
   row or in **Answer → Complete stage**. Stage 30 also offers "Skip" — the
   reason is mandatory and goes to the timeline.
5. **End.** After stage 30 the solution block is lifted, and the assignee
   writes the ticket solution the stock way.

To move such a route to the new handling, fill in "Negative outcome" on every
stage and choose "Explicit outcomes and new attempts": at the end the ticket
will be solved automatically with the last stage's report as the result.

---

## Rollout

1. A month in **Log** mode: out-of-order actions are logged, authority is
   always checked.
2. Review the log: regular bypassing usually points at the procedure — an
   unneeded stage, the wrong owner, an unrealistic deadline.
3. **Warn** for a transition period.
4. **Block** once bypasses have almost stopped.

Set up a substantially changed process as a new route, and keep the old one
while tickets still run on it.

Problems and their causes: [FAQ.en.md](FAQ.en.md).
