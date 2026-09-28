<?php

namespace Sightline\SEO\Admin\Screen;

use Sightline\SEO\Admin\Admin;
use Sightline\SEO\Admin\Fields;
use Sightline\SEO\Integrations\EDD;
use Sightline\SEO\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The settings screen.
 *
 * One form, saved whole. The settings live in one option and are exported
 * as one JSON document, so splitting them across six sub-screens with six
 * save buttons would only add ways for half of them to be applied.
 */
class Settings
{
    /**
     * @return void
     */
    public function register()
    {
        add_action('admin_post_sightline_save_settings', array($this, 'save'));
    }

    /**
     * @return void
     */
    public function render()
    {
        if (!current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage these settings.', 'sightline-seo'));
        }

        $s = Options::all();
        ?>
        <div class="wrap sightline-wrap">
            <h1><?php esc_html_e('Sightline SEO', 'sightline-seo'); ?></h1>
            <?php Admin::tabs(Admin::SLUG); ?>
            <?php $this->notices(); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sightline_save_settings">
                <?php wp_nonce_field('sightline_save_settings'); ?>

                <h2 class="title"><?php esc_html_e('Site identity', 'sightline-seo'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sl_org_name"><?php esc_html_e('Organization name', 'sightline-seo'); ?></label></th>
                        <td>
                            <input type="text" class="regular-text" id="sl_org_name" name="sightline[organization][name]" value="<?php echo esc_attr($s['organization']['name']); ?>">
                            <p class="description"><?php esc_html_e('Used as the publisher in structured data and as the brand on product pages.', 'sightline-seo'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sl_org_type"><?php esc_html_e('Organization type', 'sightline-seo'); ?></label></th>
                        <td>
                            <select id="sl_org_type" name="sightline[organization][type]">
                                <?php foreach (array('Organization', 'Corporation', 'LocalBusiness', 'OnlineStore', 'Person') as $type) : ?>
                                    <option value="<?php echo esc_attr($type); ?>" <?php selected($s['organization']['type'], $type); ?>><?php echo esc_html($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Logo', 'sightline-seo'); ?></th>
                        <td><?php Fields::image_picker('sightline[organization][logo]', $s['organization']['logo'], ''); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sl_sep"><?php esc_html_e('Title separator', 'sightline-seo'); ?></label></th>
                        <td><input type="text" class="small-text" id="sl_sep" name="sightline[title_separator]" value="<?php echo esc_attr($s['title_separator']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sl_home_title"><?php esc_html_e('Front page title', 'sightline-seo'); ?></label></th>
                        <td>
                            <input type="text" class="large-text" id="sl_home_title" name="sightline[home][title]" value="<?php echo esc_attr($s['home']['title']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sl_home_desc"><?php esc_html_e('Front page description', 'sightline-seo'); ?></label></th>
                        <td><textarea class="large-text" rows="2" id="sl_home_desc" name="sightline[home][description]"><?php echo esc_textarea($s['home']['description']); ?></textarea></td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Social profiles', 'sightline-seo'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sl_profiles"><?php esc_html_e('Profile URLs', 'sightline-seo'); ?></label></th>
                        <td>
                            <textarea class="large-text code" rows="5" id="sl_profiles" name="sightline[social][profiles]" placeholder="https://www.facebook.com/example&#10;https://x.com/example"><?php echo esc_textarea(implode("\n", (array) $s['social']['profiles'])); ?></textarea>
                            <p class="description"><?php esc_html_e('One per line. Every one of them is emitted as sameAs on the Organization — this is not a single-profile field, because a site with five profiles linked in its footer should not advertise one.', 'sightline-seo'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sl_twitter"><?php esc_html_e('X / Twitter username', 'sightline-seo'); ?></label></th>
                        <td><input type="text" class="regular-text" id="sl_twitter" name="sightline[social][twitter_site]" value="<?php echo esc_attr($s['social']['twitter_site']); ?>" placeholder="@example"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sl_card"><?php esc_html_e('Card type', 'sightline-seo'); ?></label></th>
                        <td>
                            <select id="sl_card" name="sightline[social][card_type]">
                                <option value="summary_large_image" <?php selected($s['social']['card_type'], 'summary_large_image'); ?>><?php esc_html_e('Large image', 'sightline-seo'); ?></option>
                                <option value="summary" <?php selected($s['social']['card_type'], 'summary'); ?>><?php esc_html_e('Summary', 'sightline-seo'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Fallback sharing image', 'sightline-seo'); ?></th>
                        <td><?php Fields::image_picker('sightline[social][default_image]', $s['social']['default_image'], ''); ?></td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Content types', 'sightline-seo'); ?></h2>
                <?php $this->post_type_table(); ?>
                <?php $this->taxonomy_table(); ?>

                <h2 class="title"><?php esc_html_e('Structured data', 'sightline-seo'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Emit', 'sightline-seo'); ?></th>
                        <td>
                            <?php
                            $this->checkbox('sightline[schema][webpage]', !empty($s['schema']['webpage']), __('WebPage / CollectionPage node', 'sightline-seo'));
                            $this->checkbox('sightline[schema][breadcrumbs]', !empty($s['schema']['breadcrumbs']), __('BreadcrumbList', 'sightline-seo'));
                            $this->checkbox('sightline[schema][product]', !empty($s['schema']['product']), __('Product, with one Offer per price tier', 'sightline-seo'));
                            $this->checkbox('sightline[website][searchbox]', !empty($s['website']['searchbox']), __('Site search action on WebSite', 'sightline-seo'));
                            ?>
                            <?php if (!EDD::is_active()) : ?>
                                <p class="description"><?php esc_html_e('No supported store is active, so no Product schema is emitted whatever this is set to.', 'sightline-seo'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sl_pvu"><?php esc_html_e('priceValidUntil', 'sightline-seo'); ?></label></th>
                        <td>
                            <input type="number" class="small-text" id="sl_pvu" min="0" max="60" name="sightline[schema][price_valid_months]" value="<?php echo esc_attr((string) $s['schema']['price_valid_months']); ?>">
                            <?php esc_html_e('months ahead', 'sightline-seo'); ?>
                            <p class="description"><?php esc_html_e('Google warns when an offer has no price expiry. Always computed forward from today, so it can never go stale. Set to 0 to omit it.', 'sightline-seo'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Hand-over', 'sightline-seo'); ?></th>
                        <td>
                            <?php $this->checkbox('sightline[store][take_over_microdata]', !empty($s['store']['take_over_microdata']), __('Switch off product microdata emitted by the theme or store plugin', 'sightline-seo')); ?>
                            <p class="description"><?php esc_html_e('Two Product entities describing one page is worse than either alone. Only has an effect where the other code offers a filter to switch itself off.', 'sightline-seo'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php $this->store_section($s); ?>

                <h2 class="title"><?php esc_html_e('Sitemap', 'sightline-seo'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('XML sitemap', 'sightline-seo'); ?></th>
                        <td>
                            <?php $this->checkbox('sightline[sitemap][enabled]', !empty($s['sitemap']['enabled']), __('Enabled', 'sightline-seo')); ?>
                            <?php $this->checkbox('sightline[sitemap][images]', !empty($s['sitemap']['images']), __('Include featured images', 'sightline-seo')); ?>
                            <p class="description">
                                <a href="<?php echo esc_url(home_url('/sitemap_index.xml')); ?>" target="_blank" rel="noopener"><?php echo esc_html(home_url('/sitemap_index.xml')); ?></a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sl_per_page"><?php esc_html_e('URLs per file', 'sightline-seo'); ?></label></th>
                        <td><input type="number" class="small-text" id="sl_per_page" min="50" max="2000" name="sightline[sitemap][per_page]" value="<?php echo esc_attr((string) $s['sitemap']['per_page']); ?>"></td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Other archives', 'sightline-seo'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Author archives', 'sightline-seo'); ?></th>
                        <td>
                            <?php $this->checkbox('sightline[author][noindex]', !empty($s['author']['noindex']), __('Keep out of search results', 'sightline-seo')); ?>
                            <?php $this->checkbox('sightline[search][noindex]', !empty($s['search']['noindex']), __('Keep search result pages out of search results', 'sightline-seo')); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Redirects', 'sightline-seo'); ?></th>
                        <td><?php $this->checkbox('sightline[redirects][enabled]', !empty($s['redirects']['enabled']), __('Enabled', 'sightline-seo')); ?></td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Per-post-type templates.
     *
     * @return void
     */
    private function post_type_table()
    {
        echo '<table class="widefat striped sightline-types"><thead><tr>';
        printf('<th>%s</th><th>%s</th><th>%s</th><th>%s</th>',
            esc_html__('Post type', 'sightline-seo'),
            esc_html__('Title template', 'sightline-seo'),
            esc_html__('Description template', 'sightline-seo'),
            esc_html__('Indexing', 'sightline-seo')
        );
        echo '</tr></thead><tbody>';

        foreach (get_post_types(array('public' => true), 'objects') as $post_type) {
            if ('attachment' === $post_type->name) {
                continue;
            }
            $d = Options::post_type_defaults($post_type->name);
            $n = 'sightline[post_types][' . $post_type->name . ']';
            echo '<tr><td><strong>' . esc_html($post_type->labels->name) . '</strong><br><code>' . esc_html($post_type->name) . '</code></td>';
            echo '<td><input type="text" class="widefat" name="' . esc_attr($n) . '[title]" value="' . esc_attr($d['title']) . '"></td>';
            echo '<td><input type="text" class="widefat" name="' . esc_attr($n) . '[description]" value="' . esc_attr($d['description']) . '"></td>';
            echo '<td>';
            $this->checkbox($n . '[noindex]', !empty($d['noindex']), __('No index', 'sightline-seo'));
            $this->checkbox($n . '[in_sitemap]', !empty($d['in_sitemap']), __('In sitemap', 'sightline-seo'));
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @return void
     */
    private function taxonomy_table()
    {
        echo '<table class="widefat striped sightline-types"><thead><tr>';
        printf('<th>%s</th><th>%s</th><th>%s</th><th>%s</th>',
            esc_html__('Taxonomy', 'sightline-seo'),
            esc_html__('Title template', 'sightline-seo'),
            esc_html__('Description template', 'sightline-seo'),
            esc_html__('Indexing', 'sightline-seo')
        );
        echo '</tr></thead><tbody>';

        foreach (get_taxonomies(array('public' => true), 'objects') as $taxonomy) {
            if ('post_format' === $taxonomy->name) {
                continue;
            }
            $d = Options::taxonomy_defaults($taxonomy->name);
            $n = 'sightline[taxonomies][' . $taxonomy->name . ']';
            echo '<tr><td><strong>' . esc_html($taxonomy->labels->name) . '</strong><br><code>' . esc_html($taxonomy->name) . '</code></td>';
            echo '<td><input type="text" class="widefat" name="' . esc_attr($n) . '[title]" value="' . esc_attr($d['title']) . '"></td>';
            echo '<td><input type="text" class="widefat" name="' . esc_attr($n) . '[description]" value="' . esc_attr($d['description']) . '"></td>';
            echo '<td>';
            $this->checkbox($n . '[noindex]', !empty($d['noindex']), __('No index', 'sightline-seo'));
            $this->checkbox($n . '[in_sitemap]', !empty($d['in_sitemap']), __('In sitemap', 'sightline-seo'));
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * The store section: which pages are transactional.
     *
     * The detected list is shown rather than described, because R8's whole
     * point is that these are found through the store's settings and not
     * guessed from slugs -- and the only way to prove that to whoever is
     * reading the screen is to show them what was found.
     *
     * @param array $s
     * @return void
     */
    private function store_section(array $s)
    {
        if (!EDD::is_active()) {
            return;
        }

        echo '<h2 class="title">' . esc_html__('Store pages', 'sightline-seo') . '</h2>';
        echo '<table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__('Transactional pages', 'sightline-seo') . '</th><td>';

        $this->checkbox('sightline[store][noindex_transactional]', !empty($s['store']['noindex_transactional']), __('Keep them out of search results and out of the sitemap', 'sightline-seo'));

        $labels = EDD::page_setting_labels();
        $found  = array();
        foreach (EDD::page_setting_keys() as $key) {
            $id = function_exists('edd_get_option') ? (int) edd_get_option($key, 0) : 0;
            if ($id > 0) {
                $found[$id] = isset($labels[$key]) ? $labels[$key] : $key;
            }
        }

        if (!empty($found)) {
            echo '<p class="description">' . esc_html__('Detected from the store’s own page settings:', 'sightline-seo') . '</p><ul class="sightline-page-list">';
            foreach ($found as $id => $label) {
                printf(
                    '<li><strong>%s</strong> — <a href="%s">%s</a> <code>#%d</code></li>',
                    esc_html($label),
                    esc_url((string) get_edit_post_link($id)),
                    esc_html(get_the_title($id)),
                    (int) $id
                );
            }
            echo '</ul>';
        }

        $extra = (array) Options::get('store/extra_noindex_pages', array());
        echo '<p><label for="sl_extra_pages">' . esc_html__('Other pages to treat the same way', 'sightline-seo') . '</label><br>';
        echo '<input type="text" class="regular-text" id="sl_extra_pages" name="sightline[store][extra_noindex_pages]" value="' . esc_attr(implode(', ', array_map('intval', $extra))) . '">';
        echo '<span class="description"> ' . esc_html__('Post IDs, comma separated. For an account or registration page the store does not record in its settings — listed by ID rather than matched by slug, so renaming a page cannot silently start indexing it.', 'sightline-seo') . '</span></p>';

        echo '</td></tr></table>';
    }

    /**
     * A checkbox with the hidden companion that makes "off" submit.
     *
     * @param string $name
     * @param bool   $checked
     * @param string $label
     * @return void
     */
    private function checkbox($name, $checked, $label)
    {
        printf(
            '<label class="sightline-check"><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>',
            esc_attr($name),
            checked($checked, true, false),
            esc_html($label)
        );
    }

    /**
     * @return void
     */
    private function notices()
    {
        if (isset($_GET['sightline-saved'])) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__('Settings saved.', 'sightline-seo'));
        }
    }

    /**
     * @return void
     */
    public function save()
    {
        if (!current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage these settings.', 'sightline-seo'));
        }
        check_admin_referer('sightline_save_settings');

        $input = isset($_POST['sightline']) ? wp_unslash($_POST['sightline']) : array();
        $stored = get_option(Options::KEY, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        $stored['title_separator'] = sanitize_text_field((string) ($input['title_separator'] ?? '-'));

        $stored['home'] = array(
            'title'       => sanitize_text_field((string) ($input['home']['title'] ?? '')),
            'description' => sanitize_textarea_field((string) ($input['home']['description'] ?? '')),
            'image'       => sanitize_text_field((string) ($input['home']['image'] ?? '')),
        );

        $stored['organization'] = array(
            'enabled' => true,
            'name'    => sanitize_text_field((string) ($input['organization']['name'] ?? '')),
            'type'    => sanitize_text_field((string) ($input['organization']['type'] ?? 'Organization')),
            'logo'    => sanitize_text_field((string) ($input['organization']['logo'] ?? '')),
        );

        $profiles = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) ($input['social']['profiles'] ?? '')) as $line) {
            $line = trim($line);
            if ('' !== $line) {
                $profiles[] = esc_url_raw($line);
            }
        }
        $stored['social'] = array(
            'profiles'      => array_values(array_unique(array_filter($profiles))),
            'twitter_site'  => sanitize_text_field((string) ($input['social']['twitter_site'] ?? '')),
            'card_type'     => in_array(($input['social']['card_type'] ?? ''), array('summary', 'summary_large_image'), true) ? $input['social']['card_type'] : 'summary_large_image',
            'default_image' => sanitize_text_field((string) ($input['social']['default_image'] ?? '')),
        );

        $stored['post_types'] = array();
        foreach ((array) ($input['post_types'] ?? array()) as $name => $values) {
            $stored['post_types'][sanitize_key($name)] = array(
                'title'       => sanitize_text_field((string) ($values['title'] ?? '')),
                'description' => sanitize_text_field((string) ($values['description'] ?? '')),
                'noindex'     => !empty($values['noindex']),
                'in_sitemap'  => !empty($values['in_sitemap']),
            );
        }

        $stored['taxonomies'] = array();
        foreach ((array) ($input['taxonomies'] ?? array()) as $name => $values) {
            $stored['taxonomies'][sanitize_key($name)] = array(
                'title'       => sanitize_text_field((string) ($values['title'] ?? '')),
                'description' => sanitize_text_field((string) ($values['description'] ?? '')),
                'noindex'     => !empty($values['noindex']),
                'in_sitemap'  => !empty($values['in_sitemap']),
            );
        }

        $stored['schema'] = array(
            'webpage'             => !empty($input['schema']['webpage']),
            'breadcrumbs'         => !empty($input['schema']['breadcrumbs']),
            'product'             => !empty($input['schema']['product']),
            'price_valid_months'  => max(0, min(60, (int) ($input['schema']['price_valid_months'] ?? 12))),
        );

        $stored['website'] = array(
            'enabled'   => true,
            'name'      => (string) Options::get('website/name', ''),
            'searchbox' => !empty($input['website']['searchbox']),
        );

        $extra = array();
        foreach (preg_split('/[,\s]+/', (string) ($input['store']['extra_noindex_pages'] ?? '')) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $extra[] = $id;
            }
        }
        $stored['store'] = array(
            'noindex_transactional' => !empty($input['store']['noindex_transactional']),
            'take_over_microdata'   => !empty($input['store']['take_over_microdata']),
            'extra_noindex_pages'   => array_values(array_unique($extra)),
        );

        $stored['sitemap'] = array_merge((array) Options::get('sitemap', array()), array(
            'enabled'  => !empty($input['sitemap']['enabled']),
            'images'   => !empty($input['sitemap']['images']),
            'per_page' => max(50, min(2000, (int) ($input['sitemap']['per_page'] ?? 500))),
        ));

        $stored['author'] = array_merge((array) Options::get('author', array()), array(
            'noindex' => !empty($input['author']['noindex']),
        ));
        $stored['search'] = array('noindex' => !empty($input['search']['noindex']));
        $stored['redirects'] = array_merge((array) Options::get('redirects', array()), array(
            'enabled' => !empty($input['redirects']['enabled']),
        ));

        Options::save($stored);

        // Sitemap inclusion can change which rewrite rules matter.
        \Sightline\SEO\Sitemap\Sitemaps::add_rewrite_rules();
        flush_rewrite_rules(false);

        wp_safe_redirect(Admin::url(Admin::SLUG, array('sightline-saved' => 1)));
        exit;
    }
}
