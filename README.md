# RestorePilot Backup & Migration

Back up, restore, and migrate WordPress sites with automatic URL detection and serialized-safe replacement.

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/restorepilot-backup-migration.svg)](https://wordpress.org/plugins/restorepilot-backup-migration/)
[![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dt/restorepilot-backup-migration.svg)](https://wordpress.org/plugins/restorepilot-backup-migration/)
[![WordPress Plugin Rating](https://img.shields.io/wordpress/plugin/stars/restorepilot-backup-migration.svg)](https://wordpress.org/support/plugin/restorepilot-backup-migration/reviews/)
[![Tested up to WP](https://img.shields.io/wordpress/v/restorepilot-backup-migration.svg?label=tested%20up%20to)](https://wordpress.org/plugins/restorepilot-backup-migration/)
[![License: GPLv2+](https://img.shields.io/badge/license-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**[View on WordPress.org →](https://wordpress.org/plugins/restorepilot-backup-migration/)**

## Why RestorePilot

Most backup plugins are tested by how well backups work. Restores get tested less, and that is the moment you actually need one to work.

- **A rollback point before every restore.** RestorePilot saves your current database before it changes anything, and you can restore straight from it if something goes wrong — no separate backup step, no remembering to do it yourself.
- **Resumes instead of restarting.** A host timeout, a closed browser tab, a slow connection — a backup or restore picks up from exactly where it stopped, including partway through a single large database table, instead of starting over.
- **No size ceiling.** Large sites are split into volumes automatically, so a host's per-file size limit stops being a reason a backup can fail.
- **Nothing leaves your server.** Backups are written to your own WordPress uploads directory. RestorePilot has no cloud storage integration and sends nothing to a third party.
- **Built for migration, not just backup.** Source and target URLs are detected automatically from the backup itself, with serialized-safe replacement across options, widgets, and post meta — restoring on a different domain doesn't need a manual search-and-replace pass.

## Screenshots

| | |
|---|---|
| ![Backup tab](https://ps.w.org/restorepilot-backup-migration/assets/screenshot-1.png) Backup — create a full or custom backup, and download, check, restore, or delete existing backups. | ![Daily Backup tab](https://ps.w.org/restorepilot-backup-migration/assets/screenshot-2.png) Daily Backup — schedule one automatic backup a day, with an email on success or failure. |
| ![Restore tab](https://ps.w.org/restorepilot-backup-migration/assets/screenshot-3.png) Restore — from an uploaded zip or one already on the server, with automatic URL detection for migrations. | ![Restore confirmation](https://ps.w.org/restorepilot-backup-migration/assets/screenshot-4.png) Every restore is confirmed first — what will change, how to recover if it fails, and a required acknowledgement. |
| ![Status tab](https://ps.w.org/restorepilot-backup-migration/assets/screenshot-5.png) Status — system readiness, storage and rollback diagnostics, and safe maintenance tools. | ![Master Reset confirmation](https://ps.w.org/restorepilot-backup-migration/assets/screenshot-6.png) Destructive actions ask twice — Master Reset requires both a backup acknowledgement and typing RESET. |

## Installation

The supported way to install RestorePilot is through WordPress:

1. Go to **Plugins → Add New Plugin** in your WordPress admin.
2. Search for "RestorePilot".
3. Click **Install Now**, then **Activate**.

This repository is the plugin's development source, kept in sync with what's published on WordPress.org. If you'd rather install from here, copy `restorepilot-backup-migration.php`, `readme.txt`, `uninstall.php`, `assets/`, and `includes/` into `wp-content/plugins/restorepilot-backup-migration/` and activate it the normal way.

## Requirements

- WordPress 6.2 or later
- PHP 7.4 or later

## Feedback

Bug reports, feature requests, and general feedback are all welcome — [open an issue](https://github.com/srj2326/restorepilot/issues) here, or leave a review on the [WordPress.org support forum](https://wordpress.org/support/plugin/restorepilot-backup-migration/).

## License

GPLv2 or later — see [gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
