<?php
/**
 * Uninstall.
 *
 * Asks first, and the default answer is keep (R11). The setting lives on
 * the Tools screen; without an explicit yes recorded there, deleting the
 * plugin leaves every title, description and redirect exactly where it is.
 *
 * The reason is not politeness. A redirect table is the one thing on this
 * list that cannot be recreated from content -- 26 rules on the site this
 * was built for, pointing retired product URLs at their replacements, known
 * to nothing outside the database. Dropping that because somebody clicked
 * Delete to test something is a data loss with no undo.
 *
 * @package Sightline\SEO
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$sightline_settings = get_option('sightline_settings', array());
$sightline_delete = is_array($sightline_settings)
    && !empty($sightline_settings['advanced']['delete_data_on_uninstall']);

if (!$sightline_delete) {
    return;
}

global $wpdb;

// Post, term and user meta. LIKE on the prefix rather than a key list, so
// per-taxonomy primary-term keys go too.
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_sightline\\_%'");
$wpdb->query("DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '\\_sightline\\_%'");
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_sightline\\_%'");

foreach (array(
    'sightline_settings',
    'sightline_version',
    'sightline_active_redirects',
    'sightline_import_report',
    'sightline_redirects_db_version',
) as $sightline_option) {
    delete_option($sightline_option);
}

$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'sightline_redirects');

flush_rewrite_rules(false);
