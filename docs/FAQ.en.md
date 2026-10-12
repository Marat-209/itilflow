# FAQ and GLPI 11 pitfalls

English · **[Русский](FAQ.md)**

## Starting a route

**The list of routes in the business rule action is empty.**
The list is filtered by the rule's entity. A rule in a child entity sees
routes of its own and parent entities — but only those with **"Visible in
child entities"** on. Turn the flag on in the route form, or create the route
in the rule's entity. A rule without a usable value still saves and silently
does nothing — hence "the rule does not fire".

**The rule fired but the route did not start.**
Check in order: the route is active; the route's item type matches the
ticket; the ticket's entity is the route's or a child of it with "Visible in
child entities"; the rule is not overridden by a higher rule with "Stop
processing".

**The route halted as soon as a stage opened.**
Almost always the responsible group is not visible in the target entity.
GLPI lets you assign a group from another entity without an error, and the
ticket would hang with a group that cannot see it; the plugin halts the route
instead. Make the group visible in child entities from a parent entity, or
pick a group from the target entity.

**The group is missing from the stage's list.**
The group lacks **"Can be assigned to tickets"**.

## Walking the stages

**The Answer menu has no "Complete stage".**
It is shown only to the responsible of the current "Task in the ticket" stage,
and only in the central interface. An approval closes by the approver's
answer, a child ticket by its outcome (new handling) or solution (previous).

**"Complete" on an approval or a department stage is refused.**
By design since 1.8.0: an approval completes only by the approver's answer,
department work by the outcome in its ticket. Such a stage can be closed
without an answer only by force, with the right and a reason.

**The child ticket cannot be solved by hand.**
With the new handling it is closed by the department's outcome: the child
ticket's route tab → "Record result". After that it is solved automatically.

**"Name the approver" silently did not save.**
Fixed in 1.8.0. GLPI puts a hidden empty field in front of every multi-select;
if only a person or only a group was picked, the form discarded the choice.
Upgrade to 1.8.0.

**A technician or approver cannot do anything with the ticket.**
They have only a self-service profile in the ticket's entity. Rights depend on
the entity where the profile is granted — see [RIGHTS.en.md](RIGHTS.en.md).
For answering an approval, self-service is enough.

**A mandatory report was bypassed by marking the task done.**
Not since 1.7.0: with a mandatory report, marking the task done is refused;
closing goes through "Complete stage".

## Statuses and the end of a route

**The ticket status does not follow the stages (new handling).**
The `itilflow_status_sync` automatic action is disabled, or there is no system
cron running `front/cron.php`. The administrator sees a warning in the route
list. Enable the task: **Setup → Automatic actions**.

**The ticket was not solved after the last stage.**
That is the previous handling: the solution block is lifted, and the assignee
writes the solution. Automatic solving with the result happens only with the
new handling.

**The ticket cannot be solved although all stages are done.**
The route halted after a refusal ("Stop"). Route tab → "Resume route",
"New attempt…" or "Abort route…".

**After a refusal from self-service the ticket stayed "In progress".**
Fixed in 1.8.0: GLPI stored the solution but did not change the status when
the last answer came from a user without update rights on the ticket. The
route now sets the status itself.

**The child ticket's SLA differs from the stage's.**
The stage's SLA is set when the child ticket is created, then a GLPI business
rule with an SLA action overrides it. Exclude the stage service category from
such rules, or remove the SLA from the stage.

**Withdrawal is unavailable on old tickets.**
For routes started before 1.8.0 the original requester was not recorded, and
the plugin does not guess it from the current actors.

## Installation and upgrade

**After replacing the files no routes are shown and the plugin is disabled.**
GLPI compares the version in `setup.php` with the one in the database and, on
a mismatch, puts the plugin into "to be updated". Run `plugin:install` and
`plugin:activate` (or "Update" in the plugin list — not "Uninstall").

**Old forms and tabs are shown after the upgrade.**
Template cache: `php bin/console cache:clear`.

**The plugin's files return 404.**
The archive was built on Windows and the directories lack the `x` bit.
Rebuild it on Linux (`git archive` or `tar`), or fix permissions:
`find itilflow -type d -exec chmod 755 {} +` and
`find itilflow -type f -exec chmod 644 {} +`.

**Installation stopped: "schema … not confirmed".**
After the migration the plugin verifies fields, indexes and the table engine.
A common cause is tables not on InnoDB. Attach the installation log to an
issue and do not carry on with a production database.

**The migration log says "review ACLs after update".**
Expected: GLPI granted the new right to profiles that can update the
configuration. Review that list.

## GLPI 11 forms and catalogue

**"Item not found" when opening a form.**
GLPI 11 has no `/Form/Render?uuid=…` or `servicecatalog.php`. The catalogue is
`/ServiceCatalog`, a form is `/Form/Render/<form id>`.

**The form creates a ticket but the route does not start.**
The category in the form's destination is not set as a specific value but
taken from an answer or a default, and the rule does not see the expected
category. Set the category as a specific value.

## General

**Why is the interface Russian only?**
Strings are hardcoded; there is no translation catalogue yet.

**Can stages run in parallel or conditionally?**
No. A route is linear; only an optional stage can be skipped, manually.

**Can I edit a route that tickets are running on?**
With the new handling, running tickets keep the definition snapshot from their
start. With the previous handling they read the current stage settings — set
up a substantially changed process as a new route.
