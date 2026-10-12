# Installation and upgrade

English · **[Русский](INSTALL.md)**

## Requirements

| What | Requirement |
|---|---|
| GLPI | 11.0.x. Declared 11.0.0–11.99.99; release 1.9.0 tested on 11.0.8 |
| Database | MariaDB (tested) or MySQL 8 (not tested); InnoDB tables |
| PHP | whatever your GLPI 11 requires |
| Cron | a system cron running `front/cron.php` every minute: the plugin's tasks run in CLI mode |
| Other | nothing: no composer, npm or external services |

## Installation

### 1. Files

The plugin directory must be named `itilflow` and sit in your GLPI's
`plugins/` directory.

From a release archive:

```bash
tar -xzf itilflow-1.9.0.tar.gz -C /var/www/glpi/plugins/
chown -R www-data:www-data /var/www/glpi/plugins/itilflow
```

From the repository (the repository root is the plugin directory):

```bash
git clone <this repository's URL> /var/www/glpi/plugins/itilflow
cd /var/www/glpi/plugins/itilflow && git checkout v1.9.0
```

Use your own web user (`www-data`) and GLPI path — the same as the other
directories in `plugins/`.

**Build archives on Linux.** A zip built on Windows carries no unix
permissions: after unpacking, directories may lack the `x` bit and GLPI
answers 404 for the plugin's files. A reliable way:

```bash
git archive --prefix=itilflow/ -o itilflow-1.9.0.tar.gz v1.9.0
```

### 2. Install and enable

From the GLPI directory, as the web user:

```bash
php bin/console plugin:install itilflow --username=<GLPI administrator>
php bin/console plugin:activate itilflow
php bin/console cache:clear
```

The same is available in the interface: **Setup → Plugins** → "Install", then
"Enable". Do not skip clearing the cache: without it GLPI may keep rendering
old templates. Do the same after every replacement of the plugin files.

Why. In production mode GLPI 11 does not reread changed templates: the
compiled copy is looked up by the file's path, not by its contents, and
"Update" in the plugin list does not touch it. Since 1.9.0, install and
"Update" remove the plugin's compiled template copies themselves. But
`cache:clear` is still needed: if the console and the web server run different
PHP versions, the console cannot find the web server's copies. A failure of
this step does not stop the installation — the reason is written to
`files/_log/itilflow.log`.

If OPcache on the server is configured without file timestamp checks
(`opcache.validate_timestamps=0`), reset it too after replacing the files:
**Setup → General → Performance** → "Reset", or restart the web server.
Otherwise the old plugin classes stay in use.

The installer creates the tables, verifies their schema and stops with
a message on any mismatch. An incomplete schema is a reason to investigate,
not to carry on.

### 3. Automatic actions

**Setup → Automatic actions**:

| Action | What it does | After install |
|---|---|---|
| `itilflow_deadlines` | every 15 minutes marks overdue stages | enabled, CLI mode |
| `itilflow_status_sync` | every minute applies the stage's ticket status in routes with the new outcome handling | **disabled** — enable it yourself: state "Scheduled", CLI mode |

Both run only with a system cron:

```cron
* * * * * www-data php /var/www/glpi/front/cron.php
```

While `itilflow_status_sync` is disabled, the route list shows a warning to
the administrator, and the ticket status does not follow the stages of routes
with the new outcome handling.

### 4. Rights

Installation adds two profile rights: **Stage routes** («Маршруты этапов»)
and **Route bypass** («Обход маршрута»). GLPI grants Stage routes to profiles
that can update the configuration and writes a migration log file such as
`warning_during_migration_to_1.8.0.log` with "review ACLs after update" —
this is expected. Check who got the right and grant it to the people who set
up processes. Route bypass is granted to nobody by default.

Details: [RIGHTS.en.md](RIGHTS.en.md).

### 5. Check

- `php bin/console plugin:list` — itilflow 1.9.0 is enabled.
- The database has nine `glpi_plugin_itilflow_*` tables: processes, stages,
  instances, steps, violations, attempts, outcomes, outcomevalidations,
  commandreceipts.
- `files/_log/php-errors.log` has no errors mentioning `itilflow`.
- The **Administration** menu has **Stage routes** (requires the right).

## Upgrading from 1.8.x

The database schema does not change in 1.9.0: no new tables or columns, and
the installer does not edit any rows. The route and stage settings screens
change.

1. **Backup** of `plugins/itilflow` (and, as always, of the database).
2. **Files.** Replace the contents of `plugins/itilflow` with 1.9.0.
3. **Install** — as above: `plugin:install`, `plugin:activate`,
   `cache:clear`. Until `plugin:install` runs, GLPI keeps the plugin disabled
   ("to be updated").

What process administrators will see:

- the route and stage forms show only what is needed, rarely used fields are
  under the collapsed "More"; the stage deadline is in working days or hours;
- a readiness line on the route and above the stage table;
- a new route created from the form uses the new outcome handling right away;
  routes on the previous handling show a banner and the "Convert to the new
  order" button («Перевести на новый порядок»). Existing routes do not change
  by themselves: they are converted only when the button is pressed;
- a route created through the API without `outcome_contract` stays on the
  previous handling by default.

**Rollback** — put the 1.8.x files back and run `cache:clear`: 1.9.0 writes
no data that 1.8 would not understand. Routes converted with the button stay
on the new handling — 1.8 understands it too.

## Upgrading from 1.7.x

### Procedure

1. **Backup.** A full database dump and a copy of `plugins/itilflow`. Ideally,
   rehearse the upgrade on a copy of the production database first.
2. **Files.** Replace the contents of `plugins/itilflow` with 1.9.0 (remove the
   old files: some templates were dropped and some classes added since 1.8.0).
3. **Migration.**

   ```bash
   php bin/console plugin:install itilflow --username=<GLPI administrator>
   php bin/console plugin:activate itilflow
   php bin/console cache:clear
   ```

   Once the version in the files changes, GLPI puts the plugin into
   "to be updated" and disables it. Until `plugin:install` runs, routes are
   not shown on tickets. In the interface this is the "Update" button in the
   plugin list — not "Uninstall".
4. **`itilflow_status_sync`** — enable it as for a fresh install.
5. **Check** — as for a fresh install, plus one ticket per production route
   walked with ordinary roles.

The migration is cumulative: upgrading from earlier 1.x versions follows the
same path, but the tested path is from 1.7.0.

### What changes for existing routes

- **Outcome handling does not change.** All routes stay on the previous
  handling; each one is converted to the new handling with the "Convert to
  the new order" button on the route page, and the new handling applies to
  new starts only.
- **Authority is checked more strictly — in every control mode.** "Complete"
  and "Skip" no longer close an approval stage or a child-ticket stage: those
  close by the approver's answer and by the child ticket's solution (or
  outcome). An approval without an assigned approver waits for one instead of
  opening to all technicians. Log mode no longer lets through actions without
  authority — only order violations.
- **Forced completion** requires the Route bypass right (for tasks and
  approvals — or the right to update Stage routes) and a reason; it is logged
  as a separate entry.
- **Withdrawal by the requester** is unavailable for routes started before the
  upgrade: the original requester was not recorded for them, and the plugin
  does not guess it from the ticket's current actors.
- **Thresholds of running approvals** are frozen at upgrade time as they were
  in the stage settings.
- **The route tab** on the ticket looks different: one table of stages.

### Rollback

Putting the previous files back **does not roll the database schema back**:
the new fields and tables stay, and the previous version will not understand
data written by 1.8.0. Roll back only by restoring the database dump and the
plugin directory taken in step 1.

## Removal

**Setup → Plugins** → "Disable", then "Uninstall" (or `plugin:deactivate` and
`plugin:uninstall`).

Uninstalling removes the plugin's rights. The route and stage tables are
renamed to `*_backup_<date>`; the run history tables stay, so tickets keep
their history.
