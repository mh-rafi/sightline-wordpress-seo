<?php

namespace Sightline\SEO\Admin\Screen;

use Sightline\SEO\Admin\Admin;
use Sightline\SEO\Import\RankMath;
use Sightline\SEO\Import\Report;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The migration screen (R10).
 *
 * An admin page rather than a command, because the production database is
 * never replaced from local: whatever runs has to run there, by hand, in a
 * browser. The batching is AJAX for the same reason -- a shared host will
 * kill a request long before a large site's postmeta is walked.
 */
class Import
{
    /**
     * @return void
     */
    public function register()
    {
        add_action('wp_ajax_sightline_import_batch', array($this, 'ajax_batch'));
        add_action('admin_post_sightline_clear_report', array($this, 'clear_report'));
    }

    /**
     * @return void
     */
    public function render()
    {
        if (!current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to run an import.', 'sightline-seo'));
        }

        $counts = RankMath::counts();
        $report = Report::load();
        ?>
        <div class="wrap sightline-wrap">
            <h1><?php esc_html_e('Import', 'sightline-seo'); ?></h1>
            <?php Admin::tabs(Admin::SLUG . '-import'); ?>

            <div class="sightline-card">
                <h2><?php esc_html_e('Import from Rank Math', 'sightline-seo'); ?></h2>

                <p><?php esc_html_e('Nothing Rank Math wrote is changed or deleted. Both plugins can stay active while you check the result, and rolling back is deactivating one of them.', 'sightline-seo'); ?></p>

                <?php if (!RankMath::has_data()) : ?>
                    <p class="sightline-empty"><?php esc_html_e('No Rank Math data was found in this database.', 'sightline-seo'); ?></p>
                <?php else : ?>

                    <table class="widefat striped sightline-counts">
                        <thead><tr><th><?php esc_html_e('What was found', 'sightline-seo'); ?></th><th><?php esc_html_e('Rows', 'sightline-seo'); ?></th></tr></thead>
                        <tbody>
                            <tr><td><?php esc_html_e('Posts, pages and products with Rank Math meta', 'sightline-seo'); ?></td><td><?php echo (int) $counts['post_meta']; ?></td></tr>
                            <tr><td><?php esc_html_e('Taxonomy terms with Rank Math meta', 'sightline-seo'); ?></td><td><?php echo (int) $counts['term_meta']; ?></td></tr>
                            <tr><td><?php esc_html_e('Redirect rules', 'sightline-seo'); ?></td><td><?php echo (int) $counts['redirects']; ?></td></tr>
                            <tr><td><?php esc_html_e('Global title and social settings', 'sightline-seo'); ?></td><td><?php echo $counts['settings'] ? esc_html__('present', 'sightline-seo') : esc_html__('none', 'sightline-seo'); ?></td></tr>
                        </tbody>
                    </table>

                    <?php if ((int) $counts['redirects'] > 0) : ?>
                        <p class="sightline-callout">
                            <?php
                            printf(
                                /* translators: %d: number of redirect rules. */
                                esc_html__('%d redirect rules exist only in Rank Math’s own table. Nothing outside it knows they are there, and deactivating the plugin stops every one of them without an error anywhere. This is the part of the migration that cannot be recreated from your content.', 'sightline-seo'),
                                (int) $counts['redirects']
                            );
                            ?>
                        </p>
                    <?php endif; ?>

                    <form id="sightline-import-form">
                        <p>
                            <label>
                                <input type="checkbox" name="overwrite" value="1">
                                <?php esc_html_e('Overwrite values Sightline already has', 'sightline-seo'); ?>
                            </label>
                            <span class="description"><?php esc_html_e('Off by default: a second run then changes nothing, and anything you have already edited here is safe.', 'sightline-seo'); ?></span>
                        </p>

                        <p>
                            <button type="submit" class="button button-primary" id="sightline-import-run"><?php esc_html_e('Run import', 'sightline-seo'); ?></button>
                        </p>

                        <div id="sightline-import-progress" hidden>
                            <p class="sightline-progress-label"></p>
                            <progress id="sightline-import-bar" max="100" value="0"></progress>
                        </div>
                    </form>

                <?php endif; ?>
            </div>

            <div class="sightline-card">
                <h2><?php esc_html_e('Not imported, on purpose', 'sightline-seo'); ?></h2>
                <p><?php esc_html_e('These are Rank Math’s own working data. They render nothing on the front end, so they are left where they are rather than copied — listed here so that is a decision you can see rather than a gap you find later.', 'sightline-seo'); ?></p>
                <table class="widefat striped">
                    <tbody>
                    <?php foreach (RankMath::not_imported() as $key => $why) : ?>
                        <tr><td><code><?php echo esc_html($key); ?></code></td><td><?php echo esc_html($why); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php $this->report($report); ?>
        </div>
        <?php
    }

    /**
     * The result of the last run.
     *
     * @param Report $report
     * @return void
     */
    private function report(Report $report)
    {
        if ($report->is_empty()) {
            return;
        }

        $data = $report->data();
        ?>
        <div class="sightline-card" id="sightline-import-report">
            <h2><?php esc_html_e('Last run', 'sightline-seo'); ?></h2>
            <p class="description">
                <?php
                printf(
                    /* translators: 1: start time, 2: finish time or a dash. */
                    esc_html__('Started %1$s UTC, finished %2$s.', 'sightline-seo'),
                    esc_html($data['started']),
                    esc_html($data['finished'] ? $data['finished'] . ' UTC' : __('— still running or interrupted', 'sightline-seo'))
                );
                ?>
            </p>

            <?php if (!empty($data['imported'])) : ?>
                <h3><?php esc_html_e('Written', 'sightline-seo'); ?></h3>
                <table class="widefat striped"><tbody>
                <?php foreach ($data['imported'] as $key => $n) : ?>
                    <tr><td><code><?php echo esc_html($key); ?></code></td><td><?php echo (int) $n; ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>

            <?php if (!empty($data['skipped'])) : ?>
                <h3><?php esc_html_e('Skipped, and why', 'sightline-seo'); ?></h3>
                <table class="widefat striped"><tbody>
                <?php foreach ($data['skipped'] as $reason => $info) : ?>
                    <tr>
                        <td><?php echo esc_html($reason); ?></td>
                        <td><?php echo (int) $info['count']; ?></td>
                        <td class="sightline-examples">
                            <?php echo esc_html(implode(', ', array_slice($info['examples'], 0, 6))); ?>
                            <?php if ($info['count'] > count($info['examples'])) : ?>
                                <?php echo esc_html__('…', 'sightline-seo'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>

            <?php if (!empty($data['notes'])) : ?>
                <h3><?php esc_html_e('Worth knowing', 'sightline-seo'); ?></h3>
                <ul class="sightline-notes">
                    <?php foreach ($data['notes'] as $note) : ?>
                        <li><?php echo esc_html($note); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (empty($data['skipped']) && empty($data['imported'])) : ?>
                <p><?php esc_html_e('Nothing was written and nothing was skipped — there was nothing to do.', 'sightline-seo'); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sightline_clear_report">
                <?php wp_nonce_field('sightline_clear_report'); ?>
                <button type="submit" class="button"><?php esc_html_e('Clear this report', 'sightline-seo'); ?></button>
            </form>
        </div>
        <?php
    }

    /**
     * One batch, called repeatedly by the browser.
     *
     * @return void
     */
    public function ajax_batch()
    {
        if (!current_user_can(Admin::CAPABILITY)) {
            wp_send_json_error(array('message' => __('You do not have permission to run an import.', 'sightline-seo')), 403);
        }
        check_ajax_referer('sightline_import', 'nonce');

        $step      = isset($_POST['step']) ? sanitize_key(wp_unslash($_POST['step'])) : '';
        $offset    = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
        $overwrite = !empty($_POST['overwrite']);

        $steps = RankMath::steps();
        if (!isset($steps[$step])) {
            wp_send_json_error(array('message' => __('Unknown import step.', 'sightline-seo')), 400);
        }

        if ('settings' === $step && 0 === $offset) {
            Report::clear();
            Report::start();
        }

        $result = RankMath::run($step, $offset, $overwrite);

        $names = array_keys($steps);
        $index = array_search($step, $names, true);
        $result['label'] = $steps[$step];
        $result['next']  = ($result['done'] && isset($names[$index + 1])) ? $names[$index + 1] : ($result['done'] ? '' : $step);

        if ($result['done'] && '' === $result['next']) {
            $report = Report::load();
            $report->finish();
        }

        wp_send_json_success($result);
    }

    /**
     * @return void
     */
    public function clear_report()
    {
        if (!current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do that.', 'sightline-seo'));
        }
        check_admin_referer('sightline_clear_report');

        Report::clear();

        wp_safe_redirect(Admin::url(Admin::SLUG . '-import'));
        exit;
    }
}
