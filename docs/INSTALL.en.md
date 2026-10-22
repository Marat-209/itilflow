# Installation and upgrade

English · **[Русский](INSTALL.md)**

## Requirements

| What | Requirement |
|---|---|
| GLPI | 11.0.x (declared 11.0.0–11.99.99). Tested on 11.0.8 |
| Database | MariaDB or MySQL 8, InnoDB tables; GLPI's connection with the default `autocommit=1` |
| PHP | the version your GLPI requires |
| Cron | a system run of `front/cron.php` every minute |
| Other | nothing: no composer, npm or external services |

Stage deadlines are stored up to 19 January 2038 — that is how GLPI itself and
the database store dates.

## Installation

### 1. Files

The plugin directory must be named `itilflow` and sit in your GLPI's
`plugins/`.

```bash
tar -xzf itilflow-1.13.1.tar.gz -C /var/www/glpi/plugins/
chown -R www-data:www-data /var/www/glpi/plugins/itilflow
```

Or download the `itilflow-1.13.1.zip` archive from this repository's Releases
page and unpack it into `plugins/` — the `itilflow` directory is already inside:

```bash
unzip itilflow-1.13.1.zip -d /var/www/glpi/plugins/
chown -R www-data:www-data /var/www/glpi/plugins/itilflow
```

Use your own path and web user. Build the archive on Linux
(`git archive --prefix=itilflow/ -o itilflow-1.13.1.tar.gz v1.13.1`): in an
archive built on Windows directories may lack the `x` permission, and GLPI
returns 404 for the plugin's files.

### 2. Install and enable

From the GLPI directory, as the web user:

```bash
php bin/console plugin:install itilflow --username=<GLPI administrator>
php bin/console plugin:activate itilflow
php bin/console cache:clear
```

Or **Setup → Plugins** → Install, then Enable — and still `cache:clear`:
without it GLPI may show old screens. If PHP does not check file changes
(`opcache.validate_timestamps=0`), reset OPcache too or restart the web
server.

Installation creates the tables, checks them and stops with a message on a
mismatch — then do not go on, find the cause.

### 3. Automatic actions

**Setup → Automatic actions:**

| Action | What it does | After installation |
|---|---|---|
| `itilflow_status_sync` | every minute sets the ticket status of the stage | **disabled** — enable it: status Scheduled, mode CLI |
| `itilflow_deadlines` (“Сроки этапов”, Stage deadlines) | every 15 minutes marks overdue stages and catches up stages where the approver's answer is saved but the stage did not move | enabled |

Both run only from the system cron:

```cron
* * * * * www-data php /var/www/glpi/front/cron.php
```

While `itilflow_status_sync` is disabled, a warning is shown above the route
list and the ticket status does not follow the stages.

### 4. Rights

**Administration → Profiles → ‹profile› → the “Маршруты этапов” (Stage
routes) tab.** Who gets what — [RIGHTS.en.md](RIGHTS.en.md).

### 5. Check

- `php bin/console plugin:list` — itilflow 1.13.1 enabled.
- The **Administration** menu has **“Маршруты этапов”**.
- `files/_log/php-errors.log` has no errors mentioning `itilflow`.

## Upgrading from 1.10 and later

In one installation, no intermediate versions. Routes, stages and running
instances are not rewritten.

1. **Backup:** the `glpi_plugin_itilflow_*` tables, the `plugins/itilflow`
   directory and, as always, the whole database.
2. **Files:** replace the contents of `plugins/itilflow` with the new files,
   delete the old ones.
3. **Install:** `plugin:install`, `plugin:activate`, `cache:clear`, as above.
   Until `plugin:install` runs, GLPI keeps the plugin disabled (“To update”)
   and no routes show on tickets.
4. **Rights:** check the “Маршруты этапов” tab of the profiles you need.

After the upgrade:

- running tickets continue from the same stage, with the settings they started
  with;
- from 1.12 and earlier, every route has “Запускать, если” (Start if) =
  “Не запускать по форме” (Not started from the form): the same business
  rules start them. A route is moved to form start by hand
  ([REFERENCE.en.md](REFERENCE.en.md#5-starting-a-route));
- from 1.13.0 to 1.13.1 the database structure does not change;
- stages saved before 1.13 are not re-checked: if you have deadlines or SLAs
  years ahead, open such stages and save them — the deadline will be checked.

## Upgrading from old versions

From 1.7–1.9 — the same procedure: the migration is cumulative. Routes created
long ago work in the old mode; the “Перевести на новый порядок” (Convert to
the new mode) button on the route page converts them. Upgrade a copy of the
production database first.

## Rollback

- **To 1.12 or later** — restore the previous version's files and run
  `plugin:install`, `plugin:activate`, `cache:clear`. The new fields stay in
  the tables and do not disturb the previous version.
- If routes were moved to form start and you roll back to 1.12, re-enable
  their business rules: 1.12 does not start routes by “Запускать, если”.
- **To older versions** — only by restoring the backup of the tables and the
  directory. Everything the routes did after the backup is lost.

## Removal

**Setup → Plugins** → Disable, then Uninstall (or `plugin:deactivate` and
`plugin:uninstall`). The plugin's rights are removed. Route and stage tables
are renamed to `*_backup_<date>`, instance tables stay: ticket history is
kept.
