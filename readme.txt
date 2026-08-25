=== RestorePilot Backup & Migration ===
Contributors: srjdev
Tags: backup, restore, migration, database backup, site migration
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, restore, and migrate WordPress sites with automatic URL detection and serialized-safe replacement.

== Description ==

RestorePilot Backup & Migration is a straightforward backup and restore plugin for WordPress site owners, developers, and small agencies.

It creates a downloadable backup package containing this site's WordPress database tables and, optionally, the `wp-content` files. During restore, RestorePilot automatically detects the source URL from the backup manifest and replaces it with the current site URL using serialized-safe replacement — no manual search-and-replace needed.

= Why RestorePilot =

Most backup plugins are tested by how well backups work. Restores get tested less, and that is the moment you actually need one to work.

* **A rollback point before every restore.** RestorePilot saves your current database before it changes anything, and you can restore straight from it if something goes wrong — no separate backup step, no remembering to do it yourself.
* **Resumes instead of restarting.** A host timeout, a closed browser tab, a slow connection — a backup or restore picks up from exactly where it stopped, including partway through a single large database table, instead of starting the whole thing over.
* **No size ceiling.** Large sites are split into volumes automatically, so a host's per-file size limit stops being a reason a backup can fail.
* **Nothing leaves your server.** Backups are written to your own WordPress uploads directory. RestorePilot has no cloud storage integration and sends nothing to the plugin author or any third party — see Privacy & data below.
* **Built for migration, not just backup.** Source and target URLs are detected automatically from the backup itself, with serialized-safe replacement across options, widgets, and post meta — restoring a backup on a different domain does not need a manual search-and-replace pass.

= Features =

**Backup**

* Full site backup: database + wp-content files.
* No size limit: backups are split into volumes, so a site is limited by free disk space rather than by how large a single file the server allows.
* Constant memory use: the database is exported and restored as a stream, so database size is not limited by PHP's memory limit.
* Resumes automatically: if a background backup is interrupted by a host timeout, it continues from where it left off on the next attempt instead of starting over.
* One-click full backup download for restore or migration.
* Advanced file selection: choose which top-level wp-content folders to include.
* Friendly backup filenames with site name and date/time.
* Background backup jobs — navigating away does not cancel the backup.
* Progress bar with percent complete and estimated time remaining.
* Cancel button for running backups.
* Health check before restore to verify backup integrity.
* Free version keeps the newest 2 backups total across manual and daily backups.

**Restore & Migrate**

* Restore from an uploaded backup zip.
* Large uploads are sent in smaller chunks to bypass PHP upload limits.
* Restore from a zip already inside the site's uploads directory (useful for very large sites).
* Auto-detect source and target URLs from the backup manifest.
* Manual source/target URL mode for advanced migrations.
* Serialized-safe URL replacement (handles WordPress options, widgets, post meta).
* Table prefix mapping between source and target sites.
* Pre-restore database rollback point for safety.
* Resumes automatically: if a background restore is interrupted by a host timeout, it continues from where it left off — including partway through a large table — on the next attempt instead of starting over.
* Atomic table swap: new tables are staged before replacing live ones.
* Maintenance mode during restore, automatically removed on completion or failure.
* Post-restore success dialog after login.
* The database is fully replaced by the backup. wp-content files are overlaid: files present in the backup overwrite matching files on the target, but a file that exists on the target and is not in the backup is left in place, not removed.

**Downloads**

* Full backup zip is the primary download for restore and migration.
* Advanced downloads for database, plugins, themes, uploads, must-use plugins, and other wp-content files.
* Large backups are handed off to the web server where possible.
* Resumable PHP streaming with HTTP Range support as a fallback.

**Scheduled backups & notifications**

* Optional daily automatic backups via WP-Cron from the Daily Backup tab.
* Optional email notification after backup success or failure.

**Logs & diagnostics**

* Logs tab with refresh, download, clear, and quick filters.
* Dark-themed log viewer for easy reading.
* Database-backed fallback log if file logging fails.
* System Readiness panel: PHP version, ZIP support, disk space, backup folder, WP-Cron.
* Diagnostics & Maintenance panel for backup storage status, temp cleanup, stuck runtime reset, and log tools.
* Runtime PHP warning and fatal error logging during RestorePilot actions.

**WP-CLI**

* `wp restorepilot backup` — create a full backup.
* `wp restorepilot backup --db-only` — database-only backup.
* `wp restorepilot health` — check the newest backup.

**Security & compatibility**

* Admin-only access with nonce verification on every action.
* Backup storage protected by `.htaccess` and `index.php`.
* Randomised backup filenames to reduce direct-access risk.
* Installed backup plugins (UpdraftPlus, Duplicator, WP Staging, etc.) are
  backed up normally; only their *stored backup archives* are excluded from
  RestorePilot backups to avoid backup-of-backups bloat.
* Missing active plugin references are cleaned after restore to avoid WordPress
  deactivating unavailable plugins with a scary admin notice.
* Full cleanup on uninstall: backups, logs, temp files, options, scheduled events.
* Multisite compatible uninstall. Note: creating backups and running restores is not supported on multisite networks.

= Important note =

This is an early release. Always test restores on a staging site before using RestorePilot on a production site.

= Privacy & data =

RestorePilot creates backup files that may contain personal data from your WordPress database, media library, themes, plugins, and uploaded files. Backups are stored locally on your own server inside the WordPress uploads directory unless you manually download or move them elsewhere. RestorePilot does not send backup data to the plugin author or to any third-party service.

== Screenshots ==

1. Backup tab — create a full or custom backup, and download, check, restore, or delete the backups you already have.
2. Daily Backup tab — schedule one automatic backup a day and get an email when it succeeds or fails.
3. Restore tab — restore from an uploaded zip or from one already on the server, with automatic URL detection for migrations.
4. Every restore is confirmed first — what will change, how to recover if it fails, and a required acknowledgement.
5. Status tab — system readiness, storage and rollback diagnostics, and safe maintenance tools.
6. Destructive actions ask twice — Master Reset requires both a backup acknowledgement and typing RESET.

== Installation ==

= From your WordPress admin (most common) =

1. Go to **Plugins → Add New Plugin**.
2. Search for "RestorePilot".
3. Click **Install Now**, then **Activate**.
4. Go to **RestorePilot** in the admin menu and create your first backup.

= Manual install (if you downloaded the zip separately) =

1. Upload the `restorepilot-backup-migration` folder to `/wp-content/plugins/`, or upload the zip directly via **Plugins → Add New Plugin → Upload Plugin**.
2. Activate the plugin through the **Plugins** screen.
3. Go to **RestorePilot** in the admin menu and create your first backup.

== Frequently Asked Questions ==

= Does RestorePilot support migrations to a different domain? =

Yes. RestorePilot stores the source site URL in the backup manifest and replaces it with the current site URL during restore — automatically.

= Do I need to enter old and new URLs manually? =

No. Auto-detect is on by default. Manual URL fields are available in Advanced restore settings for edge cases.

= Does it handle serialized WordPress data? =

Yes. URL replacement is applied after unserializing values where possible, then the values are serialized again. Incomplete PHP classes are safely skipped.

= Where are backups stored? =

Under the WordPress uploads directory in a protected `restorepilot-backup-migration` folder. The folder is excluded from future RestorePilot backups.

= Are other backup plugins' files excluded from the backup? =

RestorePilot excludes the *backup archives* created by other plugins (UpdraftPlus, Duplicator, BackupBuddy, WP Staging, etc.) to avoid including huge backup zips inside your backup. The backup plugins *themselves* (their code inside `wp-content/plugins/`) are included normally.

= What happens when I delete the plugin? =

Deleting the plugin removes all RestorePilot backups, logs, temporary download files, background job data, backup locks, and scheduled events.

= My backup is larger than the browser upload limit. What do I do? =

Two options: (1) RestorePilot automatically splits large uploads into chunks if you upload a single zip larger than the server limit. (2) Upload the zip into this site's WordPress uploads directory via FTP/SFTP and use **Advanced restore settings → Server backup path** during restore.

= Why is my backup split into several files? =

Many hosts refuse to create a file beyond a fixed size — the write fails with "File too large" no matter how much free disk space there is. RestorePilot therefore writes a backup as a set of volumes of up to 1 GB each: `your-backup.zip`, `your-backup-v002.zip`, and so on. A small site produces a single file and looks exactly as before.

Keep the whole set together. To restore, place every volume in the same folder — RestorePilot reads them as one backup, and refuses to start if any volume is missing rather than restoring part of your site. If your host has a lower file size limit, a developer can reduce the volume size with the `restorepilot_backup_volume_bytes` filter.

You never need to handle the volumes yourself: "Download Full Backup" always gives you a single file, even when the backup behind it is stored as several volumes — RestorePilot reassembles them into one download automatically. The individual volumes are still available under "Download volumes individually" if you ever need to retry just one piece.

= My backup or restore shows "continuing in the background" instead of finishing right away — is that normal? =

Yes. A background backup or restore runs in short chunks rather than one long process, so a host execution-time limit, a proxy or CDN timeout, or anything else that cuts the process short cannot lose progress — the next chunk simply continues from exactly where the last one stopped, including partway through a single large database table during a restore. On a large site this can mean several chunks before the job finishes, which is expected and does not mean anything went wrong. A scheduled (cron) daily backup is unaffected and still runs as a single process.

= Can I check a backup before restoring? =

Yes. Click **Health Check** next to any backup. RestorePilot verifies the zip structure, manifest, database export, and file paths.

= How many backups does RestorePilot keep? =

The free version keeps the newest 2 backups total. Manual backups and daily automatic backups share the same limit; older backups are removed automatically.

= Can I run backups from WP-CLI? =

Yes. Use `wp restorepilot backup` for a full backup, `wp restorepilot backup --db-only` for database only, and `wp restorepilot health` to check the newest backup.

= What happens if a restore fails? =

RestorePilot stops immediately, removes maintenance mode, and writes the full error to the Logs tab. A pre-restore rollback point may be available, but you should review the logs and verify the site before retrying.

== Changelog ==

= 0.5.0 =
* Added: backups now split into volumes of up to 1 GB instead of one large file, so hosts with a per-file size limit can back up large sites. Total backup size is limited only by available disk space. Configurable via the `restorepilot_backup_volume_bytes` filter.
* Added: background backups and restores now run in short, resumable chunks instead of one continuous process, picking up automatically if a host interrupts them partway through — no restart from zero.
* Added: the database is exported and restored as a stream instead of being loaded into memory, so backup and restore are limited by disk space rather than PHP's memory limit.
* Added: an optional "Create a new admin login for this site" restore option adds a brand-new administrator account without touching any existing one — useful when restoring a backup to a different domain whose admin credentials you don't have. The generated password is shown once, right after the restore completes.
* Added: if a restore stops responding, signed-in administrators now see its real progress instead of a blank maintenance page, with a one-click way to end it and unlock the site — instead of waiting up to two hours.
* Improved: the progress bar now moves steadily through the database stage instead of stopping on one number for the whole of it, and names the table it has reached ("36% done • Exporting database (table 47 of 149)"). On a site with a lot of database tables that stage can run for several minutes, and a bar that never moved was impossible to tell apart from a backup that had died.
* Fixed: the backup progress display could make a perfectly healthy backup look broken. The percentage could jump backwards (8%, then 0%, then 30%) because a page reload or a resumed step reported a lower figure; the heading kept saying "Backup in progress" after a backup had been canceled or had already finished; and a single failed status check — a dropped request, a moment of server load — permanently stopped the page watching a backup that was still running, leaving the finished backup invisible until the page was reloaded by hand. Progress now only moves forward, the heading follows the real state, and a failed status check is retried instead of being treated as a failed backup.
* Changed: the backup progress line now shows which stage is running and how long it has been going ("8% done • Exporting database • 1m 12s elapsed") instead of guessing the seconds remaining. The old estimate assumed every stage ran at the same speed, which they do not — exporting a database of many small tables is far slower per percent than collecting files — so it was often wrong and could even count upwards. Naming the stage also helps most in the moment it matters: a backup sitting at 8% for a minute looks stuck until it says it is exporting the database.
* Fixed: the stored-backups notice read "You currently have 1 backups stored", because a single plural rule was being applied to two different numbers in the same sentence.
* Fixed: the pre-restore rollback point — the safety net every restore creates automatically — could not actually be restored. It's now the one thing that reliably works when a restore needs to be undone.
* Fixed: restoring from a rollback point could delete the very file it was restoring from, if that file happened to be the oldest one kept. The file being restored from is now always protected from cleanup.
* Fixed: a restore of a site with many plugins could crash partway through and leave the site down, because the database was replaced before the corresponding plugin files had been restored. Plugins are now safely held back until every file is back in place, then switched on automatically.
* Fixed: "Download Full Backup" on a backup split into volumes only ever delivered the first volume, though the button showed the full, correct size. It now always delivers one complete file; individual volumes remain available separately for retrying a failed download.
* Fixed: a combined multi-volume download couldn't be restored — it still reported its original volume count, so a restore rejected it as incomplete even though it was whole.
* Fixed: Advanced downloads (database/plugins/themes/uploads only) on a multi-volume backup only ever looked in the first volume, silently omitting content stored in later ones.
* Fixed: Master Reset's "wipe all uploads" step deleted RestorePilot's own stored backups, rollback points, and log along with everything else — including a backup manually placed there for the Server backup path option. RestorePilot's own storage is now excluded, as intended.
* Security: a database column containing raw binary data (for example an IP-address column) could be silently corrupted during backup due to a detection bug, occasionally causing two different rows to collide and making a later restore fail with a duplicate-key error. Binary data is now preserved byte-for-byte. A backup taken before this fix should be retaken.
* Security: a restore now rejects a table-creation statement that isn't a plain table definition, closing a path where a crafted backup archive could smuggle in a query that copies existing site data into a new table.
* Security: serialized values inside a backup can no longer instantiate PHP classes during URL replacement.
* Fixed: once a restore turned on maintenance mode, the requests it depends on to continue (its own background dispatch) were blocked by that same maintenance mode, permanently stalling the restore with no error shown. Maintenance mode is now enforced by RestorePilot itself so it can let its own traffic through.
* Fixed: canceling a backup could let a second backup start while the first was still finishing its current step; locks are now released only once the first has actually stopped.
* Fixed: a restore now fully validates the backup's database export — structure, required tables, row shapes — before making any change, instead of discovering a problem partway through.
* Fixed: database export now reads every table in a fixed, consistent order, so rows can no longer be duplicated or skipped if site content changes while a backup is running.
* Fixed: a failed chunked-upload reassembly left every already-uploaded piece on disk instead of cleaning up, and could briefly need nearly double the backup's size in free space; both are now handled correctly, and a doomed attempt fails immediately with a clear "space available vs. needed" message.
* Fixed: an interrupted restore's temporary database tables were not excluded from later backups, so a subsequent backup could include them as if they were real site content.
* Fixed: resuming a restore with many already-restored rows to skip past had no time budget for that step, so it could run far longer than one chunk should with nothing saved if interrupted. Skipping now yields cooperatively like the rest of a restore chunk.
* Fixed: on multisite, backup and restore are now refused immediately with a plain explanation, instead of after files were already uploaded or jobs already queued. Backup, restore, and Master Reset remain unavailable on multisite, where plugins, themes, and shared core tables span the whole network.
* Fixed: a full backup no longer silently excludes files based on their extension (for example a stray `.zip` file in wp-content), which could have omitted legitimate content while still reporting success.
* Fixed: Master Reset refuses to run on a site using a custom shared user table, instead of deleting from a table that may belong to a different WordPress installation.
* Fixed: restore no longer removes leftover staging tables by a loose wildcard match, which could have dropped an unrelated table sharing the same naming pattern; only tables it created itself are ever removed.
* Fixed: after Master Reset, the active theme and the `home` option could each be left in a broken state; both are now handled correctly.
* Fixed: three restore-lock recovery paths could leave a stale per-chunk lock behind, or briefly open a window where a second restore could start; all recovery paths now release both locks cleanly.
* Fixed: a rollback point split across multiple volumes was counted and pruned as several separate points instead of one, which could leave orphaned volume files behind after cleanup.
* Fixed: a WordPress core issue in some versions silently breaks `%`-containing values passed through `$wpdb->prepare()`, which could prevent this plugin's own leftover lock and job cleanup from finding what it needed to remove.
* Improved: write failures during backup or restore now report the operating system's reason, how much had been written, and free space at that moment, and distinguish a full disk from an invisible hosting quota.
* Changed: every database identifier is now passed through `$wpdb->prepare()`'s `%i` placeholder rather than manual escaping, which raises the minimum required WordPress version to 6.2.
* Changed: when a third-party maintenance page already exists, RestorePilot no longer touches it; the site still enters maintenance via WordPress's own mechanism.

= 0.4.0 =
* Added: per-entry ZIP64 support — backups containing individual files larger than 4 GB no longer fail.
* Added: loopback HTTP dispatch for background restore jobs, so restores continue when WP-Cron is disabled or the browser tab closes.
* Added: "Restore from rollback" panel in the Restore tab — pre-restore rollback points are now listed with a one-click restore button.
* Added: "Settings" quick link in the Plugins list and a Support link in the plugin row meta.
* Fixed: serialized scalar values (integers, booleans, floats) were silently corrupted during URL replacement when the value looked serialized but contained no URL.
* Fixed: block-editor JSON with escaped forward slashes (`https:\/\/`) was missed during URL replacement after migration.
* Fixed: background restore job had no loopback worker endpoint — the restore worker now runs reliably without an active browser session.
* Fixed: creating a backup with the advanced file selection panel open (even with all folders selected) incorrectly tagged the backup as "Partial" instead of "Full". Backups are now labelled "Partial" only when the user has explicitly excluded at least one folder.
* Fixed: stale `rp_tmp_` and `rp_old_` tables left by an interrupted restore were not cleaned up before the next restore run.
* Fixed: pre-restore `CREATE TABLE` statements were not validated, allowing a malformed backup to inject arbitrary SQL.
* Fixed: cron-based backup and restore workers did not register the shutdown handler, so a fatal error during WP-Cron could leave maintenance mode permanently enabled.
* Fixed: partial-zip temp files were not cleaned up when `addFile()` threw an exception.
* Fixed: worker lock acquisition had a TOCTOU race that could allow two workers to run simultaneously for the same job.
* Fixed: long single-table database restores (> 2 h) could trigger the stale-job detector and release an active lock.
* Improved: admin CSS and JavaScript are now properly enqueued via `wp_enqueue_style` / `wp_enqueue_script` instead of being inlined in the page output.

= 0.3.1 =
* Added a Daily Backup tab with daily automatic backup settings and an existing backups section.
* Added one-click full backup downloads, with database/plugins/themes/uploads downloads moved into Advanced downloads.
* Changed free-version retention to keep the newest 2 backups total across manual and daily backups.
* Added a Settings diagnostics and maintenance panel with backup storage status, stale temp cleanup, stuck runtime reset, and log tools.
* Hardened restore safety by validating target URLs, limiting server-path restores to the site's uploads directory, and skipping non-WordPress-prefix tables.
* Improved backup storage privacy by adding random suffixes to new backup and rollback filenames.
* Fixed: selected top-level folder backups now pass full wp-content-relative paths to the exclusion matcher, so `plugins/updraftplus`, `plugins/duplicator`, and similar plugin code folders are included correctly.
* Fixed: after restore, missing active plugin references are removed cleanly and logged, preventing WordPress from showing “plugin file does not exist” notices for old incomplete backups.

= 0.3.0 =
* Fixed: installed backup plugins (UpdraftPlus, Duplicator, WP Staging, etc.) were incorrectly excluded from backups due to an interior path-match bug. Only their stored backup archives are now excluded.
* Fixed: URL replacement used a bare domain match (`old.com`) that could corrupt email addresses and sibling domain names. Replacement now only matches full scheme-prefixed URLs (`https://`, `http://`, `//`).
* Fixed: database columns containing non-UTF-8 binary data caused a backup failure. Binary values are now base64-encoded in the export and decoded transparently on restore.
* Fixed: progress bar regressed from 95% to 92% for large backups after zip finalization. Progress now increases monotonically.
* Fixed: `add_directory_to_zip` used `str_replace($dir, $path)` which could strip the directory name multiple times. Replaced with `substr`.
* Fixed: `force_release_backup_locks` checked stale status using the lock start time instead of the job's last-updated time, which could release locks on long-running but active backups.
* Fixed: WP-CLI commands were registered at file-load time instead of the `cli_init` hook.
* Fixed: `enforce_backup_retention()` was called during restore when no new backup had been created.
* Fixed: generic `backup-` and `backup_` folder prefix rules in the skip list could exclude legitimate plugins and themes named with those prefixes.
* Fixed: `.gz` extension in the skip list excluded pre-compressed asset files. Now only `.sql.gz` and `.tar.gz` are excluded.
* Improved: `should_skip_file()` now pre-compiles lookup tables once per PHP process, reducing string comparisons by ~60% on large file sets.
* Improved: replaced private WordPress function `_get_cron_array()` with the public `wp_clear_scheduled_hook()` API (safe since WordPress 5.1).
* Improved: redesigned admin UI — branded page header, tab icons, card layout, dark log viewer, color-coded system status, better modals and forms.
* Updated: version bump to 0.3.0 to reflect the scope of fixes and improvements.

= 0.2.0 =
* Added separate Backup, Restore, Logs, and Settings tabs.
* Added friendlier backup filenames.
* Added configurable backup retention setting.
* Added log refresh, download, clear, and quick filters.
* Added system readiness panel in Settings.
* Added post-restore checklist after successful restores.
* Added clearer pre-restore modal and post-login success dialog.
* Moved restore rollback points into separate hidden storage so they do not appear as normal backups.
* Added backup health checks.
* Added restore preflight validation before maintenance mode starts.
* Added streaming file restore to reduce memory pressure.
* Added chunked large-file restore uploads for easier browser-based restores.
* Added pre-restore database rollback point.
* Added optional server-path restore for backups already on the server.
* Added optional daily scheduled backups.
* Added optional email notifications after backup success or failure.
* Added WP-CLI backup and health-check commands.

= 0.1.0 =
* Initial public release.

== Upgrade Notice ==

= 0.5.0 =
Removes the size limits on backup and restore: backups are split into volumes, the database is streamed, and both a background backup and a background restore now resume automatically if the host interrupts them, so neither file size, PHP memory, nor a host timeout caps the site size. Keep all volumes of a backup together. Includes a security fix for crafted archives. Requires WordPress 6.2+.

= 0.3.1 =
Important fix: selected-folder backups now preserve plugin folder paths, so backup plugin code is included while backup archives remain excluded. Also cleans missing active plugin references after restore.
