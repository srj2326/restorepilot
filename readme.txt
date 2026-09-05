=== RestorePilot Backup & Migration ===
Contributors: srjdev
Tags: backup, restore, migration, database backup, site migration
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, restore, and migrate WordPress sites with automatic URL detection and serialized-safe replacement.

== Description ==

RestorePilot Backup & Migration is a straightforward backup and restore plugin for WordPress site owners, developers, and small agencies.

It creates a downloadable backup package containing this site's WordPress database tables and, optionally, the `wp-content` files. During restore, RestorePilot automatically detects the source URL from the backup manifest and replaces it with the current site URL using serialized-safe replacement — no manual search-and-replace needed.

= Why RestorePilot =

Most backup plugins are tested by how well backups work. Restores get tested less, and that is the moment you actually need one to work.

* **A rollback point before every restore.** RestorePilot saves your current database before it changes anything, and you can restore straight from it if something goes wrong — no separate backup step, no remembering to do it yourself.
* **Resumes instead of restarting.** A host timeout, a closed browser tab, a slow connection — collecting files and restoring both pick up from where they stopped, and a restore continues partway through a single large database table. The one step that does not resume is the database export inside a backup: it is taken as a single consistent snapshot, which cannot outlive the process that opened it, so if that step is cut short it starts again rather than stitching together tables read at different moments.
* **Volume splitting for large sites.** Backups are split into volumes automatically, so a host's per-file size limit stops being a reason a backup can fail. Free disk space, rather than file size, becomes the limit.
* **Nothing leaves your server.** Backups are written to your own server, in a directory beside WordPress where the web server cannot serve them, falling back to the uploads directory if that is not writable. RestorePilot has no cloud storage integration and sends nothing to the plugin author or any third party — see Privacy & data below.
* **Built for migration, not just backup.** Source and target URLs are detected automatically from the backup itself, with serialized-safe replacement across options, widgets, and post meta — restoring a backup on a different domain does not need a manual search-and-replace pass.

= Features =

**Backup**

* Full site backup: database + wp-content files.
* Split into volumes: a site is limited by free disk space rather than by how large a single file the server allows.
* Constant memory use: the database is exported and restored as a stream, so database size is not limited by PHP's memory limit. Backups taken by much older versions used a single-document format that has to be read whole; those are still restorable, but their size is limited by memory, and RestorePilot refuses one it cannot decode before touching the site rather than failing part way.
* Resumes automatically: if a background backup is interrupted by a host timeout, file collection continues from where it left off. The database export is the exception — it runs as one consistent snapshot and restarts if interrupted, which is the price of every table being read as of the same moment. A scheduled daily backup runs as a single process and does not resume at all.
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
* Resumes automatically: if a background restore is interrupted by a host timeout, it continues from where it left off — including partway through a large table — on the next attempt instead of starting over. Each resumption re-reads the rows it has already written in order to find its place, so a table of several hundred thousand rows restores more slowly the more times it is interrupted.
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

RestorePilot creates backup files that may contain personal data from your WordPress database, media library, themes, plugins, and uploaded files. Backups are stored locally on your own server, in a `restorepilot-private-storage` directory beside the WordPress installation so that no URL reaches them; if that location cannot be written, they stay in a protected folder inside the uploads directory instead. They remain there unless you download or move them yourself. RestorePilot does not send backup data to the plugin author or to any third-party service.

Deleting the plugin removes the stored backups along with it, from either location. A storage directory you configured yourself with `RESTOREPILOT_STORAGE_DIR` is left untouched, since it is yours rather than the plugin's.

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

In a `restorepilot-private-storage` directory next to your WordPress installation — outside the web root, so no request can reach a backup even on servers that ignore `.htaccess`, which is most of them. If that directory cannot be created or written, RestorePilot falls back to a protected `restorepilot-backup-migration` folder under the uploads directory and says so on the Status tab.

Either way the folder is excluded from future RestorePilot backups. You can choose the location yourself by defining `RESTOREPILOT_STORAGE_DIR` in `wp-config.php`; a directory you name that way is never deleted by the plugin, including on uninstall.

= Why is a large download slower than it used to be? =

Every download now goes through WordPress, which checks that you are still allowed to have the file on each request. Earlier versions handed very large archives straight to the web server instead, which was faster but meant the download address kept working until a scheduled cleanup removed it — and on sites where WP-Cron is disabled or traffic is low, that could be a long time. A backup contains your whole database, so the link is checked rather than merely deleted later.

Downloads are resumable: if a transfer is interrupted, your browser or download manager can continue it rather than starting again. If your host cuts off long requests and a large download will not complete, split the backup into volumes and fetch the parts, or re-enable the direct route by adding this to `wp-config.php`:

`define('RESTOREPILOT_DIRECT_DOWNLOADS', true);`

That trades the confidentiality of the download link for reliability on a slow host. Turn it on only if you need it.

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

Yes. A background backup or restore runs in short chunks rather than one long process, so a host execution-time limit, a proxy or CDN timeout, or anything else that cuts the process short does not lose the work already done — the next chunk continues from where the last one stopped, including partway through a single large database table during a restore. On a large site this can mean several chunks before the job finishes, which is expected and does not mean anything went wrong.

Two things do not resume, and it is better to know which. The database export inside a backup is taken as one consistent snapshot; a database transaction cannot outlive the PHP process that opened it, so if that step is cut short it starts again rather than joining together tables read at different moments. And a scheduled (cron) daily backup runs as a single process throughout, so it does not chunk or resume at all.

A restore also finds its place on each resumption by re-reading the rows it has already written, so a very large single table takes longer the more times the restore is interrupted.

= Can I check a backup before restoring? =

Yes. Click **Health Check** next to any backup. RestorePilot verifies the zip structure, manifest, database export, and file paths.

= How many backups does RestorePilot keep? =

The free version keeps the newest 2 backups total. Manual backups and daily automatic backups share the same limit; older backups are removed automatically.

= Can I run backups from WP-CLI? =

Yes. Use `wp restorepilot backup` for a full backup, `wp restorepilot backup --db-only` for database only, and `wp restorepilot health` to check the newest backup.

= What happens if a restore fails? =

RestorePilot stops immediately, removes maintenance mode, and writes the full error to the Logs tab. A pre-restore rollback point may be available, but you should review the logs and verify the site before retrying.

== Changelog ==

= 0.5.8 =
* Fixed: deleting the plugin now removes your stored backups, and Master Reset's "also delete stored backups" now actually deletes them. Moving backups out of the uploads directory in 0.5.7 left both of these looking in the old place, so archives were kept while the plugin said it had removed them. Master Reset even wrote "Stored backups were deleted at the operator's request" into the log while leaving every one of them on disk. A backup contains your whole database, so a copy left behind on a site you have handed over or deleted the plugin from matters. A storage directory you set yourself with `RESTOREPILOT_STORAGE_DIR` is never removed by either, because it is yours rather than the plugin's.
* Security: large backup downloads are checked on every request instead of being placed at a secret web address that a scheduled cleanup deleted six hours later. Where WP-Cron is disabled or the site is quiet, that address kept working. Downloads are resumable, so an interrupted transfer continues rather than restarting. If your host cuts off long downloads, split the backup into volumes, or add `define('RESTOREPILOT_DIRECT_DOWNLOADS', true);` to `wp-config.php` to restore the old behaviour.
* Fixed: a restore now refuses a backup in the old single-document format that is too large for your server's PHP memory limit, and says what to do about it, instead of accepting it and running out of memory part-way through with the site already in pieces. Backups taken by any recent version stream and are unaffected.
* Fixed: the files a restore needs in order to be resumed or monitored are now written and checked properly. They are the only record that survives the moment a restore replaces the database, and a failed write was previously ignored, which could leave a restore that could not continue and whose progress could not be read.
* Fixed: the confirmation dialogs now behave like dialogs. Keyboard focus stays inside them, Escape closes them, and focus returns to the control that opened them; the page behind can no longer be reached with the keyboard or a screen reader.
* Fixed: when a restore has to invent an administrator email address because none was usable, that address is now valid on sites whose address has no dot in it, such as a local development install at `localhost`.
* Changed: the plugin's own description, privacy text and FAQ now describe where backups are actually kept and what is removed when you delete the plugin.

= 0.5.7 =
* Security: your backups are no longer kept where the web server can hand them out. They were stored under `wp-content/uploads`, protected by an `.htaccess` file — which Apache honours and **nginx ignores completely**. On nginx, which is what most managed WordPress hosting runs, a backup could be downloaded by anyone who knew or guessed its address; the only thing standing in the way was the filename. A backup contains your whole database, including every user account and password hash. Backups are now kept in a directory beside your WordPress installation, which your site has no web address for, and existing backups are moved there automatically the next time you open the plugin. Downloading through the plugin is unchanged and has always required you to be logged in as an administrator.
* Added: where your host does not allow a directory outside the site to be created, backups stay where they are and RestorePilot now says so in the log instead of assuming the `.htaccess` protected them. It checks by placing a file in the backup folder, requesting it over the web, and reporting what came back.
* Note: the move copies every file and verifies it has arrived before changing anything, and only removes the originals once the whole set is safely in place. If it cannot finish, nothing is changed and your backups stay exactly where they were.

= 0.5.6 =
* Fixed: the manual installation instructions in the repository README left out the `includes/` directory, which the plugin loads eighteen files from. Anyone following them got a fatal error instead of a working plugin. Installing through WordPress was never affected.
* Fixed: a restore now enforces its own limits by looking at the backup rather than by believing what the backup says about itself. The number of database tables was checked against a figure the archive declared, so an archive that understated it could grow the restore's working set until PHP ran out of memory. Tables are counted as they are read now, and an archive that unpacks to more than two hundred times the space it occupies is refused before anything is written to disk. A single database record longer than 64 MB is also refused rather than read whole. These protect against a damaged or crafted archive; a backup this plugin produced is unaffected, and reading the database export is now faster than before.
* Fixed: when Master Reset is asked to remove must-use plugins, anything it cannot delete is now named in the result. It previously reported only how many it had removed, so a file left behind was invisible while the reset still described the site as clean. A must-use plugin loads on every request, so one left behind is still running.
* Changed: development and test material is now excluded from the released package by an explicit list of what may be included, with the contents checked on every commit rather than at release time. No release has ever contained it; this makes that a property of the build instead of something remembered by hand.

= 0.5.5 =
* Fixed: a restore could stop partway with a "Duplicate entry" database error, and sometimes then report that a table was missing. When a restore continues in the background it may be picked up by two workers at once -- one sent directly, one from the scheduled fallback -- and both would write the same rows to the same temporary table. A row that has already been written is now recognised as such and the restore carries on, rather than treating it as a failure. Every other database error still stops the restore, and the log records when a table was written twice. This affects 0.5.3 and 0.5.4; whether it happened depended on timing, and it became more likely the larger the site. Note that this protection needs a table to have a primary or unique key to identify its rows by -- a small number of plugin tables have neither, and a restore of those can still stop this way.
* Added: the password for a new administrator account can now be shown while you type it. That password is needed immediately after the restore, to sign in to a site whose address may have just changed, with no other administrator to fall back on -- so a typo you cannot see locks you out.
* Fixed: after restoring a backup uploaded from your computer, the "Server backup path" box under Advanced settings was left holding an internal temporary filename, pointing at a file the restore had already deleted. Starting another restore without choosing a new file then failed. That box is now only ever what you type in it.
* Changed: Master Reset has moved out of the Status tab into a Danger Zone tab of its own. Everything in front of it is unchanged: the warning, the confirmation dialog, the acknowledgement, and typing RESET in full. Removing your stored backups and your must-use plugins both remain off unless you tick them.

= 0.5.4 =
* Fixed: cancelling a backup did not stop it. Cancel marked the backup cancelled, but the process doing the work never saw that. It was reading a copy of the job made when its turn started, and its own progress updates -- which happen every few seconds -- then wrote "still running" back over the cancellation. The backup carried on to the end. Cancelling now takes effect within about a second.
* Fixed: ending a stuck restore from the maintenance screen did not stop the restore. Ending it released the locks that keep two restores apart, but the process still doing the work carried on writing to the database for the rest of its turn, unaware. A restore started straight afterwards could therefore run alongside one that had not actually stopped, which is how a database ends up holding tables from two different backups. The work now stops within about a second of being ended. If you have ever ended a restore and immediately started another, compare your data against a pre-restore rollback point before trusting it.
* Fixed: a restore could lose its record of how far it had got when more than one process was involved, causing it to repeat work it had already finished. The same cause as the two above: each update to a job's record was built on a copy that could be out of date, so one process could erase what another had just written.
* Added: Master Reset can now remove must-use plugins, which it previously always left in place -- so a site it described as clean still had every one of them loading on every request. This is off unless you tick it, and the confirmation lists them by name rather than as a count, because some are installed by your host or by a site-management service and deleting those can break things you cannot reinstall yourself.

= 0.5.3 =
* Fixed: the duplicate-entry error during a restore that 0.5.2 claimed to fix was not actually fixed. The correction there re-read the restore's saved position after taking the lock that makes a worker the only one running, but WordPress caches that read within a request, so it handed back the same stale copy it already had and two workers could still resume from the same place. It now reads past the cache.
* Fixed: starting a restore while another was still finishing could make the new one delete the tables the old one was still writing to, failing it with a missing-table error. Each restore now tracks its own scratch tables, and the cleanup that removes leftovers skips any restore that is still going.
* Fixed: Master Reset deleted RestorePilot itself, along with the other plugins it is meant to remove. Reorganising the code in 0.5.2 moved the check that told the reset which plugin folder was its own, so it stopped recognising itself. If you ran Master Reset on 0.5.2 the plugin was removed but your stored backups were not -- reinstall RestorePilot and they will still be listed. Six other places that located the plugin's own files the same way were corrected at the same time, including the one a restore uses to avoid overwriting the plugin while it is running.
* Added: Master Reset can now also delete RestorePilot's own stored backups and rollback points, for when a site is being handed to someone else and should not carry copies of the previous content. It is off by default and has to be ticked deliberately, because those backups are the only way to undo a reset.

= 0.5.2 =
* Fixed: Master Reset deleted every plugin's files but left behind the database tables those plugins had created, so a site it described as reset to a clean WordPress installation still carried all of their data -- unreadable with the plugin gone, but still taking up space and still included in every backup afterwards. On the site this was found on that was 175 tables and over 200 MB left behind. Those tables are now removed as well, and the confirmation screen says so before you agree to it.
* Fixed: a restore could stop partway through with a database error about a duplicate entry. Two background workers could end up working from the same saved position -- the position was read a moment before the worker took the lock that makes it the only one running -- so one repeated work the other had already done. The position is now read after the lock is held, and the lock itself is now settled by the database rather than by a check that two workers could pass at once.
* Improved: every step of a backup or restore used to sit idle for five seconds before the next one began. The signal meant to start the next step immediately was sent while the current one still held its lock, so it was always turned away and a five-second fallback timer started every step instead. Steps now follow on immediately -- measured across a full restore, handovers went from a flat five seconds to well under one.
* Fixed: the plugin's own header still said it was tested up to WordPress 7.0 while the readme said 7.1, which is the more likely reason installs kept showing an older compatibility figure than the readme claimed.
* Changed: the plugin is now organised into separate files by area -- backup, restore, database, storage, jobs, locks and so on -- instead of one very large file. Nothing about how it behaves has changed; this makes the code possible to navigate and review.

= 0.5.1 =
* Fixed: every restore created an extra administrator account, whether or not the "Create a new admin login" box was ticked, and its password appeared once and was then gone. If you have restored a site with this plugin, check Users for an account named `admin_` followed by six random characters and delete any you did not ask for.
* Changed: "Create a new admin login" now asks for the email address and password you want, instead of generating them and showing the password once. You sign in with that email address. Nothing is displayed that has to be copied down before leaving the page, and the password is never written to the server during the restore -- it is applied from your browser once the restore finishes.
* Fixed: backups of sites with a large table that has no primary key were far slower than they needed to be -- one 800,000-row table took nearly four minutes on its own, on every backup and on every restore, because restores take a rollback point first. Such a table is now read the same efficient way as any other where it has a unique column available. On the test site this took a full database export from 235 seconds to 15.
* Improved: the restore progress bar now moves steadily through the database stage and names the table it has reached ("Restoring database (table 123 of 149)"). It previously sat on one number for the whole stage, which on a site with many tables is minutes of a bar that never moves -- indistinguishable from a restore that has died.
* Fixed: the heading above the restore progress bar always read "Uploading", including after a restore had finished or failed, directly contradicting the message underneath it.
* Changed: a finished restore now always returns you to the login page. A restore replaces the users table and the sign-in sessions stored alongside it, so you are signed out either way; the page used to send you into the admin area first, which only produced a bounce through login with a broken page on the way.
* Fixed: the confirmation dialog could grow taller than the browser window with no way to scroll, putting the acknowledgement checkbox and the confirm button out of reach. Affected every dialog in the plugin, not only the restore one.
* Improved: the maintenance page visitors see during a restore has been redesigned, works in light and dark themes, and can now be translated -- its text was previously fixed English.

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

= 0.5.8 =
Fixes two ways stored backups were kept when you asked for them to be deleted: uninstalling the plugin, and Master Reset. Large downloads are now checked on each request rather than left at a public address. Restore reliability and dialog keyboard handling improved.

= 0.5.0 =
Removes the size limits on backup and restore: backups split into volumes, the database is streamed, and background jobs resume after a host timeout. Keep all volumes of a backup together. Includes a security fix for crafted archives. Requires WordPress 6.2+.

= 0.3.1 =
Important fix: selected-folder backups now preserve plugin folder paths, so backup plugin code is included while backup archives remain excluded. Also cleans missing active plugin references after restore.
