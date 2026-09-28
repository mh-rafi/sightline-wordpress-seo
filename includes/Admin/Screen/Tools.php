<?php

namespace Sightline\SEO\Admin\Screen;

use Sightline\SEO\Admin\Admin;
use Sightline\SEO\Meta;
use Sightline\SEO\Options;
use Sightline\SEO\Redirects\Store;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Export, import and the uninstall choice (R11).
 *
 * "Leaving is a feature." The migrator is the flagship, so the exit has to
 * be as good as the entrance: documented keys, a JSON export, and an
 * uninstall that asks rather than silently keeping or silently dropping. A
 * plugin people can leave is one they will try.
 *
 * The export exists for a second reason specific to this project: the
 * production database is never replaced from local, so settings entered on
 * one install reach another as a file a person carries, or not at all.
 */
class Tools
{
    /**
     * @return void
     */
    public function register()
    {
        add_action('admin_post_sightline_export', array($this, 'export'));
        add_action('admin_post_sightline_import_json', array($this, 'import'));
        add_action('admin_post_sightline_save_uninstall', array($this, 'save_uninstall'));
    }

    /**
     * @return void
     */
    public function render()
    {
        if (!current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to use these tools.', 'sightline-seo'));
        }
        ?>
        <div class="wrap sightline-wrap">
            <h1><?php esc_html_e('Tools', 'sightline-seo'); ?></h1>
            <?php Admin::tabs(Admin::SLUG . '-tools'); ?>
            <?php $this->notices(); ?>

            <div class="sightline-card">
                <h2><?php esc_html_e('Export', 'sightline-seo'); ?></h2>
                <p><?php esc_html_e('Settings and redirect rules as one JSON file. Per-page titles and descriptions are not included — those live with the content and travel with a database, not with a settings file.', 'sightline-seo'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="sightline_export">
                    <?php wp_nonce_field('sightline_export'); ?>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Download JSON', 'sightline-seo'); ?></button>
                </form>
            </div>

            <div class="sightline-card">
                <h2><?php esc_html_e('Import', 'sightline-seo'); ?></h2>
                <p><?php esc_html_e('Paste a file exported from another install. Redirect rules are added; rules with the same patterns are left alone rather than duplicated.', 'sightline-seo'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="sightline_import_json">
                    <?php wp_nonce_field('sightline_import_json'); ?>
                    <textarea name="payload" rows="8" class="large-text code" placeholder="{&quot;version&quot;: &quot;1.0.0&quot;, …}"></textarea>
                    <p>
                        <label><input type="checkbox" name="include_settings" value="1" checked> <?php esc_html_e('Settings', 'sightline-seo'); ?></label>
                        <label><input type="checkbox" name="include_redirects" value="1" checked> <?php esc_html_e('Redirect rules', 'sightline-seo'); ?></label>
                    </p>
                    <button type="submit" class="button"><?php esc_html_e('Import', 'sightline-seo'); ?></button>
                </form>
            </div>

            <div class="sightline-card">
                <h2><?php esc_html_e('What this plugin owns', 'sightline-seo'); ?></h2>
                <p><?php esc_html_e('Every key Sightline writes, so you can find, back up or remove its data without this plugin’s help. It reads no other plugin’s keys and never has.', 'sightline-seo'); ?></p>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Where', 'sightline-seo'); ?></th><th><?php esc_html_e('Key', 'sightline-seo'); ?></th></tr></thead>
                    <tbody>
                        <?php foreach (array_keys(Meta::fields()) as $field) : ?>
                            <tr><td><?php esc_html_e('post, term and user meta', 'sightline-seo'); ?></td><td><code><?php echo esc_html(Meta::key($field)); ?></code></td></tr>
                        <?php endforeach; ?>
                        <tr><td><?php esc_html_e('post meta', 'sightline-seo'); ?></td><td><code><?php echo esc_html(Meta::PREFIX . 'primary_{taxonomy}'); ?></code></td></tr>
                        <tr><td><?php esc_html_e('option', 'sightline-seo'); ?></td><td><code><?php echo esc_html(Options::KEY); ?></code></td></tr>
                        <tr><td><?php esc_html_e('option', 'sightline-seo'); ?></td><td><code>sightline_version</code>, <code>sightline_active_redirects</code>, <code>sightline_import_report</code></td></tr>
                        <tr><td><?php esc_html_e('table', 'sightline-seo'); ?></td><td><code><?php echo esc_html(Store::table()); ?></code></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="sightline-card">
                <h2><?php esc_html_e('When you uninstall', 'sightline-seo'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="sightline_save_uninstall">
                    <?php wp_nonce_field('sightline_save_uninstall'); ?>
                    <p>
                        <label>
                            <input type="radio" name="delete_data" value="0" <?php checked(!Options::get('advanced/delete_data_on_uninstall', false)); ?>>
                            <?php esc_html_e('Keep everything. Deleting the plugin leaves your titles, descriptions and redirects in the database.', 'sightline-seo'); ?>
                        </label><br>
                        <label>
                            <input type="radio" name="delete_data" value="1" <?php checked((bool) Options::get('advanced/delete_data_on_uninstall', false)); ?>>
                            <?php esc_html_e('Delete everything listed above when the plugin is deleted.', 'sightline-seo'); ?>
                        </label>
                    </p>
                    <p class="description"><?php esc_html_e('Keeping is the default on purpose: a deletion nobody asked for is not recoverable, and deactivating to test something should never be the thing that loses your redirects.', 'sightline-seo'); ?></p>
                    <button type="submit" class="button"><?php esc_html_e('Save', 'sightline-seo'); ?></button>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * @return void
     */
    public function export()
    {
        if (!current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to export.', 'sightline-seo'));
        }
        check_admin_referer('sightline_export');

        $payload = array(
            'plugin'    => 'sightline-seo',
            'version'   => SIGHTLINE_VERSION,
            'exported'  => gmdate('c'),
            'home_url'  => home_url('/'),
            'settings'  => get_option(Options::KEY, array()),
            'redirects' => array(),
        );

        foreach (Store::all(array('order' => 'ASC')) as $row) {
            $payload['redirects'][] = array(
                'sources'     => $row['sources'],
                'url_to'      => $row['url_to'],
                'header_code' => (int) $row['header_code'],
                'status'      => $row['status'],
            );
        }

        $filename = 'sightline-seo-' . gmdate('Y-m-d') . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * @return void
     */
    public function import()
    {
        if (!current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to import.', 'sightline-seo'));
        }
        check_admin_referer('sightline_import_json');

        $raw = isset($_POST['payload']) ? trim((string) wp_unslash($_POST['payload'])) : '';
        $data = json_decode($raw, true);

        if (!is_array($data) || !isset($data['plugin']) || 'sightline-seo' !== $data['plugin']) {
            wp_safe_redirect(Admin::url(Admin::SLUG . '-tools', array('sightline-error' => 'bad-json')));
            exit;
        }

        $settings = 0;
        $rules    = 0;

        if (!empty($_POST['include_settings']) && isset($data['settings']) && is_array($data['settings'])) {
            Options::save($data['settings']);
            $settings = 1;
        }

        if (!empty($_POST['include_redirects']) && isset($data['redirects']) && is_array($data['redirects'])) {
            if (!Store::table_exists()) {
                Store::install_table();
            }
            foreach ($data['redirects'] as $rule) {
                if (!is_array($rule) || empty($rule['sources'])) {
                    continue;
                }
                if (Store::find_by_sources((array) $rule['sources'])) {
                    continue;
                }
                if (Store::save($rule)) {
                    $rules++;
                }
            }
        }

        wp_safe_redirect(Admin::url(Admin::SLUG . '-tools', array(
            'sightline-imported' => 1,
            'settings'           => $settings,
            'rules'              => $rules,
        )));
        exit;
    }

    /**
     * @return void
     */
    public function save_uninstall()
    {
        if (!current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to change this.', 'sightline-seo'));
        }
        check_admin_referer('sightline_save_uninstall');

        Options::set_section('advanced', array(
            'delete_data_on_uninstall' => !empty($_POST['delete_data']),
        ));

        wp_safe_redirect(Admin::url(Admin::SLUG . '-tools', array('sightline-saved' => 1)));
        exit;
    }

    /**
     * @return void
     */
    private function notices()
    {
        if (isset($_GET['sightline-saved'])) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__('Saved.', 'sightline-seo'));
        }
        if (isset($_GET['sightline-imported'])) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(sprintf(
                    /* translators: 1: 1 or 0 for settings, 2: number of redirect rules. */
                    __('Import finished. Settings replaced: %1$s. Redirect rules added: %2$d.', 'sightline-seo'),
                    empty($_GET['settings']) ? __('no', 'sightline-seo') : __('yes', 'sightline-seo'),
                    isset($_GET['rules']) ? (int) $_GET['rules'] : 0
                ))
            );
        }
        if (isset($_GET['sightline-error'])) {
            printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html__('That did not look like a Sightline export.', 'sightline-seo'));
        }
    }
}
