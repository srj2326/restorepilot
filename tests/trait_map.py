"""
Which trait each method moves into. Written out in full rather than matched by
prefix, so that a method landing in the wrong place is a visible mistake in
this file rather than an accident of a regex.
"""

MAP = {
 'bootstrap': """
   init activate admin_menu plugin_action_links plugin_row_meta
   register_privacy_policy_content enqueue_plugins_page_assets enqueue_admin_assets
 """,

 'admin-ui': """
   render_admin_page render_backup_download_controls render_multisite_unsupported_notice
   render_existing_backups_card render_logs_tab render_restore_success_notice
   render_restore_success_dialog render_operation_notices render_deferred_plugins_notice
   render_maintenance_admin_page render_maintenance_page
   system_status_items diagnostic_status_items
   backup_check_message backup_type_label backup_created_label
 """,

 'request-handlers': """
   handle_backup handle_ajax_backup handle_backup_status handle_read_log handle_clear_log
   handle_chunk_restore_upload handle_cancel_backup handle_run_backup_job_admin
   handle_run_backup_job handle_run_restore_job handle_restore handle_restore_check
   handle_ajax_restore handle_restore_status handle_set_restore_admin_password
   handle_run_restore_job_admin handle_health_check handle_save_settings handle_cleanup_temp
   handle_reset_runtime handle_master_reset handle_clear_log_post handle_scheduled_backup
   handle_download handle_download_partial handle_download_log handle_split handle_delete
   handle_abandon_restore handle_reactivate_deferred_plugins cli_backup cli_health
 """,

 'backup': """
   run_backup_job create_backup_package dispatch_backup_worker write_database_export
   create_backup_parts add_selected_paths_to_zip add_directory_to_zip add_file_to_zip
   reset_file_scan_progress record_file_scan_progress flush_file_scan_progress
   assert_backup_disk_space estimate_database_size estimate_directory_size
   estimate_selected_paths_size should_skip_file reset_backup_exclusion_tracking
   record_backup_exclusion backup_exclusion_labels path_matches_skip_part
   path_starts_with_part compile_skip_rules join_relative_path list_backup_file_items
   selected_backup_paths_from_request sanitize_selected_backup_paths
   database_phase_progress database_phase_label backup_phase_label
   maybe_send_backup_email sync_scheduled_backup
 """,

 'restore': """
   run_restore_job perform_restore dispatch_restore_worker restore_database restore_files
   build_restore_plan create_restore_rollback_point create_new_admin_login
   defer_active_plugins_during_restore restore_deferred_active_plugins
   has_orphaned_deferred_plugins cleanup_missing_active_plugins active_plugin_file_exists
   assert_restore_preflight assert_restore_disk_space estimate_restore_file_bytes
   assert_restore_zip_entry_count validate_backup_zip backup_restore_readiness
   restore_database_phase_progress restore_database_phase_label restore_phase_label
   prepare_restore_upload restore_chunk_dir server_backup_path_is_allowed
   assemble_restore_chunks cleanup_restore_chunk_uploads normalize_uploaded_files
   upload_error_message missing_restore_upload_message active_restore_snapshot
 """,

 'database': """
   stream_database_records database_part_names matching_paren_offset
   create_table_tail_is_safe assert_create_table_is_safe primary_key_columns
   not_null_columns unique_key_columns keyset_cursor_columns table_engine
   json_fragment make_json_safe decode_b64_column_value uses_custom_user_tables
   table_belongs_to_other_site map_table_name restore_scratch_table_name
   temporary_table_name old_table_name table_exists journal_restore_scratch_tables
   clear_restore_table_journal sweep_stale_restore_tables throw_on_db_error
   like_prefix_literal wpdb
 """,

 'migration': """
   replace_urls_deep contains_incomplete_object is_incomplete_object
   url_replacement_pairs normalize_url validate_restore_url normalize_server_path
 """,

 'storage': """
   ensure_storage storage_dir backup_dir rollback_dir direct_download_dir
   direct_download_url content_dir plugins_dir deny_htaccess is_follow_on_volume
   volume_paths_for discover_volumes open_backup_archive list_backups peek_manifest
   peek_backup_type friendly_backup_filename friendly_rollback_filename site_filename_slug
   enforce_backup_retention list_restore_rollback_points enforce_restore_rollback_retention
   create_direct_download_url download_header_filename site_uses_https
   ensure_direct_download_dir cleanup_direct_downloads cleanup_direct_downloads_cron
   delete_direct_download_token purge_direct_downloads list_backup_parts delete_backup_parts
   build_partial_zip partial_backup_manifest serve_download serve_combined_volume_download
   write_combined_volumes rewrite_combined_manifest_entry write_stream write_file
   throw_write_failure delete_directory path_size temp_storage_bytes
   cleanup_stale_temp_files temp_file_patterns master_reset_wipe_dir pick_master_reset_theme
 """,

 'jobs': """
   backup_job_option restore_job_option poll_token_file write_poll_token_file
   read_poll_token_file delete_poll_token_file restore_status_file write_restore_status_file
   read_restore_status_file get_backup_job get_restore_job set_backup_job set_restore_job
   update_backup_job update_restore_job maybe_touch_backup_job maybe_touch_restore_job
   throw_if_backup_cancelled throw_if_chunk_time_exceeded throw_if_restore_chunk_time_exceeded
   mark_stale_backup_job_if_needed mark_unstarted_backup_job_if_needed
   mark_unstarted_restore_job_if_needed prune_finished_job_records backup_job_is_stale
   mark_stale_restore_job_if_needed restore_job_is_stale
 """,

 'locks': """
   acquire_backup_lock backup_lock_is_active release_backup_lock restore_lock_is_active
   acquire_restore_lock release_restore_lock backup_lock_can_be_released
   restore_lock_can_be_released claim_worker_lock acquire_worker_lock
   acquire_backup_worker_lock release_backup_worker_lock backup_worker_lock_option
   acquire_restore_worker_lock release_restore_worker_lock restore_worker_lock_option
   force_release_backup_locks force_release_restore_locks
 """,

 'logging': """
   log_file write_log read_log read_log_for_display clear_log append_db_log read_db_log
   trim_log enable_error_logging handle_php_error handle_shutdown_error log_runtime_error
   error_file_is_relevant php_error_label
 """,

 'maintenance': """
   maybe_block_for_maintenance should_block_for_maintenance enable_maintenance_mode
   disable_maintenance_mode operation_notice_file write_operation_notice
   set_restore_success_notice get_restore_success_notice
 """,

 'support': """
   get_settings retention_count action_url admin_action_url admin_url post_value post_bool
   post_int post_array query_value query_file uploaded_file_array
   safe_backup_file_from_request path_is_unsafe zip_entry_is_unsafe safe_content_path
   verify_admin_request multisite_unsupported_message assert_multisite_unsupported
   redirect_notice redirect_error prepare_for_long_operation purge_foreign_runtime_state
 """,
}

# name -> trait
METHOD_TRAIT = {}
for trait, names in MAP.items():
    for n in names.split():
        if n in METHOD_TRAIT:
            raise SystemExit('method assigned twice: %s (%s and %s)' % (n, METHOD_TRAIT[n], trait))
        METHOD_TRAIT[n] = trait

TRAIT_TITLES = {
 'bootstrap': 'Plugin lifecycle: activation, menus, and asset loading.',
 'admin-ui': 'Everything that renders admin markup.',
 'request-handlers': 'Entry points for admin-post, AJAX, cron, and WP-CLI requests.',
 'backup': 'Creating a backup: the chunked job, the archive, and what goes in it.',
 'restore': 'Restoring a backup: the chunked job, the plan, and the safety net around it.',
 'database': 'Reading and writing table data, and the schema rules that keep it safe.',
 'migration': 'Rewriting URLs inside restored data, including serialized values.',
 'storage': 'Where files live: the storage directory, archives, volumes, and downloads.',
 'jobs': 'The job record a chunked backup or restore resumes from.',
 'locks': 'Keeping two backups, two restores, or two workers off the same job.',
 'logging': 'The plugin log, and capturing PHP errors into it.',
 'maintenance': 'Maintenance mode during a restore, and the notices left afterwards.',
 'support': 'Settings, request input, capability checks, and path safety.',
}

if __name__ == '__main__':
    import json, sys
    inv = json.load(open(sys.argv[1]))['methods']
    missing = [m for m in inv if m not in METHOD_TRAIT]
    extra = [m for m in METHOD_TRAIT if m not in inv]
    print('methods in class : %d' % len(inv))
    print('methods mapped   : %d' % len(METHOD_TRAIT))
    print('unassigned       : %s' % (missing or 'none'))
    print('mapped but absent: %s' % (extra or 'none'))
    if missing or extra:
        sys.exit(1)
    print('\nevery method assigned exactly once')
    for t in MAP:
        print('  %-18s %d' % (t, sum(1 for v in METHOD_TRAIT.values() if v == t)))
