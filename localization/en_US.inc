<?php

/**
 * Paperless Attach — English (en_US) localization.
 *
 * @license GPL-3.0+
 */

$labels = [];

// Settings section
$labels['paperlesssection'] = 'Paperless';

// Token field
$labels['tokenlabel']       = 'API token';
$labels['tokenconfigured']  = 'configured ✓';
$labels['tokenremove']      = 'Remove stored token';

// Test connection
$labels['testconnection']   = 'Test connection';
$labels['testok']           = 'Connection successful ✓';
$labels['testbadtoken']     = 'Authentication failed — check your API token.';
$labels['testunreachable']  = 'Paperless is unreachable.';
$labels['testnotoken']      = 'No API token configured yet.';
$labels['testdecryptfail']  = 'Stored token could not be read — please re-enter it.';

// --- Phase 2: Search & attach from Paperless ---

// Toolbar button
$labels['attach_from_paperless'] = 'Attach from Paperless';

// Search
$labels['search_placeholder'] = 'Search documents…';
$labels['btn_search']         = 'Search';

// Filters
$labels['filters']              = 'Filters';
$labels['filter_tags']          = 'Tags';
$labels['filter_correspondent'] = 'Correspondent';
$labels['filter_doctype']       = 'Document type';
$labels['filter_date_from']     = 'From';
$labels['filter_date_to']       = 'To';
$labels['filter_reset']         = 'Reset filters';
$labels['filter_loading']       = 'Loading filters…';
$labels['tags_selected_count']  = '%d selected';

// Result list
$labels['load_more']    = 'Load more';
$labels['result_count'] = '%d of %d';
$labels['meta_size_kb'] = '%d KB';
$labels['meta_size_mb'] = '%d MB';

// Selection / footer
$labels['selected_count']      = '%d selected';
$labels['btn_attach_selected'] = 'Attach %d';
$labels['btn_cancel']          = 'Cancel';

// States
$labels['state_idle']                    = 'Enter a search term and click "Search".';
$labels['state_loading']                 = 'Searching…';
$labels['state_no_results']              = 'No documents found.';
$labels['state_no_token']                = 'No Paperless token configured.';
$labels['state_no_token_settings_link']  = 'Configure it in Settings';
$labels['state_error']                   = 'Paperless could not be reached.';
$labels['btn_retry']                     = 'Try again';

// --- Phase 3: Attach to email ---
$labels['attaching']   = 'Attaching…';
$labels['attached']    = 'Document(s) attached.';
$labels['attacherror'] = 'The document could not be attached.';

// --- Phase 4: Hardening & polish — batch + failure-category messages ---

// Batch summary ("3 of 4 attached.")
$labels['attach_summary'] = '%d of %d attached.';

// Per-document failure categories
$labels['attach_too_large']  = "'%s' is too large (%s > %s).";
$labels['attach_no_archive'] = "'%s' has no archive PDF and was skipped.";
$labels['attach_item_error'] = "'%s' could not be attached.";
$labels['attach_duplicate']  = "'%s' is already attached.";

// Short markers for result rows
$labels['attach_too_large_short'] = 'too large';
$labels['attach_duplicate_short'] = 'already attached';

// Missing/invalid token (whole batch)
$labels['attach_no_token']      = 'No valid Paperless token. Please configure it in Settings.';
$labels['attach_no_token_link'] = 'Configure it in Settings';

// --- Feature: Save received attachments to Paperless ---

// Menu item / preview button
$labels['save_to_paperless']       = 'Save to Paperless';
$labels['save_to_paperless_short'] = 'Paperless';

// Progress (%s = filename)
$labels['save_uploading']          = 'Uploading "%s" to Paperless…';
$labels['save_uploading_generic']  = 'Uploading attachment to Paperless…';
$labels['save_processing']         = 'Processing "%s" in Paperless…';
$labels['save_processing_generic'] = 'Processing in Paperless…';

// Outcome
$labels['save_success']           = '"%s" was saved to Paperless ✓';
$labels['save_success_generic']   = 'Saved to Paperless ✓';
$labels['save_duplicate']         = '"%s" already exists in Paperless.';
$labels['save_duplicate_generic'] = 'Document already exists in Paperless.';
$labels['save_pending']           = '"%s" was handed to Paperless (processing).';
$labels['save_pending_generic']   = 'Handed to Paperless (processing).';
$labels['save_failed']            = '"%s" could not be saved to Paperless.';
$labels['save_failed_generic']    = 'Attachment could not be saved to Paperless.';
$labels['save_too_large']         = '"%s" is too large to upload to Paperless.';
$labels['save_no_token']          = 'No valid Paperless token. Please configure it in Settings.';

$messages = [];
