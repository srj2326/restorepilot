<?php
/**
 * Everything that renders admin markup.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

trait RestorePilot_AdminUi {
  public static function render_admin_page(): void {
    self::enable_error_logging();

    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have permission to access this page.', 'restorepilot-backup-migration'));
    }

    $notice = self::query_value('rp_notice');
    $error = self::query_value('rp_error');
    $tab = sanitize_key(self::query_value('tab', 'backup'));
    if (!in_array($tab, ['backup', 'daily', 'restore', 'logs', 'settings'], true)) {
      $tab = 'backup';
    }
    self::enforce_backup_retention();
    $backups = self::list_backups();
    $backup_file_items = self::list_backup_file_items();
    $settings = self::get_settings();
    $next_daily_backup = function_exists('wp_next_scheduled') ? wp_next_scheduled('restorepilot_scheduled_backup') : false;
    ?>
    <div class="wrap">
  
      <div class="rp-page">

      <?php
      /*
       * WordPress injects admin_notices from other plugins right after the
       * first <h1> inside .wrap. Placing a screen-reader-only <h1> here
       * keeps those notices above our visual header card instead of inside it.
       */
      ?>
      <h1 class="screen-reader-text"><?php echo esc_html__('RestorePilot', 'restorepilot-backup-migration'); ?></h1>

      <?php if ($notice): ?>
        <div class="notice notice-success is-dismissible rp-admin-notice"><p><?php echo esc_html($notice); ?></p></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="notice notice-error is-dismissible rp-admin-notice"><p><?php echo esc_html($error); ?></p></div>
      <?php endif; ?>

      <!-- Page header — title is a <div> (not <h1>) so it does not become a
           second notice-injection anchor and does not re-trigger the issue. -->
      <div class="rp-page-header">
        <div class="rp-page-header__logo" aria-hidden="true">
          <span class="dashicons dashicons-shield-alt"></span>
        </div>
        <div>
          <div class="rp-page-header__title" role="heading" aria-level="1">
            <?php echo esc_html__('RestorePilot', 'restorepilot-backup-migration'); ?>
          </div>
          <p class="rp-page-header__subtitle"><?php echo esc_html__('Backup, restore, and migrate WordPress sites with safe URL replacement.', 'restorepilot-backup-migration'); ?></p>
        </div>
        <span class="rp-page-header__badge">v<?php echo esc_html(self::VERSION); ?></span>
      </div>

      <!-- Tab nav -->
      <nav class="nav-tab-wrapper">
        <a class="nav-tab <?php echo esc_attr($tab === 'backup' ? 'nav-tab-active' : ''); ?>"
           href="<?php echo esc_url(add_query_arg('tab', 'backup', self::admin_url())); ?>"
           data-rp-tab="backup">
          <span class="dashicons dashicons-backup" aria-hidden="true"></span>
          <?php echo esc_html__('Backup', 'restorepilot-backup-migration'); ?>
        </a>
        <a class="nav-tab <?php echo esc_attr($tab === 'daily' ? 'nav-tab-active' : ''); ?>"
           href="<?php echo esc_url(add_query_arg('tab', 'daily', self::admin_url())); ?>"
           data-rp-tab="daily">
          <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
          <?php echo esc_html__('Daily Backup', 'restorepilot-backup-migration'); ?>
        </a>
        <a class="nav-tab <?php echo esc_attr($tab === 'restore' ? 'nav-tab-active' : ''); ?>"
           href="<?php echo esc_url(add_query_arg('tab', 'restore', self::admin_url())); ?>"
           data-rp-tab="restore">
          <span class="dashicons dashicons-upload" aria-hidden="true"></span>
          <?php echo esc_html__('Restore', 'restorepilot-backup-migration'); ?>
        </a>
        <a class="nav-tab <?php echo esc_attr($tab === 'logs' ? 'nav-tab-active' : ''); ?>"
           href="<?php echo esc_url(add_query_arg('tab', 'logs', self::admin_url())); ?>"
           data-rp-tab="logs">
          <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
          <?php echo esc_html__('Logs', 'restorepilot-backup-migration'); ?>
        </a>
        <a class="nav-tab <?php echo esc_attr($tab === 'settings' ? 'nav-tab-active' : ''); ?>"
           href="<?php echo esc_url(add_query_arg('tab', 'settings', self::admin_url())); ?>"
           data-rp-tab="settings">
          <span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
          <?php echo esc_html__('Status', 'restorepilot-backup-migration'); ?>
        </a>
      </nav>

      <section id="rp-panel-backup" class="rp-tab-panel <?php echo esc_attr($tab === 'backup' ? 'is-active' : ''); ?>">
      <div class="rp-stack">
      <?php if (is_multisite()): self::render_multisite_unsupported_notice(); else: ?>

        <!-- Create backup card -->
        <div class="rp-card">
          <div class="rp-card__head">
            <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-backup"></span></div>
            <h2><?php echo esc_html__('Create Backup', 'restorepilot-backup-migration'); ?></h2>
          </div>
          <div class="rp-card__body">
            <p><?php echo esc_html__('Create a complete backup of your database and files. Large zips are handed off to the web server for reliable downloads.', 'restorepilot-backup-migration'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="rp-backup-form">
              <?php wp_nonce_field(self::NONCE); ?>
              <input type="hidden" name="action" value="restorepilot_backup">
              <input type="hidden" name="include_files" value="1">
              <input type="hidden" name="file_selection_enabled" value="1">

              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:4px;">
                <button type="submit" class="button button-primary rp-button-with-icon" id="rp-backup-button">
                  <span class="dashicons dashicons-backup" aria-hidden="true"></span>
                  <span class="rp-button-label"><?php echo esc_html__('Create Backup', 'restorepilot-backup-migration'); ?></span>
                </button>
                <button type="button" class="button" id="rp-cancel-backup-button" style="display:none;">
                  <?php echo esc_html__('Cancel', 'restorepilot-backup-migration'); ?>
                </button>
              </div>

              <div class="rp-progress" id="rp-backup-progress" aria-live="polite">
                <div class="rp-progress__wrap">
                  <div class="rp-progress__header">
                    <span class="rp-progress__label" id="rp-backup-progress-label"><?php echo esc_html__('Backup in progress', 'restorepilot-backup-migration'); ?></span>
                    <span class="rp-progress__pct" id="rp-backup-progress-pct">0%</span>
                  </div>
                  <div class="rp-progress__track">
                    <div class="rp-progress__bar" id="rp-backup-progress-bar"></div>
                  </div>
                  <div class="rp-progress__text" id="rp-backup-progress-text">
                    <?php echo esc_html__('Starting…', 'restorepilot-backup-migration'); ?>
                  </div>
                </div>
              </div>

              <div class="rp-advanced rp-disclosure">
                <button type="button" class="rp-disclosure__summary" aria-expanded="false">
                  <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                  <?php echo esc_html__('Advanced file selection', 'restorepilot-backup-migration'); ?>
                </button>
                <div class="rp-disclosure__panel">
                  <div class="rp-disclosure__panel-inner">
                    <p style="margin-top:12px;font-size:13px;color:#646970;"><?php echo esc_html__('Choose which top-level wp-content folders and files to include alongside the database export.', 'restorepilot-backup-migration'); ?></p>
                    <div class="rp-file-tools">
                      <button type="button" class="button" id="rp-select-all-files"><?php echo esc_html__('Select all', 'restorepilot-backup-migration'); ?></button>
                      <button type="button" class="button" id="rp-clear-files"><?php echo esc_html__('Clear all', 'restorepilot-backup-migration'); ?></button>
                    </div>
                    <div class="rp-file-list">
                      <?php if (!$backup_file_items): ?>
                        <p style="margin:0;font-size:13px;color:#646970;"><?php echo esc_html__('No selectable wp-content items were found.', 'restorepilot-backup-migration'); ?></p>
                      <?php else: ?>
                        <?php foreach ($backup_file_items as $item): ?>
                          <label class="rp-file-item">
                            <input type="checkbox" name="backup_paths[]" value="<?php echo esc_attr($item['path']); ?>" checked>
                            <span><?php echo esc_html($item['label']); ?></span>
                          </label>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </form>
          </div>
        </div>

        <!-- Existing backups card -->
        <div class="rp-card">
          <div class="rp-card__head">
            <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-media-archive"></span></div>
            <h2><?php echo esc_html__('Existing Backups', 'restorepilot-backup-migration'); ?></h2>
          </div>
          <div class="rp-card__retention-notice">
            <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
            <?php echo esc_html(sprintf(
              /* translators: %1$s: sentence about the retention limit, %2$s: sentence about how many backups are stored now */
              __('%1$s %2$s Download backups you want to keep — older ones are removed automatically.', 'restorepilot-backup-migration'),
              sprintf(
                /* translators: %d: maximum number of backups kept */
                _n(
                  'Free version: keeps the newest %d backup total.',
                  'Free version: keeps the newest %d backups total.',
                  self::MAX_BACKUPS,
                  'restorepilot-backup-migration'
                ),
                self::MAX_BACKUPS
              ),
              sprintf(
                /* translators: %d: number of backups currently stored */
                _n(
                  'You currently have %d backup stored.',
                  'You currently have %d backups stored.',
                  count($backups),
                  'restorepilot-backup-migration'
                ),
                count($backups)
              )
            )); ?>
          </div>
          <?php if (!$backups): ?>
            <div class="rp-empty-state">
              <span class="dashicons dashicons-media-archive" aria-hidden="true"></span>
              <p><?php echo esc_html__('No backups yet. Create your first backup above.', 'restorepilot-backup-migration'); ?></p>
            </div>
          <?php else: ?>
            <table class="rp-backup-table rp-backup-table--selectable">
              <thead>
                <tr>
                  <th class="check-column"><input type="checkbox" id="rp-select-all-backups" aria-label="<?php echo esc_attr__('Select all backups', 'restorepilot-backup-migration'); ?>"></th>
                  <th><?php echo esc_html__('Backup date', 'restorepilot-backup-migration'); ?></th>
                  <th><?php echo esc_html__('Backup file', 'restorepilot-backup-migration'); ?></th>
                  <th><?php echo esc_html__('Actions', 'restorepilot-backup-migration'); ?></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($backups as $backup):
                $backup_abs_path = self::backup_dir() . '/' . $backup['name'];
              ?>
                <tr>
                  <th class="check-column">
                    <input type="checkbox" name="backup_ids[]" value="<?php echo esc_attr($backup['name']); ?>"
                           <?php /* translators: %s: backup file name */ ?>
                           aria-label="<?php echo esc_attr(sprintf(__('Select %s', 'restorepilot-backup-migration'), $backup['name'])); ?>">
                  </th>
                  <td class="rp-backup-date" data-rp-label="<?php echo esc_attr__('Backup date', 'restorepilot-backup-migration'); ?>">
                    <strong><?php echo esc_html(wp_date((string) get_option('date_format'), (int) $backup['modified'])); ?></strong>
                    <span><?php echo esc_html(wp_date((string) get_option('time_format'), (int) $backup['modified'])); ?> &mdash; <?php echo esc_html(size_format((int) $backup['size'])); ?></span>
                    <?php
                    $rp_triggered = isset($backup['triggered_by']) ? (string) $backup['triggered_by'] : 'manual';
                    $rp_type      = isset($backup['backup_type'])  ? (string) $backup['backup_type']  : 'full';
                    $rp_trigger_label = $rp_triggered === 'scheduled'
                      ? __('Auto', 'restorepilot-backup-migration')
                      : __('Manual', 'restorepilot-backup-migration');
                    $rp_type_label = $rp_type === 'database'
                      ? __('DB only', 'restorepilot-backup-migration')
                      : ($rp_type === 'selected-content'
                        ? __('Partial', 'restorepilot-backup-migration')
                        : __('Full', 'restorepilot-backup-migration'));
                    ?>
                    <span class="rp-backup-badges">
                      <span class="rp-badge rp-badge--<?php echo esc_attr($rp_triggered); ?>"><?php echo esc_html($rp_trigger_label); ?></span>
                      <span class="rp-badge rp-badge--type"><?php echo esc_html($rp_type_label); ?></span>
                    </span>
                  </td>
                  <td class="rp-backup-parts" data-rp-label="<?php echo esc_attr__('Backup file', 'restorepilot-backup-migration'); ?>">
                    <?php self::render_backup_download_controls($backup['name'], (int) $backup['size'], (int) ($backup['volumes'] ?? 1)); ?>
                  </td>
                  <td class="rp-backup-actions" data-rp-label="<?php echo esc_attr__('Actions', 'restorepilot-backup-migration'); ?>">
                    <a class="button"
                       href="<?php echo esc_url(self::action_url('restorepilot_health', $backup['name'])); ?>">
                      <?php echo esc_html__('Check', 'restorepilot-backup-migration'); ?>
                    </a>
                    <button type="button"
                            class="button rp-btn-restore rp-restore-from-existing"
                            data-backup-name="<?php echo esc_attr($backup['name']); ?>"
                            data-backup-path="<?php echo esc_attr($backup_abs_path); ?>">
                      <?php echo esc_html__('Restore', 'restorepilot-backup-migration'); ?>
                    </button>
                    <a class="button rp-btn-danger"
                       href="<?php echo esc_url(self::action_url('restorepilot_delete', $backup['name'])); ?>"
                       data-confirm="<?php echo esc_attr(__('Delete this backup? This cannot be undone.', 'restorepilot-backup-migration')); ?>">
                      <?php echo esc_html__('Delete', 'restorepilot-backup-migration'); ?>
                    </a>
                    <a class="button" href="<?php echo esc_url(add_query_arg('tab', 'logs', self::admin_url())); ?>">
                      <?php echo esc_html__('View Log', 'restorepilot-backup-migration'); ?>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>

            <!-- Restore-from-existing confirmation modal (shared, populated by JS) -->
            <div class="rp-modal-backdrop" id="rp-restore-existing-modal" role="dialog" aria-modal="true" aria-labelledby="rp-restore-existing-title" style="display:none;">
              <div class="rp-modal">
                <div class="rp-modal__head">
                  <div class="rp-modal__head-icon" aria-hidden="true"><span class="dashicons dashicons-warning"></span></div>
                  <h2 id="rp-restore-existing-title"><?php echo esc_html__('Restore Full Backup', 'restorepilot-backup-migration'); ?></h2>
                </div>
                <div class="rp-modal__body">
                  <div class="rp-modal__warning">
                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                    <?php echo esc_html__('This will permanently overwrite this site\'s live database and wp-content files. There is no automatic undo.', 'restorepilot-backup-migration'); ?>
                  </div>
                  <p style="margin:12px 0 4px;"><?php echo esc_html__('Restoring from:', 'restorepilot-backup-migration'); ?><br>
                     <strong id="rp-restore-existing-name" style="word-break:break-all;"></strong></p>
                  <div class="rp-modal__panel">
                    <strong><?php echo esc_html__('What happens next', 'restorepilot-backup-migration'); ?></strong>
                    <ul>
                      <li><?php echo esc_html__('The backup is validated — nothing changes until validation passes.', 'restorepilot-backup-migration'); ?></li>
                      <li><?php echo esc_html__('A rollback snapshot of the current database is saved before any tables are replaced.', 'restorepilot-backup-migration'); ?></li>
                      <li><?php echo esc_html__('The database is fully replaced with the backup. wp-content files from the backup overwrite matching files here — files that exist on this site but are not in the backup are not removed.', 'restorepilot-backup-migration'); ?></li>
                      <li><?php echo esc_html__('You will be asked to log in again — this is expected.', 'restorepilot-backup-migration'); ?></li>
                    </ul>
                  </div>
                  <div class="rp-modal__panel rp-modal__panel--warn">
                    <strong><?php echo esc_html__('⚠ If restore fails or the site becomes unreachable', 'restorepilot-backup-migration'); ?></strong>
                    <ul>
                      <li><?php echo esc_html__('RestorePilot removes maintenance mode automatically — but a partial restore may leave the database in a mixed state.', 'restorepilot-backup-migration'); ?></li>
                      <li><?php echo esc_html__('If you cannot log in after a failed restore, use the Pre-Restore Rollback Point to recover: go to Restore → Pre-Restore Rollback Points.', 'restorepilot-backup-migration'); ?></li>
                      <li><strong><?php echo esc_html__('Recommended: download a fresh backup and keep it somewhere safe before proceeding.', 'restorepilot-backup-migration'); ?></strong></li>
                    </ul>
                  </div>
                  <label class="rp-modal__confirm-check">
                    <input type="checkbox" id="rp-restore-existing-ack">
                    <?php echo esc_html__('I have a backup and understand this will overwrite my live database and matching wp-content files, and cannot be undone automatically.', 'restorepilot-backup-migration'); ?>
                  </label>
                </div>
                <div class="rp-modal__foot">
                  <button type="button" class="button" id="rp-restore-existing-cancel"><?php echo esc_html__('Cancel', 'restorepilot-backup-migration'); ?></button>
                  <form id="rp-restore-existing-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <?php wp_nonce_field(self::NONCE); ?>
                    <input type="hidden" name="action" value="restorepilot_restore">
                    <input type="hidden" name="confirm_restore" value="1">
                    <input type="hidden" name="restore_files" value="1">
                    <input type="hidden" name="auto_detect_urls" value="1">
                    <input type="hidden" name="server_backup_path" id="rp-restore-existing-path" value="">
                    <button type="submit" class="button button-primary" id="rp-restore-existing-submit" disabled><?php echo esc_html__('Yes, restore full backup', 'restorepilot-backup-migration'); ?></button>
                  </form>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>

      </div>
      <?php endif; ?>
      </section>

      <section id="rp-panel-daily" class="rp-tab-panel <?php echo esc_attr($tab === 'daily' ? 'is-active' : ''); ?>">
      <div class="rp-stack">
      <?php if (is_multisite()): self::render_multisite_unsupported_notice(); else: ?>

        <div class="rp-card">
          <div class="rp-card__head">
            <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-calendar-alt"></span></div>
            <h2><?php echo esc_html__('Daily Backup', 'restorepilot-backup-migration'); ?></h2>
          </div>
          <div class="rp-card__body">
            <p><?php echo esc_html__('Create one automatic backup every day using WP-Cron. Manual and daily backups share the same storage limit.', 'restorepilot-backup-migration'); ?></p>

            <div class="rp-info-block" style="margin-bottom:18px;">
              <strong><?php echo esc_html__('Free backup limit', 'restorepilot-backup-migration'); ?></strong>
              <?php /* translators: %d: maximum number of backups kept */ ?>
              <span><?php echo esc_html(sprintf(__('RestorePilot keeps the newest %d backups total across manual and daily backups. Older backups are deleted automatically.', 'restorepilot-backup-migration'), self::MAX_BACKUPS)); ?></span>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <?php wp_nonce_field(self::NONCE); ?>
              <input type="hidden" name="action" value="restorepilot_save_settings">
              <input type="hidden" name="redirect_tab" value="daily">

              <div class="rp-field">
                <label class="rp-toggle">
                  <input type="checkbox" name="scheduled_enabled" value="1" <?php checked(!empty($settings['scheduled_enabled'])); ?>>
                  <span>
                    <span class="rp-toggle__label"><?php echo esc_html__('Enable daily automatic backup', 'restorepilot-backup-migration'); ?></span>
                    <span class="rp-toggle__desc"><?php echo esc_html__('Runs once per day when WordPress cron is triggered by site traffic or server cron.', 'restorepilot-backup-migration'); ?></span>
                  </span>
                </label>
              </div>

              <div class="rp-field">
                <label class="rp-field__label"><?php echo esc_html__('Backup time', 'restorepilot-backup-migration'); ?></label>
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                  <select name="scheduled_hour" style="width:auto;">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                      <option value="<?php echo esc_attr((string) $h); ?>" <?php selected((int) $settings['scheduled_hour'], $h); ?>>
                        <?php echo esc_html(sprintf('%02d', $h)); ?>
                      </option>
                    <?php endfor; ?>
                  </select>
                  <span style="font-weight:600;font-size:15px;line-height:1;">:</span>
                  <select name="scheduled_minute" style="width:auto;">
                    <?php foreach ([0, 15, 30, 45] as $m): ?>
                      <option value="<?php echo esc_attr((string) $m); ?>" <?php selected((int) $settings['scheduled_minute'], $m); ?>>
                        <?php echo esc_html(sprintf('%02d', $m)); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <span class="description" style="margin:0;">
                    <?php
                    $tz_name = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
                    /* translators: %s: site timezone name */
                    echo esc_html(sprintf(__('Site timezone: %s', 'restorepilot-backup-migration'), $tz_name));
                    ?>
                  </span>
                </div>
                <span class="description"><?php echo esc_html__('The backup will start at this time each day. 02:00 is recommended (low traffic period).', 'restorepilot-backup-migration'); ?></span>
              </div>

              <div class="rp-field">
                <label class="rp-toggle">
                  <input type="checkbox" name="email_notifications" value="1" <?php checked(!empty($settings['email_notifications'])); ?>>
                  <span>
                    <span class="rp-toggle__label"><?php echo esc_html__('Email notifications', 'restorepilot-backup-migration'); ?></span>
                    <span class="rp-toggle__desc"><?php echo esc_html__('Receive an email after each daily backup succeeds, fails, or is skipped.', 'restorepilot-backup-migration'); ?></span>
                  </span>
                </label>
              </div>

              <div class="rp-field">
                <label class="rp-field__label" for="rp_notify_email"><?php echo esc_html__('Notification email', 'restorepilot-backup-migration'); ?></label>
                <input id="rp_notify_email" class="regular-text" type="email" name="notify_email"
                       value="<?php echo esc_attr($settings['notify_email']); ?>">
              </div>

              <div class="rp-info-block">
                <strong><?php echo esc_html__('Next scheduled run', 'restorepilot-backup-migration'); ?></strong>
                <span>
                  <?php
                  if ($next_daily_backup) {
                    echo esc_html(wp_date(
                      (string) get_option('date_format') . ' ' . (string) get_option('time_format'),
                      (int) $next_daily_backup
                    ));
                  } else {
                    echo esc_html__('Not scheduled. Enable daily backup and save settings.', 'restorepilot-backup-migration');
                  }
                  ?>
                </span>
              </div>

              <div style="margin-top:20px;">
                <button type="submit" class="button button-primary"><?php echo esc_html__('Save Daily Backup Settings', 'restorepilot-backup-migration'); ?></button>
              </div>
            </form>
          </div>
        </div>

        <?php self::render_existing_backups_card($backups, __('No backups yet. Daily and manual backups will appear here.', 'restorepilot-backup-migration')); ?>

      </div>
      <?php endif; ?>
      </section>

      <section id="rp-panel-restore" class="rp-tab-panel <?php echo esc_attr($tab === 'restore' ? 'is-active' : ''); ?>">
      <div class="rp-stack">
      <?php if (is_multisite()): self::render_multisite_unsupported_notice(); else: ?>

        <!-- Restore card -->
        <div class="rp-card">
          <div class="rp-card__head">
            <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-upload"></span></div>
            <h2><?php echo esc_html__('Restore Full Backup', 'restorepilot-backup-migration'); ?></h2>
          </div>
          <div class="rp-card__body">
            <p><?php echo esc_html__('Upload the full RestorePilot backup zip downloaded from Existing Backups. The file is validated before any changes are made to this site.', 'restorepilot-backup-migration'); ?></p>

            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="rp-restore-form">
              <?php wp_nonce_field(self::NONCE); ?>
              <input type="hidden" name="action" id="rp_restore_action" value="restorepilot_restore">
              <input type="hidden" name="auto_detect_urls" value="1">
              <input type="hidden" name="restore_files" value="1">
              <input type="hidden" name="confirm_restore" value="1">
              <!-- Set from the pre-restore confirmation modal's own checkbox, which
                   lives outside this form in the DOM — see admin.js. -->
              <input type="hidden" name="create_new_admin" id="rp_create_new_admin_hidden" value="0">
              <!-- Copied from the modal the same way. Neither is a secret, so
                   both travel with the job; the chosen password deliberately
                   does not — see the modal's own comment. -->
              <input type="hidden" name="new_admin_email" id="rp_new_admin_email_hidden" value="">

              <div class="rp-upload-box">
                <span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span>
                <label for="rp_file"><?php echo esc_html__('Choose full backup zip', 'restorepilot-backup-migration'); ?></label>
                <input id="rp_file" type="file" name="backup_upload[]" multiple
                       data-max-upload-size="<?php echo esc_attr((string) wp_max_upload_size()); ?>">
              </div>

              <div class="rp-restore-actions is-waiting">
                <button type="submit" class="button rp-restore-check rp-button-with-icon" data-rp-action="restorepilot_check_restore" disabled>
                  <span class="dashicons dashicons-search" aria-hidden="true"></span>
                  <span class="rp-button-label"><?php echo esc_html__('Check Backup', 'restorepilot-backup-migration'); ?></span>
                </button>
                <button type="submit" class="button button-primary rp-restore-submit rp-button-with-icon" data-rp-action="restorepilot_restore" disabled>
                  <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                  <span class="rp-button-label"><?php echo esc_html__('Restore Full Backup', 'restorepilot-backup-migration'); ?></span>
                </button>
                <span class="description rp-restore-ready-hint"><?php echo esc_html__('Choose a backup zip or enter a server backup path to continue.', 'restorepilot-backup-migration'); ?></span>
              </div>

              <div class="rp-progress" id="rp-restore-progress" aria-live="polite">
                <div class="rp-progress__wrap">
                  <div class="rp-progress__header">
                    <span class="rp-progress__label" id="rp-restore-progress-label"><?php echo esc_html__('Uploading…', 'restorepilot-backup-migration'); ?></span>
                    <span class="rp-progress__pct" id="rp-restore-progress-pct">0%</span>
                  </div>
                  <div class="rp-progress__track">
                    <div class="rp-progress__bar" id="rp-restore-progress-bar"></div>
                  </div>
                  <div class="rp-progress__text" id="rp-restore-progress-text">
                    <?php echo esc_html__('Preparing restore…', 'restorepilot-backup-migration'); ?>
                  </div>
                </div>
              </div>

              <?php
              // There is deliberately no panel here for showing a login. The
              // plugin no longer generates a credential, so it never has one
              // to hand back: the operator sets the email and password
              // themselves, and the page can simply carry on to the restore
              // tab when it is done. Every problem that panel used to cause —
              // a password shown once and missed, a page that could not
              // redirect without destroying it, a live credential sitting on
              // a screen people photograph — went away with the thing that
              // made it necessary.
              ?>

              <div class="rp-advanced rp-disclosure">
                <button type="button" class="rp-disclosure__summary" aria-expanded="false">
                  <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                  <?php echo esc_html__('Advanced restore settings', 'restorepilot-backup-migration'); ?>
                </button>
                <div class="rp-disclosure__panel">
                  <div class="rp-disclosure__panel-inner">
                    <div class="rp-advanced-panel">

                      <div class="rp-advanced-panel__section">
                        <label class="rp-toggle">
                          <input id="rp_auto_urls" type="checkbox" checked>
                          <span>
                            <span class="rp-toggle__label"><?php echo esc_html__('Auto-detect source and target URLs', 'restorepilot-backup-migration'); ?></span>
                            <span class="rp-toggle__desc"><?php echo esc_html__('Reads the original URL from the backup manifest and replaces it with this site\'s URL.', 'restorepilot-backup-migration'); ?></span>
                          </span>
                        </label>
                        <div id="rp_manual_urls" style="display:none;gap:12px;margin-top:12px;padding-top:12px;border-top:1px solid #dcdcde;">
                          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="rp-field">
                              <label class="rp-field__label" for="rp_source"><?php echo esc_html__('Source URL', 'restorepilot-backup-migration'); ?></label>
                              <input id="rp_source" class="regular-text" type="url" name="source_url" placeholder="https://old-domain.com">
                            </div>
                            <div class="rp-field">
                              <label class="rp-field__label" for="rp_target"><?php echo esc_html__('Target URL', 'restorepilot-backup-migration'); ?></label>
                              <input id="rp_target" class="regular-text" type="url" name="target_url" value="<?php echo esc_attr(home_url()); ?>">
                            </div>
                          </div>
                        </div>
                      </div>

                      <div class="rp-advanced-panel__section">
                        <label class="rp-field__label" for="rp_server_backup_path"><?php echo esc_html__('Server backup path', 'restorepilot-backup-migration'); ?></label>
                        <input id="rp_server_backup_path" class="regular-text" type="text" name="server_backup_path" placeholder="wp-content/uploads/backup.zip">
                        <span class="description"><?php echo esc_html__('Only needed if the full backup zip is already sitting inside this site\'s uploads directory (for example, placed there via your host\'s file manager) — this skips uploading it again.', 'restorepilot-backup-migration'); ?></span>
                      </div>

                    </div>
                  </div>
                </div>
              </div>
            </form>
          </div>
        </div>

        <?php
        $rp_rollback_points = self::list_restore_rollback_points();
        if (!empty($rp_rollback_points)):
        ?>
        <div class="rp-card" id="rp-rollback-card">
          <div class="rp-card__head">
            <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-backup"></span></div>
            <h2><?php echo esc_html__('Pre-Restore Rollback Points', 'restorepilot-backup-migration'); ?></h2>
          </div>
          <div class="rp-card__retention-notice">
            <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
            <?php echo esc_html__('Snapshots taken automatically before each restore. Use one if a restore left the site in a broken state. Database only — files are not included.', 'restorepilot-backup-migration'); ?>
          </div>
          <table class="rp-backup-table rp-backup-table--plain rp-backup-table--2col">
            <thead>
              <tr>
                <th><?php echo esc_html__('Created', 'restorepilot-backup-migration'); ?></th>
                <th><?php echo esc_html__('Actions', 'restorepilot-backup-migration'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rp_rollback_points as $rp_point): ?>
              <tr>
                <td class="rp-backup-date" data-rp-label="<?php echo esc_attr__('Created', 'restorepilot-backup-migration'); ?>">
                  <strong><?php echo esc_html(wp_date((string) get_option('date_format'), $rp_point['modified'])); ?></strong>
                  <span><?php echo esc_html(wp_date((string) get_option('time_format'), $rp_point['modified'])); ?> &mdash; <?php echo esc_html(size_format($rp_point['size'])); ?></span>
                  <span class="rp-backup-badges">
                    <span class="rp-badge rp-badge--type"><?php echo esc_html__('DB only', 'restorepilot-backup-migration'); ?></span>
                    <span class="rp-badge rp-badge--scheduled"><?php echo esc_html__('Auto', 'restorepilot-backup-migration'); ?></span>
                  </span>
                </td>
                <td class="rp-backup-actions" data-rp-label="<?php echo esc_attr__('Actions', 'restorepilot-backup-migration'); ?>">
                  <button type="button" class="button rp-btn-restore rp-rollback-restore-btn"
                    data-rp-rollback-path="<?php echo esc_attr($rp_point['path']); ?>">
                    <?php echo esc_html__('Restore from this point', 'restorepilot-backup-migration'); ?>
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <!-- Pre-restore confirmation modal -->
        <div class="rp-modal-backdrop" id="rp-restore-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="rp-restore-confirm-title">
          <div class="rp-modal">
            <div class="rp-modal__head">
              <div class="rp-modal__head-icon" aria-hidden="true">
                <span class="dashicons dashicons-warning"></span>
              </div>
              <h2 id="rp-restore-confirm-title"><?php echo esc_html__('Before Restore Starts', 'restorepilot-backup-migration'); ?></h2>
            </div>
            <div class="rp-modal__body">
              <div class="rp-modal__warning">
                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                <?php echo esc_html__('This will permanently overwrite this site\'s live database and wp-content files. There is no automatic undo.', 'restorepilot-backup-migration'); ?>
              </div>
              <div class="rp-modal__panel">
                <strong><?php echo esc_html__('What happens next', 'restorepilot-backup-migration'); ?></strong>
                <ul>
                  <li><?php echo esc_html__('The backup is validated — nothing changes until validation passes.', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('A rollback snapshot of the current database is saved before any tables are replaced.', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('The database is fully replaced with the backup. wp-content files from the backup overwrite matching files here — files that exist on this site but are not in the backup are not removed.', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('You will be asked to log in again — this is expected.', 'restorepilot-backup-migration'); ?></li>
                </ul>
              </div>
              <div class="rp-modal__panel rp-modal__panel--warn">
                <strong><?php echo esc_html__('⚠ If restore fails or the site becomes unreachable', 'restorepilot-backup-migration'); ?></strong>
                <ul>
                  <li><?php echo esc_html__('RestorePilot removes maintenance mode automatically — but a partial restore may leave the database in a mixed state.', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('If you cannot log in after a failed restore, use the Pre-Restore Rollback Point to recover: go to Restore → Pre-Restore Rollback Points.', 'restorepilot-backup-migration'); ?></li>
                  <li><strong><?php echo esc_html__('Recommended: download a fresh backup and keep it somewhere safe before proceeding.', 'restorepilot-backup-migration'); ?></strong></li>
                </ul>
              </div>
              <div class="rp-modal__panel">
                <label class="rp-toggle">
                  <input type="checkbox" id="rp-restore-confirm-new-admin">
                  <span>
                    <span class="rp-toggle__label"><?php echo esc_html__('Create a new admin login for this site', 'restorepilot-backup-migration'); ?></span>
                    <span class="rp-toggle__desc"><?php echo esc_html__('Useful when restoring a backup from a different domain and you don\'t have (or don\'t want to reuse) that site\'s admin password. Adds a new administrator account, with an email and password you choose, instead of changing any existing one.', 'restorepilot-backup-migration'); ?></span>
                  </span>
                </label>

                <div class="rp-new-admin-fields" id="rp-new-admin-fields" hidden>
                  <p class="rp-new-admin-fields__intro">
                    <?php echo esc_html__('You will sign in with this email address and password.', 'restorepilot-backup-migration'); ?>
                  </p>

                  <?php
                  // Both fields are required, and nothing is generated on the
                  // operator's behalf. A generated credential is one the
                  // plugin has to hand back, which means displaying it, which
                  // means it can be missed, screenshotted, or lost by
                  // navigating away — every one of which has to be designed
                  // around. A credential the operator already knows removes
                  // that whole problem rather than managing it.
                  //
                  // The email travels with the restore: it is not a secret,
                  // and having it on the job means the account is created even
                  // if this browser never comes back, leaving WordPress's own
                  // password reset as a way in. The password does not travel
                  // — it stays here and is applied in one call once the
                  // restore is done, so it is never written to the job record,
                  // which is mirrored to a file under uploads to survive the
                  // database swap.
                  ?>
                  <p class="rp-field">
                    <label for="rp-new-admin-email-input"><?php echo esc_html__('Email address', 'restorepilot-backup-migration'); ?></label>
                    <input type="email" id="rp-new-admin-email-input" autocomplete="off" spellcheck="false" required
                      placeholder="you@example.com">
                    <span class="rp-field__hint"><?php echo esc_html__('Use an address you can actually receive mail at — it is how you recover this account if anything goes wrong.', 'restorepilot-backup-migration'); ?></span>
                  </p>

                  <p class="rp-field">
                    <label for="rp-new-admin-password-input"><?php echo esc_html__('Password', 'restorepilot-backup-migration'); ?></label>
                    <input type="password" id="rp-new-admin-password-input" autocomplete="new-password" spellcheck="false" required
                      placeholder="<?php echo esc_attr__('At least 8 characters', 'restorepilot-backup-migration'); ?>">
                    <span class="rp-field__hint"><?php echo esc_html__('Never stored on the server during the restore — it is applied from this page once the restore finishes.', 'restorepilot-backup-migration'); ?></span>
                  </p>

                  <div class="rp-field__error" id="rp-new-admin-error" hidden></div>
                </div>
              </div>
              <label class="rp-modal__confirm-check">
                <input type="checkbox" id="rp-restore-confirm-check">
                <?php echo esc_html__('I have a backup and understand this will overwrite my live database and matching wp-content files, and cannot be undone automatically.', 'restorepilot-backup-migration'); ?>
              </label>
            </div>
            <div class="rp-modal__foot">
              <button type="button" class="button" id="rp-restore-confirm-cancel"><?php echo esc_html__('Go back', 'restorepilot-backup-migration'); ?></button>
              <button type="button" class="button button-primary" id="rp-restore-confirm-continue" disabled><?php echo esc_html__('Yes, start restore', 'restorepilot-backup-migration'); ?></button>
            </div>
          </div>
        </div>

        <?php if (strpos($notice, 'Restore completed') !== false): ?>
          <div class="rp-card">
            <div class="rp-card__head">
              <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-yes-alt"></span></div>
              <h2><?php echo esc_html__('Post-Restore Checklist', 'restorepilot-backup-migration'); ?></h2>
            </div>
            <div class="rp-card__body">
              <ul class="rp-checklist">
                <li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php echo esc_html__('Visit the front of the site and check important pages.', 'restorepilot-backup-migration'); ?></li>
                <li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php echo esc_html__('Log in again if WordPress prompts you.', 'restorepilot-backup-migration'); ?></li>
                <li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php echo esc_html__('Reconnect SMTP, SEO, cache, or license-based plugins if they show notices.', 'restorepilot-backup-migration'); ?></li>
              </ul>
            </div>
          </div>
        <?php endif; ?>

      </div>
      <?php endif; ?>
      </section>

      <section id="rp-panel-settings" class="rp-tab-panel <?php echo esc_attr($tab === 'settings' ? 'is-active' : ''); ?>">
      <?php $rp_backup_tab_url = esc_url(add_query_arg('tab', 'backup', self::admin_url())); ?>
      <div class="rp-settings-layout">

        <!-- Main column: Diagnostics & Maintenance -->
        <div class="rp-settings-col rp-settings-col--main">
          <div class="rp-card">
            <div class="rp-card__head">
              <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-admin-tools"></span></div>
              <h2><?php echo esc_html__('Diagnostics & Maintenance', 'restorepilot-backup-migration'); ?></h2>
            </div>
            <div class="rp-card__body">
              <div class="rp-status-list rp-status-list--grid rp-diagnostics-list">
                <?php foreach (self::diagnostic_status_items($backups) as $item):
                  $s = isset($item['status']) ? $item['status'] : 'info';
                  $icon = $s === 'ok' ? 'yes' : ($s === 'error' ? 'no' : ($s === 'warn' ? 'warning' : 'minus'));
                ?>
                  <div class="rp-status rp-status--<?php echo esc_attr($s); ?>">
                    <div class="rp-status__dot" aria-hidden="true">
                      <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
                    </div>
                    <div class="rp-status__body">
                      <div class="rp-status__name"><?php echo esc_html($item['label']); ?></div>
                      <div class="rp-status__value"><?php echo esc_html($item['value']); ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>

              <hr class="rp-divider">

              <div class="rp-info-block">
                <strong><?php echo esc_html__('Safe maintenance tools', 'restorepilot-backup-migration'); ?></strong>
                <span><?php echo esc_html__('These tools do not delete completed backup zips. They only clean stale temporary files or reset stuck runtime state.', 'restorepilot-backup-migration'); ?></span>
              </div>
              <div class="rp-maintenance-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                  <?php wp_nonce_field(self::NONCE); ?>
                  <input type="hidden" name="action" value="restorepilot_cleanup_temp">
                  <button type="submit" class="button rp-button-with-icon">
                    <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                    <span class="rp-button-label"><?php echo esc_html__('Clean Stale Temp Files', 'restorepilot-backup-migration'); ?></span>
                  </button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                  <?php wp_nonce_field(self::NONCE); ?>
                  <input type="hidden" name="action" value="restorepilot_reset_runtime">
                  <button type="submit" class="button rp-button-with-icon" data-confirm="<?php echo esc_attr(__('Reset stuck RestorePilot locks and remove maintenance mode? Do this only if no backup or restore is currently running.', 'restorepilot-backup-migration')); ?>">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <span class="rp-button-label"><?php echo esc_html__('Reset Stuck Runtime', 'restorepilot-backup-migration'); ?></span>
                  </button>
                </form>
                <a class="button rp-button-with-icon" href="<?php echo esc_url(self::admin_action_url('restorepilot_download_log')); ?>">
                  <span class="dashicons dashicons-download" aria-hidden="true"></span>
                  <span class="rp-button-label"><?php echo esc_html__('Download Logs', 'restorepilot-backup-migration'); ?></span>
                </a>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                  <?php wp_nonce_field(self::NONCE); ?>
                  <input type="hidden" name="action" value="restorepilot_clear_log_post">
                  <button type="submit" class="button rp-button-with-icon" data-confirm="<?php echo esc_attr(__('Clear RestorePilot logs?', 'restorepilot-backup-migration')); ?>">
                    <span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
                    <span class="rp-button-label"><?php echo esc_html__('Clear Logs', 'restorepilot-backup-migration'); ?></span>
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>

        <!-- Side column: System Readiness, Status, Danger Zone -->
        <div class="rp-settings-col rp-settings-col--side">

          <!-- System readiness card -->
          <div class="rp-card">
            <div class="rp-card__head">
              <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-dashboard"></span></div>
              <h2><?php echo esc_html__('System Readiness', 'restorepilot-backup-migration'); ?></h2>
            </div>
            <div class="rp-card__body">
              <div class="rp-status-list rp-status-list--grid">
                <?php foreach (self::system_status_items() as $item):
                  $s = isset($item['status']) ? $item['status'] : 'info';
                  $icon = $s === 'ok' ? 'yes' : ($s === 'error' ? 'no' : ($s === 'warn' ? 'warning' : 'minus'));
                ?>
                  <div class="rp-status rp-status--<?php echo esc_attr($s); ?>">
                    <div class="rp-status__dot" aria-hidden="true">
                      <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
                    </div>
                    <div class="rp-status__body">
                      <div class="rp-status__name"><?php echo esc_html($item['label']); ?></div>
                      <div class="rp-status__value"><?php echo esc_html($item['value']); ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Status card -->
          <div class="rp-card">
            <div class="rp-card__head">
              <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-admin-settings"></span></div>
              <h2><?php echo esc_html__('Status', 'restorepilot-backup-migration'); ?></h2>
            </div>
            <div class="rp-card__body rp-card__body--compact">
              <div class="rp-info-block">
                <strong><?php echo esc_html__('Backup limit', 'restorepilot-backup-migration'); ?></strong>
                <?php /* translators: %d: maximum number of backups kept */ ?>
                <span><?php echo esc_html(sprintf(__('RestorePilot keeps the newest %d backups total in this free version. Manual and daily backups share this limit.', 'restorepilot-backup-migration'), self::MAX_BACKUPS)); ?></span>
              </div>
              <div class="rp-info-block">
                <strong><?php echo esc_html__('Daily backups', 'restorepilot-backup-migration'); ?></strong>
                <span><?php echo esc_html__('Use the Daily Backup tab to enable automatic daily backups and email notifications.', 'restorepilot-backup-migration'); ?></span>
              </div>
              <div class="rp-info-block">
                <strong><?php echo esc_html__('Cleanup on uninstall', 'restorepilot-backup-migration'); ?></strong>
                <span><?php echo esc_html__('Deleting the plugin removes all RestorePilot backups, logs, temporary files, options, and scheduled events.', 'restorepilot-backup-migration'); ?></span>
              </div>
            </div>
          </div>

          <!-- Master Reset danger zone -->
          <div class="rp-card rp-card--danger rp-danger-zone">
            <div class="rp-card__head rp-card__head--danger">
              <div class="rp-card__head-icon rp-card__head-icon--danger" aria-hidden="true"><span class="dashicons dashicons-warning"></span></div>
              <h2><?php echo esc_html__('Danger Zone — Master Reset', 'restorepilot-backup-migration'); ?></h2>
            </div>
            <div class="rp-card__body rp-danger-body">
              <div class="rp-danger-body__text">
                <p class="rp-danger-intro"><?php echo esc_html__('Permanently wipe this site back to a clean WordPress installation. Everything listed below is deleted and cannot be recovered.', 'restorepilot-backup-migration'); ?></p>
                <ul class="rp-danger-list">
                  <li><?php echo esc_html__('All posts, pages, custom post types, and taxonomy terms', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('All uploaded media files (wp-content/uploads) — except RestorePilot\'s own stored backups, rollback points, and log, which this reset keeps', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('All plugins except RestorePilot', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('All themes except Twenty Twenty-Five', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('All user accounts except the current administrator', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('All plugin settings and site customisations', 'restorepilot-backup-migration'); ?></li>
                  <li><?php echo esc_html__('All database tables created by other plugins, and everything stored in them (form entries, orders, logs, and anything else a plugin kept in its own tables)', 'restorepilot-backup-migration'); ?></li>
                </ul>
              </div>
              <div class="rp-danger-body__action">
                <?php if (is_multisite()): ?>
                  <span class="rp-danger-hint"><?php echo esc_html__('Master Reset is not available on multisite networks, because plugins and themes are shared across all sites.', 'restorepilot-backup-migration'); ?></span>
                <?php else: ?>
                  <button type="button" id="rp-master-reset-open" class="button rp-button-danger rp-button-with-icon">
                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                    <span class="rp-button-label"><?php echo esc_html__('Master Reset…', 'restorepilot-backup-migration'); ?></span>
                  </button>
                  <span class="rp-danger-hint"><?php echo esc_html__('You will be asked to confirm.', 'restorepilot-backup-migration'); ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>

        </div>
      </div>

      <!-- Master Reset confirmation modal -->
      <div id="rp-master-reset-modal" class="rp-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="rp-master-reset-title">
        <div class="rp-modal rp-modal--danger">
          <div class="rp-modal__head">
            <div class="rp-modal__head-icon rp-modal__head-icon--danger" aria-hidden="true">
              <span class="dashicons dashicons-warning"></span>
            </div>
            <h2 id="rp-master-reset-title"><?php echo esc_html__('Master Reset — this cannot be undone', 'restorepilot-backup-migration'); ?></h2>
          </div>
          <div class="rp-modal__body">
            <div class="rp-modal__backup-prompt">
              <span class="dashicons dashicons-download" aria-hidden="true"></span>
              <div class="rp-modal__backup-prompt-text">
                <strong><?php echo esc_html__('Create and download a full backup first', 'restorepilot-backup-migration'); ?></strong>
                <span><?php echo esc_html__('A backup is the only way to recover this site after a reset. Download one now if you have not already.', 'restorepilot-backup-migration'); ?></span>
                <a class="button button-primary rp-modal__backup-btn" href="<?php echo esc_url($rp_backup_tab_url); ?>">
                  <span class="dashicons dashicons-backup" aria-hidden="true"></span>
                  <?php echo esc_html__('Go to Backup tab', 'restorepilot-backup-migration'); ?>
                </a>
              </div>
            </div>
            <label class="rp-modal__ack">
              <input type="checkbox" id="rp-master-reset-ack">
              <span><?php echo esc_html__('I have a full backup, or I understand this site cannot be recovered.', 'restorepilot-backup-migration'); ?></span>
            </label>
            <div class="rp-modal__warning">
              <span class="dashicons dashicons-warning" aria-hidden="true"></span>
              <span><?php echo esc_html__('You are about to permanently delete all site content, uploads, plugins, themes, and user accounts. Only the current administrator account will be kept. This action cannot be reversed.', 'restorepilot-backup-migration'); ?></span>
            </div>
            <p class="rp-modal__confirm-prompt"><?php echo esc_html__('Type RESET in the box below to confirm:', 'restorepilot-backup-migration'); ?></p>
            <input type="text" id="rp-master-reset-confirm-input" class="regular-text rp-master-reset-input" placeholder="RESET" autocomplete="off" autocorrect="off" spellcheck="false">
            <div id="rp-master-reset-error" class="rp-master-reset-error" style="display:none;"></div>
          </div>
          <div class="rp-modal__foot">
            <button type="button" id="rp-master-reset-cancel" class="button"><?php echo esc_html__('Cancel', 'restorepilot-backup-migration'); ?></button>
            <button type="button" id="rp-master-reset-confirm" class="button rp-button-danger" disabled>
              <span class="dashicons dashicons-warning" aria-hidden="true"></span>
              <?php echo esc_html__('Reset Everything', 'restorepilot-backup-migration'); ?>
            </button>
          </div>
        </div>
      </div>

      </section>
      <section id="rp-panel-logs" class="rp-tab-panel <?php echo esc_attr($tab === 'logs' ? 'is-active' : ''); ?>">
        <?php self::render_logs_tab(); ?>
      </section>
      </div>
    </div>
    <?php
  }

  /**
   * $volume_count > 1 means this backup was split (see BACKUP_VOLUME_BYTES) —
   * serve_download() only ever streams the ONE file it is given, so a "Download
   * Full Backup" link built from the base filename alone would silently
   * deliver just the first volume of a large site's backup. list_backups()
   * already computes the true volume count for exactly this reason; every
   * caller must pass it through rather than defaulting to 1.
   */
  private static function render_backup_download_controls(string $backup_name, int $backup_size = 0, int $volume_count = 1): void {
    $backup_name = sanitize_file_name($backup_name);
    $backup_size_label = $backup_size > 0 ? size_format($backup_size) : '';
    $dl_full = self::action_url('restorepilot_download', $backup_name);
    $dl_db   = self::admin_action_url('restorepilot_download_part', ['file' => $backup_name, 'part' => 'database']);
    $dl_plg  = self::admin_action_url('restorepilot_download_part', ['file' => $backup_name, 'part' => 'plugins']);
    $dl_thm  = self::admin_action_url('restorepilot_download_part', ['file' => $backup_name, 'part' => 'themes']);
    $dl_upl  = self::admin_action_url('restorepilot_download_part', ['file' => $backup_name, 'part' => 'uploads']);
    $dl_mu   = self::admin_action_url('restorepilot_download_part', ['file' => $backup_name, 'part' => 'mu-plugins']);
    $dl_oth  = self::admin_action_url('restorepilot_download_part', ['file' => $backup_name, 'part' => 'others']);
    ?>
    <div class="rp-backup-primary">
      <a class="button button-primary rp-button-with-icon" href="<?php echo esc_url($dl_full); ?>">
        <span class="dashicons dashicons-download" aria-hidden="true"></span>
        <span class="rp-button-label"><?php echo esc_html__('Download Full Backup', 'restorepilot-backup-migration'); ?></span>
        <?php if ($backup_size_label !== ''): ?>
          <span class="rp-button-size"><?php echo esc_html($backup_size_label); ?></span>
        <?php endif; ?>
      </a>
      <span class="rp-full-backup-hint"><?php echo esc_html__('Use this zip to restore or migrate the full site.', 'restorepilot-backup-migration'); ?></span>

      <?php if ($volume_count > 1):
        $volume_paths = self::volume_paths_for(self::backup_dir() . '/' . $backup_name);
      ?>
      <div class="rp-advanced-downloads rp-disclosure">
        <button type="button" class="rp-disclosure__summary" aria-expanded="false">
          <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
          <?php echo esc_html__('Download volumes individually', 'restorepilot-backup-migration'); ?>
        </button>
        <div class="rp-disclosure__panel">
          <div class="rp-disclosure__panel-inner">
            <p class="rp-part-note"><?php echo esc_html(sprintf(
              /* translators: %d: number of volumes this backup is split into */
              __('This backup is stored as %d files behind the scenes. Download Full Backup above reassembles them into one file automatically — use these only if that download fails partway and you need to retry a single piece.', 'restorepilot-backup-migration'),
              $volume_count
            )); ?></p>
            <div class="rp-part-list">
              <?php foreach ($volume_paths as $i => $volume_path):
                $volume_name = basename($volume_path);
                $volume_size = @filesize($volume_path);
                $dl_volume = self::action_url('restorepilot_download_stream', $volume_name);
              ?>
                <a class="rp-part-btn" href="<?php echo esc_url($dl_volume); ?>"><?php echo esc_html(sprintf(
                  /* translators: 1: this volume's position, 2: total number of volumes */
                  __('Volume %1$d of %2$d', 'restorepilot-backup-migration'),
                  $i + 1,
                  $volume_count
                )); ?><?php if ($volume_size !== false && $volume_size > 0): ?> (<?php echo esc_html(size_format((int) $volume_size)); ?>)<?php endif; ?></a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="rp-advanced-downloads rp-disclosure">
        <button type="button" class="rp-disclosure__summary" aria-expanded="false">
          <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
          <?php echo esc_html__('Advanced downloads', 'restorepilot-backup-migration'); ?>
        </button>
        <div class="rp-disclosure__panel">
          <div class="rp-disclosure__panel-inner">
            <p class="rp-part-note"><?php echo esc_html__('Individual archives are for manual recovery only. Use Download Full Backup for restore or migration.', 'restorepilot-backup-migration'); ?></p>
            <div class="rp-part-list">
              <a class="rp-part-btn" href="<?php echo esc_url($dl_db); ?>"><?php echo esc_html__('Database only', 'restorepilot-backup-migration'); ?></a>
              <a class="rp-part-btn" href="<?php echo esc_url($dl_plg); ?>"><?php echo esc_html__('Plugins only', 'restorepilot-backup-migration'); ?></a>
              <a class="rp-part-btn" href="<?php echo esc_url($dl_thm); ?>"><?php echo esc_html__('Themes only', 'restorepilot-backup-migration'); ?></a>
              <a class="rp-part-btn" href="<?php echo esc_url($dl_upl); ?>"><?php echo esc_html__('Uploads only', 'restorepilot-backup-migration'); ?></a>
              <a class="rp-part-btn" href="<?php echo esc_url($dl_mu); ?>"><?php echo esc_html__('Must-use plugins', 'restorepilot-backup-migration'); ?></a>
              <a class="rp-part-btn" href="<?php echo esc_url($dl_oth); ?>"><?php echo esc_html__('Other wp-content files', 'restorepilot-backup-migration'); ?></a>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php
  }

  private static function render_multisite_unsupported_notice(): void {
    ?>
    <div class="rp-card">
      <div class="rp-empty-state">
        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
        <p><?php echo esc_html(self::multisite_unsupported_message()); ?></p>
      </div>
    </div>
    <?php
  }

  private static function render_existing_backups_card(array $backups, string $empty_message): void {
    ?>
    <div class="rp-card">
      <div class="rp-card__head">
        <div class="rp-card__head-icon" aria-hidden="true"><span class="dashicons dashicons-media-archive"></span></div>
        <h2><?php echo esc_html__('Existing Backups', 'restorepilot-backup-migration'); ?></h2>
      </div>
      <div class="rp-card__retention-notice">
        <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
        <?php echo esc_html(sprintf(
          /* translators: %1$s: sentence about the retention limit, %2$s: sentence about how many backups are stored now */
          __('%1$s %2$s Download backups you want to keep — older ones are removed automatically.', 'restorepilot-backup-migration'),
          sprintf(
            /* translators: %d: maximum number of backups kept */
            _n(
              'Free version: keeps the newest %d backup total.',
              'Free version: keeps the newest %d backups total.',
              self::MAX_BACKUPS,
              'restorepilot-backup-migration'
            ),
            self::MAX_BACKUPS
          ),
          sprintf(
            /* translators: %d: number of backups currently stored */
            _n(
              'You currently have %d backup stored.',
              'You currently have %d backups stored.',
              count($backups),
              'restorepilot-backup-migration'
            ),
            count($backups)
          )
        )); ?>
      </div>
      <?php if (!$backups): ?>
        <div class="rp-empty-state">
          <span class="dashicons dashicons-media-archive" aria-hidden="true"></span>
          <p><?php echo esc_html($empty_message); ?></p>
        </div>
      <?php else: ?>
        <table class="rp-backup-table rp-backup-table--plain">
          <thead>
            <tr>
              <th><?php echo esc_html__('Backup date', 'restorepilot-backup-migration'); ?></th>
              <th><?php echo esc_html__('Backup file', 'restorepilot-backup-migration'); ?></th>
              <th><?php echo esc_html__('Actions', 'restorepilot-backup-migration'); ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($backups as $backup):
            $backup_abs_path = self::backup_dir() . '/' . $backup['name'];
          ?>
            <tr>
              <td class="rp-backup-date" data-rp-label="<?php echo esc_attr__('Backup date', 'restorepilot-backup-migration'); ?>">
                <strong><?php echo esc_html(wp_date((string) get_option('date_format'), (int) $backup['modified'])); ?></strong>
                <span><?php echo esc_html(wp_date((string) get_option('time_format'), (int) $backup['modified'])); ?> &mdash; <?php echo esc_html(size_format((int) $backup['size'])); ?></span>
                <?php
                $rp_triggered = isset($backup['triggered_by']) ? (string) $backup['triggered_by'] : 'manual';
                $rp_type      = isset($backup['backup_type'])  ? (string) $backup['backup_type']  : 'full';
                $rp_trigger_label = $rp_triggered === 'scheduled'
                  ? __('Auto', 'restorepilot-backup-migration')
                  : __('Manual', 'restorepilot-backup-migration');
                $rp_type_label = $rp_type === 'database'
                  ? __('DB only', 'restorepilot-backup-migration')
                  : ($rp_type === 'selected-content'
                    ? __('Partial', 'restorepilot-backup-migration')
                    : __('Full', 'restorepilot-backup-migration'));
                ?>
                <span class="rp-backup-badges">
                  <span class="rp-badge rp-badge--<?php echo esc_attr($rp_triggered); ?>"><?php echo esc_html($rp_trigger_label); ?></span>
                  <span class="rp-badge rp-badge--type"><?php echo esc_html($rp_type_label); ?></span>
                </span>
              </td>
              <td class="rp-backup-parts" data-rp-label="<?php echo esc_attr__('Backup file', 'restorepilot-backup-migration'); ?>">
                <?php self::render_backup_download_controls($backup['name'], (int) $backup['size'], (int) ($backup['volumes'] ?? 1)); ?>
              </td>
              <td class="rp-backup-actions" data-rp-label="<?php echo esc_attr__('Actions', 'restorepilot-backup-migration'); ?>">
                <a class="button"
                   href="<?php echo esc_url(self::action_url('restorepilot_health', $backup['name'])); ?>">
                  <?php echo esc_html__('Check', 'restorepilot-backup-migration'); ?>
                </a>
                <button type="button"
                        class="button rp-btn-restore rp-restore-from-existing"
                        data-backup-name="<?php echo esc_attr($backup['name']); ?>"
                        data-backup-path="<?php echo esc_attr($backup_abs_path); ?>">
                  <?php echo esc_html__('Restore', 'restorepilot-backup-migration'); ?>
                </button>
                <a class="button rp-btn-danger"
                   href="<?php echo esc_url(self::action_url('restorepilot_delete', $backup['name'])); ?>"
                   data-confirm="<?php echo esc_attr(__('Delete this backup? This cannot be undone.', 'restorepilot-backup-migration')); ?>">
                  <?php echo esc_html__('Delete', 'restorepilot-backup-migration'); ?>
                </a>
                <a class="button" href="<?php echo esc_url(add_query_arg('tab', 'logs', self::admin_url())); ?>">
                  <?php echo esc_html__('View Log', 'restorepilot-backup-migration'); ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php
  }

  private static function render_logs_tab(): void {
    $log = self::read_log_for_display();
    ?>
    <div style="margin-top:20px;">
      <div class="rp-log-card">
        <div class="rp-log-toolbar">
          <button type="button" class="button rp-button-with-icon" id="rp-refresh-log">
            <span class="dashicons dashicons-update" aria-hidden="true"></span>
            <span class="rp-button-label"><?php echo esc_html__('Refresh', 'restorepilot-backup-migration'); ?></span>
          </button>
          <a class="button rp-button-with-icon" href="<?php echo esc_url(self::admin_action_url('restorepilot_download_log')); ?>">
            <span class="dashicons dashicons-download" aria-hidden="true"></span>
            <span class="rp-button-label"><?php echo esc_html__('Download', 'restorepilot-backup-migration'); ?></span>
          </a>
          <button type="button" class="button" id="rp-clear-log"><?php echo esc_html__('Clear', 'restorepilot-backup-migration'); ?></button>

          <div class="rp-log-sep" aria-hidden="true"></div>

          <button type="button" class="button rp-log-filter is-active" data-rp-log-filter="all"><?php echo esc_html__('All', 'restorepilot-backup-migration'); ?></button>
          <button type="button" class="button rp-log-filter" data-rp-log-filter="error"><?php echo esc_html__('Errors', 'restorepilot-backup-migration'); ?></button>
          <button type="button" class="button rp-log-filter" data-rp-log-filter="backup"><?php echo esc_html__('Backups', 'restorepilot-backup-migration'); ?></button>
          <button type="button" class="button rp-log-filter" data-rp-log-filter="restore"><?php echo esc_html__('Restores', 'restorepilot-backup-migration'); ?></button>
        </div>
        <pre class="rp-log-output" id="rp-log-output"><?php echo esc_html($log !== '' ? $log : __('No log entries yet.', 'restorepilot-backup-migration')); ?></pre>
      </div>
    </div>
    <?php
  }

  public static function render_restore_success_notice(): void {
    if (!current_user_can('manage_options')) {
      return;
    }

    $notice = self::get_restore_success_notice();
    if (!$notice) {
      return;
    }
    ?>
    <div class="notice notice-success is-dismissible">
      <p><strong><?php echo esc_html__('Restore completed successfully.', 'restorepilot-backup-migration'); ?></strong></p>
      <p><?php echo esc_html__('You were asked to log in again because WordPress sessions can change after a database restore.', 'restorepilot-backup-migration'); ?></p>
    </div>
    <?php
  }

  public static function render_restore_success_dialog(): void {
    if (!current_user_can('manage_options')) {
      return;
    }

    $notice = self::get_restore_success_notice();
    if (!$notice) {
      return;
    }

    $source = isset($notice['source_url']) ? (string) $notice['source_url'] : '';
    $target = isset($notice['target_url']) ? (string) $notice['target_url'] : '';
    ?>
        <div class="rp-restore-dialog-backdrop" id="rp-restore-success-dialog" role="dialog" aria-modal="true" aria-labelledby="rp-restore-success-title">
      <div class="rp-restore-dialog">
        <h2 id="rp-restore-success-title"><?php echo esc_html__('Restore completed successfully', 'restorepilot-backup-migration'); ?></h2>
        <p><?php echo esc_html__('The site database and selected wp-content files were restored. Login was expected because WordPress may replace session data during restore.', 'restorepilot-backup-migration'); ?></p>
        <?php if ($source !== '' || $target !== ''): ?>
          <div class="rp-restore-dialog__meta">
            <?php if ($source !== ''): ?>
              <p><strong><?php echo esc_html__('Source:', 'restorepilot-backup-migration'); ?></strong> <?php echo esc_html($source); ?></p>
            <?php endif; ?>
            <?php if ($target !== ''): ?>
              <p><strong><?php echo esc_html__('This site:', 'restorepilot-backup-migration'); ?></strong> <?php echo esc_html($target); ?></p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <ul>
          <li><?php echo esc_html__('Open the front of the site and check important pages.', 'restorepilot-backup-migration'); ?></li>
          <li><?php echo esc_html__('Reconnect SMTP, SEO, cache, or license-based plugins if they show notices.', 'restorepilot-backup-migration'); ?></li>
          <li><?php echo esc_html__('Use Logs if anything looks unexpected.', 'restorepilot-backup-migration'); ?></li>
        </ul>
        <div class="rp-restore-dialog__actions">
          <a class="button" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener"><?php echo esc_html__('View Site', 'restorepilot-backup-migration'); ?></a>
          <a class="button button-primary" href="<?php echo esc_url(add_query_arg('tab', 'restore', self::admin_url())); ?>"><?php echo esc_html__('Open RestorePilot', 'restorepilot-backup-migration'); ?></a>
          <button type="button" class="button" id="rp-restore-success-close"><?php echo esc_html__('Close', 'restorepilot-backup-migration'); ?></button>
        </div>
      </div>
    </div>
    <?php
    delete_option(self::RESTORE_SUCCESS_OPTION);
    self::$restore_success_notice = [];
  }

  private static function backup_check_message(string $file, bool $for_restore): string {
    if (!is_file($file) || !is_readable($file)) {
      throw new RuntimeException(__('Backup file not found.', 'restorepilot-backup-migration'));
    }

    $zip = self::open_backup_archive($file);

    try {
      $validated = self::validate_backup_zip($zip, false);
      $manifest = $validated['manifest'];
      $readiness = self::backup_restore_readiness($manifest, (int) $validated['file_count']);
      $needed = self::estimate_restore_file_bytes($zip);
      $free = is_dir(self::content_dir()) ? @disk_free_space(self::content_dir()) : false;
    } finally {
      $zip->close();
    }

    $source_url = isset($manifest['home_url']) ? (string) $manifest['home_url'] : '';
    if ($source_url === '' && isset($manifest['source_home_url'])) {
      $source_url = (string) $manifest['source_home_url'];
    }
    $source_url = self::normalize_url($source_url);
    $source_label = $source_url !== '' ? $source_url : __('unknown source', 'restorepilot-backup-migration');
    $created_label = self::backup_created_label($manifest);
    $type_label = self::backup_type_label($manifest, $readiness['type']);
    $size = filesize($file);
    $size_label = $size ? size_format((int) $size) : __('unknown size', 'restorepilot-backup-migration');
    $disk_label = $needed > 0
      ? sprintf(
        /* translators: 1: estimated size of files to restore, 2: available disk space */
        __('Estimated files: %1$s. Available disk: %2$s.', 'restorepilot-backup-migration'),
        size_format((int) $needed),
        $free === false ? __('unknown', 'restorepilot-backup-migration') : size_format((int) $free)
      )
      : __('No wp-content files were found in this archive.', 'restorepilot-backup-migration');
    $disk_warning = $free !== false && $needed > 0 && (int) $free < (int) ($needed * 1.15);

    $details = sprintf(
      /* translators: 1: backup type label, 2: source URL, 3: creation date, 4: number of database tables, 5: number of files, 6: archive size, 7: disk space info line */
      __('Type: %1$s. Source: %2$s. Created: %3$s. Tables: %4$d. Files: %5$d. Size: %6$s. %7$s', 'restorepilot-backup-migration'),
      $type_label,
      $source_label,
      $created_label,
      (int) $validated['table_count'],
      (int) $validated['file_count'],
      $size_label,
      $disk_label
    );

    self::write_log('Backup check report for ' . basename($file) . ': ' . $details . ' Restorable: ' . (!empty($readiness['restorable']) ? 'yes' : 'no') . '.');

    if (!empty($readiness['restorable'])) {
      if ($disk_warning) {
        return sprintf(
          /* translators: %s: backup details summary (type, source, tables, files, size, disk info) */
          __('Backup check passed, but available disk space may be too low for restore. %s', 'restorepilot-backup-migration'),
          $details
        );
      }

      return sprintf(
        $for_restore
          /* translators: %s: backup details summary (type, source, tables, files, size, disk info) */
          ? __('Backup check passed. This full backup is ready for restore. %s', 'restorepilot-backup-migration')
          /* translators: %s: backup details summary (type, source, tables, files, size, disk info) */
          : __('Backup check passed. This is a full restorable backup. %s', 'restorepilot-backup-migration'),
        $details
      );
    }

    return sprintf(
      /* translators: %s: backup details summary (type, source, tables, files, size, disk info) */
      __('Backup check completed. This archive is not a full-site restore file. Use Download Full Backup for restore or migration. %s', 'restorepilot-backup-migration'),
      $details
    );
  }

  private static function backup_type_label(array $manifest, string $type): string {
    if ($type === 'partial' && !empty($manifest['partial_type'])) {
      $partial_type = sanitize_key((string) $manifest['partial_type']);
      $partial_labels = [
        'database' => __('Database-only archive', 'restorepilot-backup-migration'),
        'plugins' => __('Plugins-only archive', 'restorepilot-backup-migration'),
        'themes' => __('Themes-only archive', 'restorepilot-backup-migration'),
        'uploads' => __('Uploads-only archive', 'restorepilot-backup-migration'),
        'mu-plugins' => __('Must-use plugins archive', 'restorepilot-backup-migration'),
        'others' => __('Other wp-content archive', 'restorepilot-backup-migration'),
      ];
      if (isset($partial_labels[$partial_type])) {
        return $partial_labels[$partial_type];
      }
    }

    $labels = [
      'full' => __('Full backup', 'restorepilot-backup-migration'),
      'database' => __('Database-only backup', 'restorepilot-backup-migration'),
      'partial' => __('Partial archive', 'restorepilot-backup-migration'),
      'selected-content' => __('Selected-content backup', 'restorepilot-backup-migration'),
      'rollback' => __('Restore rollback point', 'restorepilot-backup-migration'),
      'unsupported-configuration' => __('Incomplete backup — user accounts not included (custom user table)', 'restorepilot-backup-migration'),
    ];

    return $labels[$type] ?? __('Unknown archive type', 'restorepilot-backup-migration');
  }

  private static function backup_created_label(array $manifest): string {
    $created = isset($manifest['created_gmt']) ? (string) $manifest['created_gmt'] : '';
    if ($created === '' && isset($manifest['source_backup_created_gmt'])) {
      $created = (string) $manifest['source_backup_created_gmt'];
    }

    $timestamp = $created !== '' ? strtotime($created) : false;
    if ($timestamp === false) {
      return __('unknown time', 'restorepilot-backup-migration');
    }

    return wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), (int) $timestamp);
  }

  /**
   * Shown to a logged-in administrator instead of the plain 503. Rendered by
   * hand rather than through the admin UI because wp-admin itself is (rightly)
   * unavailable during a restore.
   */
  private static function render_maintenance_admin_page(): void {
    $snapshot = self::active_restore_snapshot();
    $job = $snapshot['job'];
    $phase = (string) ($job['phase_label'] ?? ($job['phase'] ?? ''));
    $message = (string) ($job['message'] ?? '');
    $progress = isset($job['progress']) ? (int) $job['progress'] : -1;
    $since = (int) $snapshot['seconds_since_update'];

    nocache_headers();
    status_header(503);
    header('Retry-After: 30');
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php esc_html_e('Restore in progress', 'restorepilot-backup-migration'); ?></title>
</head>
<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<div style="max-width:620px;padding:40px;">
  <h1 style="font-size:22px;margin:0 0 8px;"><?php esc_html_e('A restore is running on this site', 'restorepilot-backup-migration'); ?></h1>
  <p style="color:#50575e;line-height:1.6;margin:0 0 20px;">
    <?php esc_html_e('Visitors are seeing a maintenance page. You are seeing this because you are signed in as an administrator. The site stays unavailable while the database is being replaced, so nothing is written that the restore would overwrite.', 'restorepilot-backup-migration'); ?>
  </p>

  <?php if ($snapshot['job_id'] !== '' && $job): ?>
    <div style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px 20px;margin:0 0 20px;">
      <?php if ($phase !== ''): ?>
        <p style="margin:0 0 6px;"><strong><?php esc_html_e('Stage:', 'restorepilot-backup-migration'); ?></strong> <?php echo esc_html($phase); ?><?php echo $progress >= 0 ? ' (' . esc_html((string) $progress) . '%)' : ''; ?></p>
      <?php endif; ?>
      <?php if ($message !== ''): ?>
        <p style="margin:0 0 6px;color:#50575e;"><?php echo esc_html($message); ?></p>
      <?php endif; ?>
      <p style="margin:0;color:#50575e;">
        <?php
        if ($since >= 0) {
          echo esc_html(sprintf(
            /* translators: %s: human-readable time difference, e.g. "5 mins" */
            __('Last progress: %s ago.', 'restorepilot-backup-migration'),
            human_time_diff(time() - $since, time())
          ));
        } else {
          esc_html_e('No progress has been recorded yet.', 'restorepilot-backup-migration');
        }
        ?>
      </p>
    </div>
  <?php endif; ?>

  <?php if ($snapshot['looks_stuck']): ?>
    <div style="background:#fcf9e8;border-left:4px solid #dba617;padding:12px 16px;margin:0 0 16px;">
      <p style="margin:0;color:#50575e;line-height:1.6;">
        <?php esc_html_e('This restore has not reported progress for several minutes, so it has probably stopped. If it has, you can end it below and then recover your database from a pre-restore rollback point.', 'restorepilot-backup-migration'); ?>
      </p>
    </div>
  <?php else: ?>
    <p style="color:#50575e;line-height:1.6;margin:0 0 16px;">
      <?php esc_html_e('This restore is still making progress. Let it finish — ending it now would leave the site partly restored.', 'restorepilot-backup-migration'); ?>
    </p>
  <?php endif; ?>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="restorepilot_abandon_restore">
    <?php wp_nonce_field(self::NONCE); ?>
    <button type="submit" style="background:#d63638;border:0;color:#fff;padding:10px 16px;border-radius:3px;font-size:14px;cursor:pointer;"
      onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('End this restore and unlock the site? Only do this if the restore has genuinely stopped. The site may be left partly restored, and you should then recover from a pre-restore rollback point.', 'restorepilot-backup-migration'))); ?>);">
      <?php esc_html_e('End this restore and unlock the site', 'restorepilot-backup-migration'); ?>
    </button>
  </form>

  <p style="color:#787c82;font-size:13px;line-height:1.6;margin:16px 0 0;">
    <?php esc_html_e('Ending a restore turns off maintenance mode and releases the lock so you can start another restore straight away — including restoring a rollback point. It does not undo anything already written.', 'restorepilot-backup-migration'); ?>
  </p>
</div>
</body>
</html>
    <?php
    exit;
  }

  /**
   * The page every visitor sees while a restore holds the site.
   *
   * Deliberately self-contained: no external stylesheet, font, or image, and
   * no database read of any kind. This renders during the window where the
   * database is being replaced, so anything that queried an option could be
   * reading a half-swapped table — and any asset request would 503 against
   * this same gate. Everything needed is inlined.
   *
   * It carries no plugin name or link. This is the site owner's front end,
   * shown to their visitors, and plugin credits there have to be opt-in and
   * default to off (plugin guideline 10) — so the honest default is to say
   * nothing about which plugin is doing the work.
   *
   * The bar is indeterminate on purpose. Real progress is not readable from
   * here without a database the restore is actively rewriting, and a made-up
   * percentage that stalls or slides backwards reads worse than an honest
   * "something is happening".
   */
  private static function render_maintenance_page(): void {
    nocache_headers();
    status_header(503);
    header('Retry-After: 30');
    header('Content-Type: text/html; charset=utf-8');

    $title   = __('Briefly unavailable', 'restorepilot-backup-migration');
    $heading = __('Briefly unavailable for scheduled maintenance', 'restorepilot-backup-migration');
    $body    = __('This site is finishing a backup restore and will be back in a moment.', 'restorepilot-backup-migration');
    $hint    = __('Please check back shortly.', 'restorepilot-backup-migration');
    $status  = __('Restore in progress', 'restorepilot-backup-migration');

    echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow">';
    echo '<title>' . esc_html($title) . '</title>';
    echo '<style>
:root{
  --rp-bg:#f6f7f7; --rp-card:#fff; --rp-ink:#1d2327; --rp-muted:#646970;
  --rp-line:#dcdcde; --rp-accent:#2271b1; --rp-track:#e8eaec;
}
@media (prefers-color-scheme:dark){
  :root{
    --rp-bg:#16191d; --rp-card:#1f2429; --rp-ink:#f0f0f1; --rp-muted:#a7aaad;
    --rp-line:#2f353b; --rp-accent:#5ba3dd; --rp-track:#2b3137;
  }
}
*{box-sizing:border-box}
body{
  margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
  padding:24px; background:var(--rp-bg); color:var(--rp-ink);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;
  -webkit-font-smoothing:antialiased;
}
.rp-card{
  width:100%; max-width:460px; background:var(--rp-card);
  border:1px solid var(--rp-line); border-radius:10px;
  padding:36px 32px; text-align:center;
  box-shadow:0 1px 3px rgba(0,0,0,.06),0 8px 24px rgba(0,0,0,.05);
}
.rp-status{
  display:inline-flex; align-items:center; gap:7px;
  font-size:12px; font-weight:600; letter-spacing:.04em; text-transform:uppercase;
  color:var(--rp-muted); margin-bottom:18px;
}
.rp-dot{
  width:7px; height:7px; border-radius:50%; background:var(--rp-accent);
  animation:rp-pulse 1.8s ease-in-out infinite;
}
h1{font-size:19px; line-height:1.4; margin:0 0 10px; font-weight:600;}
p{font-size:14.5px; line-height:1.65; margin:0; color:var(--rp-muted);}
p + p{margin-top:6px;}
.rp-track{
  margin-top:26px; height:3px; border-radius:3px;
  background:var(--rp-track); overflow:hidden;
}
.rp-bar{
  height:100%; width:38%; border-radius:3px; background:var(--rp-accent);
  animation:rp-slide 1.9s cubic-bezier(.65,0,.35,1) infinite;
}
@keyframes rp-slide{
  0%{transform:translateX(-110%)} 100%{transform:translateX(330%)}
}
@keyframes rp-pulse{
  0%,100%{opacity:1} 50%{opacity:.3}
}
@media (prefers-reduced-motion:reduce){
  .rp-bar{animation:none; width:100%; opacity:.45}
  .rp-dot{animation:none}
}
</style></head>';

    echo '<body><main class="rp-card">';
    echo '<div class="rp-status"><span class="rp-dot" aria-hidden="true"></span>' . esc_html($status) . '</div>';
    echo '<h1>' . esc_html($heading) . '</h1>';
    echo '<p>' . esc_html($body) . '</p>';
    echo '<p>' . esc_html($hint) . '</p>';
    echo '<div class="rp-track" role="progressbar" aria-label="' . esc_attr($status) . '"><div class="rp-bar"></div></div>';
    echo '</main></body></html>';
    exit;
  }

  public static function render_operation_notices(): void {
    if (!current_user_can('manage_options')) {
      return;
    }
    $file = self::operation_notice_file();
    if (!is_file($file)) {
      return;
    }
    $raw = @file_get_contents($file);
    @unlink($file);
    if (!$raw) {
      return;
    }
    $notice = json_decode($raw, true);
    if (!is_array($notice) || empty($notice['type']) || empty($notice['message'])) {
      return;
    }
    // Discard stale notices older than 24 hours.
    if (!empty($notice['created']) && (time() - (int) $notice['created']) > 86400) {
      return;
    }
    $type      = $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
    $operation = sanitize_text_field($notice['operation'] ?? '');
    $message   = sanitize_text_field($notice['message']);
    $label     = $operation === 'backup'
      ? ($notice['type'] === 'success' ? __('Backup completed successfully.', 'restorepilot-backup-migration') : __('Backup failed.', 'restorepilot-backup-migration'))
      : ($notice['type'] === 'success' ? __('Restore completed.', 'restorepilot-backup-migration')           : __('Restore failed.', 'restorepilot-backup-migration'));
    ?>
    <div class="notice <?php echo esc_attr($type); ?> is-dismissible">
      <p><strong><?php echo esc_html($label); ?></strong> <?php echo esc_html($message); ?></p>
    </div>
    <?php
  }

  /**
   * Tells the user their plugins are sitting deactivated because a restore
   * held them back (see defer_active_plugins_during_restore()) and then did
   * not finish. Without this the site would just silently come back with
   * everything except RestorePilot switched off and nothing explaining why.
   */
  public static function render_deferred_plugins_notice(): void {
    if (!current_user_can('manage_options') || !self::has_orphaned_deferred_plugins()) {
      return;
    }

    $deferred = get_option(self::DEFERRED_PLUGINS_OPTION, []);
    $count = is_array($deferred) ? count($deferred) : 0;
    ?>
    <div class="notice notice-warning">
      <p>
        <strong><?php esc_html_e('RestorePilot: your other plugins are still deactivated.', 'restorepilot-backup-migration'); ?></strong>
      </p>
      <p>
        <?php
        echo esc_html(sprintf(
          /* translators: %d: number of plugins that were deactivated during a restore */
          _n(
            'A restore switched %d plugin off while it replaced your files, so a half-restored plugin could not crash the site mid-restore. That restore did not finish, so they were never switched back on.',
            'A restore switched %d plugins off while it replaced your files, so a half-restored plugin could not crash the site mid-restore. That restore did not finish, so they were never switched back on.',
            $count,
            'restorepilot-backup-migration'
          ),
          $count
        ));
        ?>
      </p>
      <p>
        <?php esc_html_e('Reactivate them once you are satisfied the site is in a good state — for example after finishing or retrying the restore, or after rolling back. Plugins whose files are missing will be left off.', 'restorepilot-backup-migration'); ?>
      </p>
      <p>
        <a href="<?php echo esc_url(self::admin_action_url('restorepilot_reactivate_plugins')); ?>" class="button button-primary">
          <?php esc_html_e('Reactivate my plugins', 'restorepilot-backup-migration'); ?>
        </a>
      </p>
    </div>
    <?php
  }

  private static function system_status_items(): array {
    self::ensure_storage();

    $free_space  = @disk_free_space(self::storage_dir());
    $max_upload  = (int) wp_max_upload_size();
    $cron_ready  = !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON;
    $zip_ok      = class_exists('ZipArchive');
    $folder_ok   = is_writable(self::backup_dir());
    $free_ok     = $free_space !== false && (int) $free_space > 50 * 1024 * 1024;
    $php_ok      = version_compare(PHP_VERSION, '7.4.0', '>=');

    return [
      [
        'label'  => __('PHP Version', 'restorepilot-backup-migration'),
        'value'  => PHP_VERSION,
        'status' => $php_ok ? 'ok' : 'warn',
      ],
      [
        'label'  => __('ZIP Support', 'restorepilot-backup-migration'),
        'value'  => $zip_ok
          ? __('Available', 'restorepilot-backup-migration')
          : __('Missing — restores require ZipArchive', 'restorepilot-backup-migration'),
        'status' => $zip_ok ? 'ok' : 'error',
      ],
      [
        'label'  => __('Backup Folder', 'restorepilot-backup-migration'),
        'value'  => $folder_ok
          ? __('Writable', 'restorepilot-backup-migration')
          : __('Not writable — backups cannot be saved', 'restorepilot-backup-migration'),
        'status' => $folder_ok ? 'ok' : 'error',
      ],
      [
        'label'  => __('Free Disk Space', 'restorepilot-backup-migration'),
        'value'  => $free_space === false ? __('Unknown', 'restorepilot-backup-migration') : size_format((int) $free_space),
        'status' => $free_space === false ? 'info' : ($free_ok ? 'ok' : 'warn'),
      ],
      [
        'label'  => __('Upload Limit', 'restorepilot-backup-migration'),
        'value'  => $max_upload > 0 ? size_format($max_upload) : __('Unknown', 'restorepilot-backup-migration'),
        'status' => 'info',
      ],
      [
        'label'  => __('WP-Cron', 'restorepilot-backup-migration'),
        'value'  => $cron_ready
          ? __('Available', 'restorepilot-backup-migration')
          : __('Disabled — scheduled backups won\'t run', 'restorepilot-backup-migration'),
        'status' => $cron_ready ? 'ok' : 'warn',
      ],
    ];
  }

  private static function diagnostic_status_items(array $backups): array {
    self::ensure_storage();

    $backup_bytes = 0;
    foreach ($backups as $backup) {
      $backup_bytes += isset($backup['size']) ? max(0, (int) $backup['size']) : 0;
    }

    $rollback_files = self::list_restore_rollback_points();
    $free_space = @disk_free_space(self::storage_dir());
    $temp_bytes = self::temp_storage_bytes();

    return [
      [
        'label' => __('Backup Folder', 'restorepilot-backup-migration'),
        'value' => self::backup_dir(),
        'status' => is_writable(self::backup_dir()) ? 'ok' : 'error',
      ],
      [
        'label' => __('Stored Backups', 'restorepilot-backup-migration'),
        'value' => sprintf(
          /* translators: 1: number of stored backups, 2: maximum number of backups, 3: total size of stored backups */
          __('%1$d of %2$d used — %3$s', 'restorepilot-backup-migration'),
          count($backups),
          self::MAX_BACKUPS,
          size_format((int) $backup_bytes)
        ),
        'status' => count($backups) >= self::MAX_BACKUPS ? 'warn' : 'ok',
      ],
      [
        'label' => __('Temporary Files', 'restorepilot-backup-migration'),
        'value' => size_format((int) $temp_bytes),
        'status' => $temp_bytes > 100 * 1024 * 1024 ? 'warn' : 'ok',
      ],
      [
        'label' => __('Restore Rollback Points', 'restorepilot-backup-migration'),
        'value' => sprintf(
          /* translators: 1: maximum number of rollback points kept, 2: number of rollback points currently stored */
          __('Up to %1$d hidden rollback point(s); currently %2$d stored.', 'restorepilot-backup-migration'),
          self::MAX_RESTORE_ROLLBACKS,
          count($rollback_files)
        ),
        'status' => 'ok',
      ],
      [
        'label' => __('Restore Safety', 'restorepilot-backup-migration'),
        'value' => __('Full backups only; partial archives are blocked from full restore; background restore is enabled.', 'restorepilot-backup-migration'),
        'status' => 'ok',
      ],
      [
        'label' => __('Free Disk Space', 'restorepilot-backup-migration'),
        'value' => $free_space === false ? __('Unknown', 'restorepilot-backup-migration') : size_format((int) $free_space),
        'status' => $free_space === false ? 'info' : ((int) $free_space > 250 * 1024 * 1024 ? 'ok' : 'warn'),
      ],
      [
        'label' => __('Uninstall Cleanup', 'restorepilot-backup-migration'),
        'value' => __('Deleting the plugin removes RestorePilot backups, logs, temp files, options, and scheduled events.', 'restorepilot-backup-migration'),
        'status' => 'info',
      ],
    ];
  }
}
