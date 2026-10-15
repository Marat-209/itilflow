# itilflow — staged routes for ticket handling in GLPI 11

English · **[Русский](README.md)**

A GLPI 11 plugin that leads a ticket through a defined procedure: stage by
stage, in a fixed order, across the departments that must be involved — and
refuses to let the ticket close until the procedure is complete. At the end
of the route the requester gets an answer as the ticket solution: the result
of the work or the reason for a refusal.

GLPI core is not patched. Deadlines, permissions, notifications and reporting
stay with GLPI; the plugin introduces no ticket statuses, time accounting or
permission system of its own.

> **Language notice.** The plugin's user interface is **Russian only**: strings
> are hardcoded and there is no translation catalogue. These documents give the
> Russian labels next to their English meaning where it helps.

---

## Why

GLPI has tasks, child tickets and approvals, but no order between them.
Nothing stops a ticket from being solved while an approval is skipped; nothing
says that a manager's approval comes first and the department's work second;
no stock report answers which stage tickets get stuck at, and for how long.

A route turns the procedure into a sequence of stages. A stage's object —
a task, a child ticket or an approval request — is created **when the stage
is entered**, not all at once. Future stages do not physically exist, so
there is nothing to mark as done: order is enforced by the data model, not by
an interface check that an API call could bypass.

The simplest route:

```
manager approval → work by a department → answer to the requester
```

## Features

- **Three stage modes:** a task in the ticket itself, a child ticket in another
  department (with its own SLA and visibility), a stock GLPI approval with
  a threshold.
- **The approver is set in the stage or named while the ticket is processed**,
  including several approvers with separate approvals and a threshold.
- **Explicit outcomes and new attempts (1.8.0).** A department answers
  "Done", "Refused" or "Return for clarification"; after clarification the
  route continues as a new attempt in the same ticket, earlier attempts stay
  in the history.
- **Answer to the requester (1.8.0).** After the last stage the ticket is
  solved with the result, after a refusal — with the reason; accepting the
  solution remains with the requester.
- **Server-side authority checks (1.8.0).** Every route action is checked on
  the server: access to the ticket and entity, current stage, current
  assignee, approval addressee.
- **One route table on the ticket (1.8.0).** Each stage's result and the action
  button sit in the stage row; on a phone the table folds into cards.
- **Simple settings (1.9.0).** The stage form shows only the fields of its
  mode, rarely used ones under "More"; the deadline is in working days or
  hours; a readiness line tells whether the route is ready and what is
  missing; a new route uses the new outcome handling right away, an old one is
  converted with one button.
- **Moving routes (1.10.0).** Export to a JSON file and import — like GLPI 11
  forms: preview, "Resolve issues" with replacements picked from GLPI lists,
  routes are created disabled in one transaction, existing ones never change.
- **Order enforcement** in Log, Warn and Block modes, with a log of
  out-of-order actions.
- **Ticket status per stage**, stage deadline with overdue marking, stage
  hand-over, withdrawal by the requester, approval sheet, bottleneck report.
- **Start by a stock business rule** or manually from the ticket.

## Requirements

- GLPI 11.0.x (`setup.php` declares 11.0.0–11.99.99). Release 1.10.0 was
  tested on GLPI 11.0.8 with MariaDB: upgrade from 1.9.0.
  It has not been tested on MySQL 8.
- PHP — whatever your GLPI 11 requires.
- InnoDB tables (the installer checks this).
- A system cron running GLPI's `front/cron.php` every minute: the plugin's
  automatic actions run in CLI mode.
- Nothing else: no composer, npm or external services.

## Installation

```bash
# unpack the release archive into GLPI's plugins directory — you get an itilflow directory
tar -xzf itilflow-1.10.0.tar.gz -C /var/www/glpi/plugins/
chown -R www-data:www-data /var/www/glpi/plugins/itilflow

# install, enable, clear the cache (from the GLPI directory, as the web user)
php bin/console plugin:install itilflow --username=<GLPI administrator>
php bin/console plugin:activate itilflow
php bin/console cache:clear
```

Then enable the `itilflow_status_sync` automatic action (**Setup → Automatic
actions**) and grant the rights in profiles. Details:
[docs/INSTALL.en.md](docs/INSTALL.en.md).

## Upgrading

**From 1.9.x.** The database schema does not change. Replace the plugin files
with 1.10.0 and run `plugin:install`, `plugin:activate` and `cache:clear` (in the
UI — "Upgrade" and "Enable" in the plugin list). Rollback: put the 1.9.x files
back and run the same three commands. Details:
[docs/INSTALL.en.md](docs/INSTALL.en.md#upgrading-from-19x).

**From 1.8.x.** The database schema does not change. Replace the plugin files
with 1.10.0 and run `plugin:install`, `plugin:activate` and `cache:clear`.
Existing routes do not change; old ones are converted to the new outcome
handling with a button on the route page. Rollback: put the 1.8.x files back
and run `cache:clear`, `plugin:install` and `plugin:activate`: when the
version number changes, GLPI keeps the plugin disabled until `plugin:install`
runs. If PHP does not check files for changes (`opcache.validate_timestamps=0`),
restart PHP-FPM or the web server. Details:
[docs/INSTALL.en.md](docs/INSTALL.en.md#upgrading-from-18x).

**From 1.7.x.**

1. Back up the database and the plugin directory.
2. Replace the plugin files with 1.10.0.
3. Run `plugin:install`, `plugin:activate` and `cache:clear` as for a fresh
   install. Until `plugin:install` runs, GLPI keeps the plugin disabled
   ("to be updated").
4. Enable `itilflow_status_sync`.

Existing routes stay in the previous outcome handling and behave as before;
each one is converted to the new handling with the "Convert to the new order"
button («Перевести на новый порядок»). Putting the old files back does not
roll the database schema back — only restoring the backup does. Details:
[docs/INSTALL.en.md](docs/INSTALL.en.md#upgrading-from-17x).

## Quick start

1. **GLPI dropdowns.** Assignee groups flagged "Can be assigned to tickets";
   a ticket category that will start the route.
2. **Route.** **Administration → Stage routes** («Маршруты этапов») **→ Add**:
   name, entity, "Visible in child entities", working calendar. Everything
   else is under "More"; the defaults are fine to start with.
3. **Stages.** The "Stages" tab → "Add stage", for example:

   | # | Stage | How it is done | Who |
   |---|---|---|---|
   | 10 | Manager approval | Approval | chosen during the run |
   | 20 | Department work | Child ticket | group "Service department" |
   | 30 | Answer to the requester | Task in the ticket | group "First line" |

   Refusal, return for clarification and the department's result are filled
   with defaults.
4. **Readiness.** The line above the stage table says "Ready to start" or
   names what is missing.
5. **Start.** **Administration → Rules → Business rules for tickets**:
   criterion "Category is …", action "Start stage route → assign → your route".
6. **Check.** Create a ticket in that category and walk the route as each
   participant — not as an administrator.

Step by step, with fields and checks: [docs/TUTORIAL.en.md](docs/TUTORIAL.en.md).

## Documentation

| Document | Contents |
|---|---|
| [Installation and upgrade](docs/INSTALL.en.md) | requirements, install, cron, upgrade from 1.9, 1.8 and 1.7, rollback, removal |
| [Concepts](docs/CONCEPTS.en.md) | route, stage, stage modes, outcomes and attempts, answer to the requester, statuses |
| [Setting up a route step by step](docs/TUTORIAL.en.md) | two illustrative examples from dropdowns to testing; moving routes to another GLPI: what is not moved, a fictitious example file |
| [Rights](docs/RIGHTS.en.md) | profile rights and who may perform which action |
| [FAQ and GLPI 11 pitfalls](docs/FAQ.en.md) | symptoms, causes, fixes |
| [Changelog](CHANGELOG.md) | all releases (in Russian) |

The screenshots of the route and stage forms and of the "Stages" tab in
`docs/img/` were taken on 1.9.0 with fictional data; the approval sheet was
taken on 1.6–1.7.

## Limitations

- A route is linear: one current stage, no branches, no parallel stages.
  No conditional stages — only an optional stage can be skipped, manually.
- Child tickets are supported for the "Ticket" type only; changes and problems
  get the "Task" and "Approval" modes.
- The plugin sends no notifications of its own: GLPI's stock notifications on
  tasks, child tickets, approvals and solutions apply.
- Russian-only interface.

## Licence

GNU General Public License version 3 or later. See [LICENSE](LICENSE).

Copyright (C) 2026 itilflow contributors

This program is distributed in the hope that it will be useful, but **without
any warranty**.
