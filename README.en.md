# itilflow — stage routes for GLPI 11

English · **[Русский](README.md)**

The plugin leads a GLPI ticket along a route: stage by stage, in a fixed
order. A stage is a task in the ticket itself, a child ticket for a department
group, or a GLPI approval. Until the route is complete the ticket cannot be
solved; at the end the requester gets a solution with the result or the
reason for a refusal.

The task, child ticket or approval request is created when the route reaches
the stage, so a future stage cannot be marked done ahead of time. Every route
action is checked on the server.

GLPI core is not changed: deadlines, rights, notifications and approvals stay
standard.

> **Language notice.** The plugin's interface is **Russian only**. These
> documents quote the Russian screen labels and give the English meaning in
> brackets at first mention.

![The “Маршрут этапов” (Stage route) tab on a ticket: stages with results, approval sheet, scheme](docs/img/ticket-tab-admin.png)

## Features

- **Three stage kinds:** “Задача” (Task), “Дочерняя заявка” (Child ticket),
  “Согласование” (Approval).
- **Who approves:** users or groups set in the stage (any member or each
  member, “Нужно одобрений, %” — Approvals needed, %), an approver chosen by
  the ticket's assignee, or the approval requested by a GLPI form.
- **Conditional stages:** “Когда выполнять” (When to run) — by form answers,
  questions of earlier stages and ticket category; a stage that is not needed
  creates nothing.
- **Start** from a form answer, by a business rule, or manually on a ticket.
- **Refusal, clarification, new attempt:** “Вернуть на уточнение” (Return for
  clarification) and “Начать заново с этапа…” (Restart from stage) in the same
  ticket; earlier attempts stay in the history.
- **Solution at the end** — with the last stage's result or the refusal
  reason; the requester accepts it.
- **Readiness line:** whether the route is ready to start and what is
  missing — at once, without saving.
- **Overview and transfer:** approval sheet, route scheme, “Узкие места
  маршрутов” (Route bottlenecks) report, export and import of routes.

## Requirements

- GLPI 11.0.x. Tested on GLPI 11.0.8 with MariaDB and MySQL 8.
- MariaDB or MySQL 8, InnoDB tables. Deadlines up to 19 January 2038.
- PHP — the version your GLPI requires.
- A system cron running GLPI's `front/cron.php` every minute.
- Nothing else: no composer, npm or external services.

## Installation

```bash
tar -xzf itilflow-1.13.3.tar.gz -C /var/www/glpi/plugins/
cd /var/www/glpi
php bin/console plugin:install itilflow --username=<GLPI administrator>
php bin/console plugin:activate itilflow
php bin/console cache:clear
```

Then enable the `itilflow_status_sync` automatic action and grant rights on
the profile tab “Маршруты этапов” (Stage routes). Details, upgrade and
rollback — [docs/INSTALL.en.md](docs/INSTALL.en.md).

## Documentation

| Document | Contents |
|---|---|
| [First route step by step](docs/TUTORIAL.en.md) | one example from setup to a solved ticket, with screenshots |
| [Reference](docs/REFERENCE.en.md) | stages, approvals, conditions, start, refusal and clarification, deadlines, readiness, who sees what |
| [Rights](docs/RIGHTS.en.md) | the profile tab and who may do what |
| [Route transfer](docs/TRANSFER.en.md) | export and import of routes between GLPI installations |
| [Installation and upgrade](docs/INSTALL.en.md) | requirements, installation, cron, upgrade, rollback, removal |
| [FAQ](docs/FAQ.en.md) | what to do when something is wrong |
| [Changelog](CHANGELOG.md) | all releases (in Russian) |

## Limitations

- A route is sequential: one current stage, no parallel stages.
- Child tickets and start from a form are for tickets only; changes and
  problems have “Задача” and “Согласование”.
- No own notifications: GLPI notifications about tasks, tickets, approvals
  and solutions work as usual.
- Dates after 19 January 2038 are not stored.
- Russian interface only.

## License

GNU General Public License version 3 or later — [LICENSE](LICENSE).

Copyright (C) 2026 itilflow contributors

This program is distributed in the hope that it will be useful, but **without
any warranty**.
