# Rights

English · **[Русский](RIGHTS.md)**

The plugin has no permission system of its own. It adds two rights to GLPI
profiles and takes everything else from GLPI: entity access, rights on the
ticket, assignment as technician, assignment as approver.

## Profile rights

**Administration → Profiles → [profile]**, the plugin's rights section:

| Right | Give it to | What it allows |
|---|---|---|
| **Stage routes** («Маршруты этапов») | process administrators, support managers | read — the route list and the report; update — set up routes and stages, start manually, abort and resume, new attempt, retry status change, the out-of-order log. Such a person is called a **process administrator** below |
| **Route bypass** («Обход маршрута») | an on-call administrator — deliberately | force-close the current stage with a reason and a log entry |

On install GLPI grants Stage routes to profiles that can update the
configuration — review that list. Route bypass is granted to nobody.

Neither right gives access to a ticket the person cannot otherwise reach, and
neither lets anyone answer on behalf of another approver.

## Who may do what

Every action is checked on the server, in every control mode. The action
button on the tab is shown only to those the server will allow.

| Action | Who | Conditions |
|---|---|---|
| Complete or skip a "Task in the ticket" stage | the stage's responsible person or group member | update right on the ticket; central interface; skip — optional stages only, with a reason |
| Name the approver | the ticket's assignee (personally or via a group) or a process administrator | the stage is waiting; approvers cannot be swapped once approvals exist |
| Answer an approval | the assigned approver | stock GLPI check: addressee, their groups, substitution; no special right needed, works from self-service |
| Record a department result (new handling) | the child ticket's assignee (personally or via a group) | "How the department reports the result" is "Assignee marks the result"; update right on the child ticket |
| Return for clarification (new handling) | the department's technician or the current stage's approver | the stage has return points |
| Continue after clarification | the main ticket's assignee or a process administrator | the route is waiting for clarification |
| Explain to the requester | the outcome's author or the department's current assignee | the outcome is waiting for a public explanation |
| Hand over a stage | the stage's responsible person or a process administrator | not for approvals; a reason is mandatory |
| Force close | task and approval — Route bypass or a process administrator; child ticket — Route bypass only | a reason is mandatory; does not count as an approver's vote |
| New attempt after refusal or completion | a process administrator | new handling; return points exist |
| Retry status change, reconcile an approver's answer | a process administrator | new handling |
| Abort or resume the route | a process administrator | update right on the ticket |
| Start a route manually | a process administrator | update right on the ticket; the route is active and fits the ticket's type and entity |
| Withdraw the ticket | the original requester | the route allows withdrawal; it is running or halted; the ticket is not solved; works from self-service |
| See the route tab | everyone who sees the ticket | the requester — if "Route tab for the requester" is on |
| See the out-of-order log | a process administrator | |

## The profile's entity

A right depends not only on the profile but on the entity where it is granted.
With only a self-service profile in the ticket's entity, a technician can
neither complete nor hand over a stage: GLPI does not give them the update
right on the ticket.

- Stage owners and the ticket's assignee need a technician profile in the
  ticket's entity (or above, recursive).
- A department's technician — in the child ticket's entity.
- An approver only needs to be assigned: answering works from self-service too.

A person set as the responsible of a "Child ticket" stage must have a profile
in the target entity. With the new handling this is checked when the stage
opens: without a profile the child ticket is not created and the route
reports an error.

## About the "Approve request / incident" right

Routes do not need it. In GLPI 11 the approval answer buttons are governed by
`CommonITILValidation::canAnswer()`, which only checks whether the user is the
assigned approver. The right itself controls whom GLPI offers in its own
approver picker, and the plugin assigns approvers itself.
