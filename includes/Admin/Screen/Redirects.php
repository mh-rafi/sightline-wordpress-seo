<?php

namespace Sightline\SEO\Admin\Screen;

use Sightline\SEO\Admin\Admin;
use Sightline\SEO\Redirects\Store;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Managing redirects (R7).
 *
 * A list, an editor, and a hit counter. The counter is the part that stops
 * the table growing forever: a rule with no hits since it was created
 * points away from a URL nothing links to any more, and without that column
 * there is no way to tell those from the ones carrying real traffic.
 */
class Redirects
{
    const PER_PAGE = 30;

    /**
     * @return void
     */
    public function register()
    {
        add_action('admin_post_sightline_save_redirect', array($this, 'save'));
        add_action('admin_post_sightline_delete_redirect', array($this, 'delete'));
    }

    /**
     * @return void
     */
    public function render()
    {
        if (!current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage redirects.', 'sightline-seo'));
        }

        $editing = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
        $adding  = isset($_GET['add']);
        ?>
        <div class="wrap sightline-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Redirects', 'sightline-seo'); ?></h1>
            <a href="<?php echo esc_url(Admin::url(Admin::SLUG . '-redirects', array('add' => 1))); ?>" class="page-title-action"><?php esc_html_e('Add rule', 'sightline-seo'); ?></a>
            <?php Admin::tabs(Admin::SLUG . '-redirects'); ?>
            <?php $this->notices(); ?>

            <?php if ($adding || $editing) : ?>
                <?php $this->form($editing ? Store::get($editing) : null); ?>
            <?php else : ?>
                <?php $this->table(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @return void
     */
    private function table()
    {
        $paged  = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        $total = Store::count(array('search' => $search));
        $rows  = Store::all(array(
            'search' => $search,
            'number' => self::PER_PAGE,
            'offset' => ($paged - 1) * self::PER_PAGE,
        ));

        $comparisons = Store::comparisons();
        ?>
        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr(Admin::SLUG . '-redirects'); ?>">
            <p class="search-box">
                <label class="screen-reader-text" for="sightline-redirect-search"><?php esc_html_e('Search redirects', 'sightline-seo'); ?></label>
                <input type="search" id="sightline-redirect-search" name="s" value="<?php echo esc_attr($search); ?>">
                <button type="submit" class="button"><?php esc_html_e('Search', 'sightline-seo'); ?></button>
            </p>
        </form>

        <table class="widefat striped sightline-redirects">
            <thead>
                <tr>
                    <th><?php esc_html_e('Matches', 'sightline-seo'); ?></th>
                    <th><?php esc_html_e('Goes to', 'sightline-seo'); ?></th>
                    <th><?php esc_html_e('Status', 'sightline-seo'); ?></th>
                    <th><?php esc_html_e('Hits', 'sightline-seo'); ?></th>
                    <th><?php esc_html_e('Last used', 'sightline-seo'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="6">
                    <?php esc_html_e('No redirect rules yet.', 'sightline-seo'); ?>
                    <a href="<?php echo esc_url(Admin::url(Admin::SLUG . '-import')); ?>"><?php esc_html_e('Import them from Rank Math.', 'sightline-seo'); ?></a>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row) : ?>
                <tr<?php echo 'active' === $row['status'] ? '' : ' class="sightline-inactive"'; ?>>
                    <td>
                        <?php foreach ($row['sources'] as $source) : ?>
                            <div><code><?php echo esc_html($source['pattern']); ?></code>
                                <span class="sightline-badge"><?php echo esc_html($comparisons[$source['comparison']]); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <td><?php echo $row['url_to'] ? esc_html($row['url_to']) : '<span class="sightline-muted">—</span>'; ?></td>
                    <td><?php echo (int) $row['header_code']; ?></td>
                    <td><?php echo (int) $row['hits']; ?></td>
                    <td>
                        <?php
                        echo $row['last_accessed'] && '0000-00-00 00:00:00' !== $row['last_accessed']
                            ? esc_html($row['last_accessed'])
                            : '<span class="sightline-muted">' . esc_html__('never', 'sightline-seo') . '</span>';
                        ?>
                    </td>
                    <td class="sightline-row-actions">
                        <a href="<?php echo esc_url(Admin::url(Admin::SLUG . '-redirects', array('edit' => (int) $row['id']))); ?>"><?php esc_html_e('Edit', 'sightline-seo'); ?></a>
                        <a class="sightline-delete" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sightline_delete_redirect&id=' . (int) $row['id']), 'sightline_delete_redirect_' . (int) $row['id'])); ?>"><?php esc_html_e('Delete', 'sightline-seo'); ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post(paginate_links(array(
                'base'    => add_query_arg('paged', '%#%'),
                'format'  => '',
                'current' => $paged,
                'total'   => $pages,
            )));
            echo '</div></div>';
        }
    }

    /**
     * The add/edit form.
     *
     * @param array|null $rule
     * @return void
     */
    private function form($rule)
    {
        $sources = $rule ? $rule['sources'] : array(array('pattern' => '', 'comparison' => 'exact', 'ignore' => ''));
        $code    = $rule ? (int) $rule['header_code'] : 301;
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sightline-card">
            <input type="hidden" name="action" value="sightline_save_redirect">
            <input type="hidden" name="id" value="<?php echo $rule ? (int) $rule['id'] : 0; ?>">
            <?php wp_nonce_field('sightline_save_redirect'); ?>

            <h2><?php echo $rule ? esc_html__('Edit rule', 'sightline-seo') : esc_html__('Add rule', 'sightline-seo'); ?></h2>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Match', 'sightline-seo'); ?></th>
                    <td>
                        <div id="sightline-sources">
                            <?php foreach ($sources as $i => $source) : ?>
                                <div class="sightline-source">
                                    <input type="text" class="regular-text" name="sources[<?php echo (int) $i; ?>][pattern]" value="<?php echo esc_attr($source['pattern']); ?>" placeholder="downloads/old-product">
                                    <select name="sources[<?php echo (int) $i; ?>][comparison]">
                                        <?php foreach (Store::comparisons() as $value => $label) : ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected($source['comparison'], $value); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="button-link sightline-remove-source"><?php esc_html_e('Remove', 'sightline-seo'); ?></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button" id="sightline-add-source"><?php esc_html_e('Add another pattern', 'sightline-seo'); ?></button>
                        <p class="description"><?php esc_html_e('Paths relative to the site root, without a leading slash. One rule can carry several patterns, each with its own comparison — a rule is one destination, not one pattern.', 'sightline-seo'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sl_code"><?php esc_html_e('Response', 'sightline-seo'); ?></label></th>
                    <td>
                        <select id="sl_code" name="header_code">
                            <?php foreach (Store::status_codes() as $value => $label) : ?>
                                <option value="<?php echo (int) $value; ?>" <?php selected($code, $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr class="sightline-target-row">
                    <th scope="row"><label for="sl_target"><?php esc_html_e('Destination', 'sightline-seo'); ?></label></th>
                    <td>
                        <input type="text" class="large-text" id="sl_target" name="url_to" value="<?php echo $rule ? esc_attr($rule['url_to']) : ''; ?>" placeholder="/downloads/new-product">
                        <p class="description"><?php esc_html_e('A path or a full URL. Paths are resolved against this site, so a rule written here keeps working after a domain change. Not used by 410 or 451, which are the answer rather than a signpost to one.', 'sightline-seo'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Active', 'sightline-seo'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="status" value="active" <?php checked(!$rule || 'active' === $rule['status']); ?>>
                            <?php esc_html_e('This rule fires', 'sightline-seo'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e('Save rule', 'sightline-seo'); ?></button>
                <a href="<?php echo esc_url(Admin::url(Admin::SLUG . '-redirects')); ?>" class="button-link"><?php esc_html_e('Cancel', 'sightline-seo'); ?></a>
            </p>
        </form>
        <?php
    }

    /**
     * @return void
     */
    public function save()
    {
        if (!current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage redirects.', 'sightline-seo'));
        }
        check_admin_referer('sightline_save_redirect');

        $id      = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $sources = array();

        foreach ((array) (isset($_POST['sources']) ? wp_unslash($_POST['sources']) : array()) as $source) {
            $pattern = isset($source['pattern']) ? trim(sanitize_text_field((string) $source['pattern'])) : '';
            if ('' === $pattern) {
                continue;
            }
            $sources[] = array(
                'pattern'    => ltrim($pattern, '/'),
                'comparison' => isset($source['comparison']) ? sanitize_key((string) $source['comparison']) : 'exact',
                'ignore'     => '',
            );
        }

        if (empty($sources)) {
            wp_safe_redirect(Admin::url(Admin::SLUG . '-redirects', array('sightline-error' => 'no-source')));
            exit;
        }

        $result = Store::save(array(
            'sources'     => $sources,
            'url_to'      => isset($_POST['url_to']) ? trim((string) wp_unslash($_POST['url_to'])) : '',
            'header_code' => isset($_POST['header_code']) ? (int) $_POST['header_code'] : 301,
            'status'      => (isset($_POST['status']) && 'active' === $_POST['status']) ? 'active' : 'inactive',
        ), $id ? $id : null);

        wp_safe_redirect(Admin::url(Admin::SLUG . '-redirects', array('sightline-saved' => $result ? 1 : 0)));
        exit;
    }

    /**
     * @return void
     */
    public function delete()
    {
        if (!current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage redirects.', 'sightline-seo'));
        }

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('sightline_delete_redirect_' . $id);

        if ($id) {
            Store::delete($id);
        }

        wp_safe_redirect(Admin::url(Admin::SLUG . '-redirects', array('sightline-deleted' => 1)));
        exit;
    }

    /**
     * @return void
     */
    private function notices()
    {
        if (isset($_GET['sightline-saved'])) {
            $ok = (bool) (int) $_GET['sightline-saved'];
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                $ok ? 'success' : 'error',
                esc_html($ok ? __('Rule saved.', 'sightline-seo') : __('The rule could not be saved.', 'sightline-seo'))
            );
        }
        if (isset($_GET['sightline-deleted'])) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__('Rule deleted.', 'sightline-seo'));
        }
        if (isset($_GET['sightline-error']) && 'no-source' === $_GET['sightline-error']) {
            printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html__('A rule needs at least one pattern to match.', 'sightline-seo'));
        }
    }
}
