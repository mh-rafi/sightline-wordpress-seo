<?php

namespace Sightline\SEO\Import;

use Sightline\SEO\Context;
use Sightline\SEO\Meta;
use Sightline\SEO\Options;
use Sightline\SEO\Redirects\Store;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Migrating from Rank Math (R10).
 *
 * The flagship. All of this is database state, so it cannot travel with
 * plugin files -- which is why it is an admin screen that runs on
 * production rather than a WP-CLI command (spec section 10, DEPLOY.md).
 *
 * Four promises, each of which shapes the code below:
 *
 *   Non-destructive. Nothing Rank Math wrote is deleted or altered. Both
 *   plugins can sit side by side while the result is checked, and rolling
 *   back is deactivating one of them.
 *
 *   Idempotent. A second run changes nothing. Every write checks for an
 *   existing Sightline value first and skips unless overwrite is asked for,
 *   and redirects are matched on their full source list rather than
 *   inserted blind.
 *
 *   Resumable in batches. 327 robots rows is nothing here; somebody else's
 *   site is not this site.
 *
 *   Honest. Every skip has a reason and the reason reaches the report.
 */
class RankMath
{
    const BATCH = 50;

    /**
     * The ordered steps, and their labels.
     *
     * @return array<string,string>
     */
    public static function steps()
    {
        return array(
            'settings'  => __('Global settings and templates', 'sightline-seo'),
            'posts'     => __('Posts, pages and products', 'sightline-seo'),
            'terms'     => __('Taxonomy terms', 'sightline-seo'),
            'redirects' => __('Redirects', 'sightline-seo'),
        );
    }

    /**
     * Is there anything to import?
     *
     * Deliberately independent of whether Rank Math is active: the data
     * outlives the plugin, and someone who deactivated it before finding
     * this screen should still be able to rescue their redirects.
     *
     * @return bool
     */
    public static function has_data()
    {
        $counts = self::counts();
        foreach ($counts as $n) {
            if ($n > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * What is there, by key. Shown before anything is written, so the
     * screen can say what it is about to touch.
     *
     * @return array<string,int>
     */
    public static function counts()
    {
        global $wpdb;

        $counts = array();

        $counts['post_meta'] = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key LIKE 'rank\\_math\\_%' AND meta_value <> ''"
        );
        $counts['term_meta'] = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->termmeta} WHERE meta_key LIKE 'rank\\_math\\_%' AND meta_value <> ''"
        );
        $counts['redirects'] = self::redirect_table_exists()
            ? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'rank_math_redirections')
            : 0;
        $counts['settings'] = is_array(get_option('rank-math-options-titles', null)) ? 1 : 0;

        return $counts;
    }

    /**
     * Run one batch.
     *
     * @param string $step
     * @param int    $offset
     * @param bool   $overwrite
     * @return array{done:bool,offset:int,total:int,processed:int}
     */
    public static function run($step, $offset = 0, $overwrite = false)
    {
        $report = Report::load();
        if ($report->is_empty()) {
            $report = Report::start();
        }

        switch ($step) {
            case 'settings':
                $result = self::import_settings($report, $overwrite);
                break;
            case 'posts':
                $result = self::import_posts($report, (int) $offset, $overwrite);
                break;
            case 'terms':
                $result = self::import_terms($report, (int) $offset, $overwrite);
                break;
            case 'redirects':
                $result = self::import_redirects($report, (int) $offset);
                break;
            default:
                $result = array('done' => true, 'offset' => 0, 'total' => 0, 'processed' => 0);
        }

        $report->progress($step, $result['offset'], $result['total']);
        $report->save();

        return $result;
    }

    /* ---------------------------------------------------------------- */
    /* Settings                                                          */
    /* ---------------------------------------------------------------- */

    /**
     * @param Report $report
     * @param bool   $overwrite
     * @return array
     */
    private static function import_settings(Report $report, $overwrite)
    {
        $titles = get_option('rank-math-options-titles', array());
        if (!is_array($titles) || empty($titles)) {
            $report->skipped(__('No Rank Math title settings were found', 'sightline-seo'));
            return array('done' => true, 'offset' => 0, 'total' => 0, 'processed' => 0);
        }

        $stored = get_option(Options::KEY, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        if (!empty($titles['title_separator']) && ($overwrite || empty($stored['title_separator']))) {
            $stored['title_separator'] = (string) $titles['title_separator'];
            $report->imported('title_separator');
        }

        // Home.
        $home = isset($stored['home']) && is_array($stored['home']) ? $stored['home'] : array();
        foreach (array('homepage_title' => 'title', 'homepage_description' => 'description') as $from => $to) {
            if (!empty($titles[$from]) && ($overwrite || empty($home[$to]))) {
                $home[$to] = Tokens::translate($titles[$from], $report, 'home/' . $to);
                $report->imported('home_' . $to);
            }
        }
        if (!empty($home)) {
            $stored['home'] = $home;
        }

        // Per post type and per taxonomy templates.
        $post_types = isset($stored['post_types']) && is_array($stored['post_types']) ? $stored['post_types'] : array();
        foreach (get_post_types(array('public' => true), 'names') as $post_type) {
            $entry = isset($post_types[$post_type]) ? $post_types[$post_type] : array();
            foreach (array('title', 'description') as $field) {
                $key = 'pt_' . $post_type . '_' . $field;
                if (!empty($titles[$key]) && ($overwrite || empty($entry[$field]))) {
                    $entry[$field] = Tokens::translate($titles[$key], $report, $post_type . '/' . $field);
                    $report->imported('post_type_template');
                }
            }
            $custom = 'on' === (isset($titles['pt_' . $post_type . '_custom_robots']) ? $titles['pt_' . $post_type . '_custom_robots'] : 'off');
            $robots = isset($titles['pt_' . $post_type . '_robots']) ? (array) $titles['pt_' . $post_type . '_robots'] : array();
            if ($custom && !empty($robots)) {
                $entry['noindex']  = in_array('noindex', $robots, true);
                $entry['nofollow'] = in_array('nofollow', $robots, true);
            }
            if (!empty($entry)) {
                $post_types[$post_type] = $entry;
            }
        }
        if (!empty($post_types)) {
            $stored['post_types'] = $post_types;
        }

        $taxonomies = isset($stored['taxonomies']) && is_array($stored['taxonomies']) ? $stored['taxonomies'] : array();
        foreach (get_taxonomies(array('public' => true), 'names') as $taxonomy) {
            $entry = isset($taxonomies[$taxonomy]) ? $taxonomies[$taxonomy] : array();
            foreach (array('title', 'description') as $field) {
                $key = 'tax_' . $taxonomy . '_' . $field;
                if (!empty($titles[$key]) && ($overwrite || empty($entry[$field]))) {
                    $entry[$field] = Tokens::translate($titles[$key], $report, $taxonomy . '/' . $field);
                    $report->imported('taxonomy_template');
                }
            }
            $custom = 'on' === (isset($titles['tax_' . $taxonomy . '_custom_robots']) ? $titles['tax_' . $taxonomy . '_custom_robots'] : 'off');
            $robots = isset($titles['tax_' . $taxonomy . '_robots']) ? (array) $titles['tax_' . $taxonomy . '_robots'] : array();
            if ($custom && !empty($robots)) {
                $entry['noindex']  = in_array('noindex', $robots, true);
                $entry['nofollow'] = in_array('nofollow', $robots, true);
            }
            if (!empty($entry)) {
                $taxonomies[$taxonomy] = $entry;
            }
        }
        if (!empty($taxonomies)) {
            $stored['taxonomies'] = $taxonomies;
        }

        // Author archives.
        $author = isset($stored['author']) && is_array($stored['author']) ? $stored['author'] : array();
        if (!empty($titles['author_archive_title']) && ($overwrite || empty($author['title']))) {
            $author['title'] = Tokens::translate($titles['author_archive_title'], $report, 'author/title');
        }
        if ('on' === (isset($titles['author_custom_robots']) ? $titles['author_custom_robots'] : 'off')) {
            $author['noindex'] = in_array('noindex', (array) (isset($titles['author_robots']) ? $titles['author_robots'] : array()), true);
        }
        $author['enabled'] = 'on' !== (isset($titles['disable_author_archives']) ? $titles['disable_author_archives'] : 'off');
        $stored['author'] = $author;

        if (isset($titles['noindex_search'])) {
            $stored['search'] = array('noindex' => 'on' === $titles['noindex_search']);
        }

        // Organisation and social. Rank Math keeps one social URL field
        // here, which is the reason the reference site advertises one
        // profile while its footer links five -- so the import brings the
        // one across and says the rest have to be typed in.
        $organization = isset($stored['organization']) && is_array($stored['organization']) ? $stored['organization'] : array();
        if (!empty($titles['knowledgegraph_name']) && ($overwrite || empty($organization['name']))) {
            $organization['name'] = (string) $titles['knowledgegraph_name'];
            $report->imported('organization_name');
        }
        if (!empty($titles['knowledgegraph_type']) && ($overwrite || empty($organization['type']))) {
            $organization['type'] = 'company' === $titles['knowledgegraph_type'] ? 'Organization' : 'Person';
        }
        if (!empty($titles['knowledgegraph_logo_id']) && ($overwrite || empty($organization['logo']))) {
            $organization['logo'] = (string) (int) $titles['knowledgegraph_logo_id'];
            $report->imported('organization_logo');
        }
        $stored['organization'] = $organization;

        $social = isset($stored['social']) && is_array($stored['social']) ? $stored['social'] : array();
        $profiles = isset($social['profiles']) && is_array($social['profiles']) ? $social['profiles'] : array();
        foreach (array('social_url_facebook', 'twitter_author_names') as $key) {
            if (!empty($titles[$key]) && filter_var($titles[$key], FILTER_VALIDATE_URL)) {
                $profiles[] = esc_url_raw((string) $titles[$key]);
            }
        }
        $social['profiles'] = array_values(array_unique(array_filter($profiles)));
        if (count($social['profiles']) < 2) {
            $report->note(__('Rank Math stores a single social profile URL. Add the rest by hand in Settings → Social profiles; every one of them is emitted as sameAs on the Organization.', 'sightline-seo'));
        }
        if (!empty($titles['open_graph_image_id']) && ($overwrite || empty($social['default_image']))) {
            $social['default_image'] = (string) (int) $titles['open_graph_image_id'];
            $report->imported('default_share_image');
        } elseif (!empty($titles['open_graph_image']) && ($overwrite || empty($social['default_image']))) {
            $social['default_image'] = (string) $titles['open_graph_image'];
            $report->imported('default_share_image');
        }
        if (!empty($titles['twitter_card_type']) && empty($social['card_type'])) {
            $social['card_type'] = 'summary' === $titles['twitter_card_type'] ? 'summary' : 'summary_large_image';
        }
        $stored['social'] = $social;

        Options::save($stored);

        return array('done' => true, 'offset' => 0, 'total' => 1, 'processed' => 1);
    }

    /* ---------------------------------------------------------------- */
    /* Posts                                                             */
    /* ---------------------------------------------------------------- */

    /**
     * @param Report $report
     * @param int    $offset
     * @param bool   $overwrite
     * @return array
     */
    private static function import_posts(Report $report, $offset, $overwrite)
    {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key LIKE 'rank\\_math\\_%' AND meta_value <> ''"
        );

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE 'rank\\_math\\_%' AND meta_value <> '' ORDER BY post_id ASC LIMIT %d OFFSET %d",
            self::BATCH,
            (int) $offset
        ));

        foreach ($ids as $post_id) {
            self::import_one_post($report, (int) $post_id, $overwrite);
        }

        $processed = count($ids);
        $offset   += $processed;

        return array(
            'done'      => 0 === $processed || $offset >= $total,
            'offset'    => $offset,
            'total'     => $total,
            'processed' => $processed,
        );
    }

    /**
     * @param Report $report
     * @param int    $post_id
     * @param bool   $overwrite
     * @return void
     */
    private static function import_one_post(Report $report, $post_id, $overwrite)
    {
        $subject = self::describe_post($post_id);

        self::copy_text($report, Context::POST, $post_id, 'rank_math_title', 'title', $overwrite, $subject, true);
        self::copy_text($report, Context::POST, $post_id, 'rank_math_description', 'description', $overwrite, $subject, true);
        self::copy_text($report, Context::POST, $post_id, 'rank_math_canonical_url', 'canonical', $overwrite, $subject, false);
        self::copy_text($report, Context::POST, $post_id, 'rank_math_focus_keyword', 'focus_keyword', $overwrite, $subject, false);

        self::copy_robots($report, Context::POST, $post_id, $overwrite, $subject);
        self::copy_og_image($report, $post_id, $overwrite, $subject);
        self::copy_primary_terms($report, $post_id, $overwrite, $subject);
    }

    /* ---------------------------------------------------------------- */
    /* Terms                                                             */
    /* ---------------------------------------------------------------- */

    /**
     * @param Report $report
     * @param int    $offset
     * @param bool   $overwrite
     * @return array
     */
    private static function import_terms(Report $report, $offset, $overwrite)
    {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->termmeta} WHERE meta_key LIKE 'rank\\_math\\_%' AND meta_value <> ''"
        );

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key LIKE 'rank\\_math\\_%' AND meta_value <> '' ORDER BY term_id ASC LIMIT %d OFFSET %d",
            self::BATCH,
            (int) $offset
        ));

        foreach ($ids as $term_id) {
            $term_id = (int) $term_id;
            $term    = get_term($term_id);
            $subject = $term instanceof \WP_Term ? $term->name . ' (#' . $term_id . ')' : '#' . $term_id;

            self::copy_text($report, Context::TERM, $term_id, 'rank_math_title', 'title', $overwrite, $subject, true);
            self::copy_text($report, Context::TERM, $term_id, 'rank_math_description', 'description', $overwrite, $subject, true);
            self::copy_text($report, Context::TERM, $term_id, 'rank_math_canonical_url', 'canonical', $overwrite, $subject, false);
            self::copy_text($report, Context::TERM, $term_id, 'rank_math_focus_keyword', 'focus_keyword', $overwrite, $subject, false);
            self::copy_robots($report, Context::TERM, $term_id, $overwrite, $subject);
        }

        $processed = count($ids);
        $offset   += $processed;

        return array(
            'done'      => 0 === $processed || $offset >= $total,
            'offset'    => $offset,
            'total'     => $total,
            'processed' => $processed,
        );
    }

    /* ---------------------------------------------------------------- */
    /* Redirects                                                         */
    /* ---------------------------------------------------------------- */

    /**
     * @param Report $report
     * @param int    $offset
     * @return array
     */
    private static function import_redirects(Report $report, $offset)
    {
        global $wpdb;

        if (!self::redirect_table_exists()) {
            $report->skipped(__('Rank Math has no redirects table on this site', 'sightline-seo'));
            return array('done' => true, 'offset' => 0, 'total' => 0, 'processed' => 0);
        }

        if (!Store::table_exists()) {
            Store::install_table();
        }

        $source = $wpdb->prefix . 'rank_math_redirections';
        $total  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$source}");

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, sources, url_to, header_code, hits, status FROM {$source} ORDER BY id ASC LIMIT %d OFFSET %d",
            self::BATCH,
            (int) $offset
        ), ARRAY_A);

        foreach ((array) $rows as $row) {
            self::import_one_redirect($report, $row);
        }

        $processed = count((array) $rows);
        $offset   += $processed;

        if ($processed > 0) {
            Store::flush_cache();
        }

        return array(
            'done'      => 0 === $processed || $offset >= $total,
            'offset'    => $offset,
            'total'     => $total,
            'processed' => $processed,
        );
    }

    /**
     * One redirect row.
     *
     * `sources` is a serialised list, each entry carrying its own comparison
     * type. Reading only `pattern` and assuming an exact match -- the
     * shortcut that looks like it works -- would quietly change what a rule
     * matches, and a rule that silently stops matching is indistinguishable
     * from a rule that was never imported.
     *
     * @param Report $report
     * @param array  $row
     * @return void
     */
    private static function import_one_redirect(Report $report, array $row)
    {
        $raw = maybe_unserialize($row['sources']);
        if (!is_array($raw) || empty($raw)) {
            $report->skipped(__('Redirect has no readable sources', 'sightline-seo'), '#' . $row['id']);
            return;
        }

        $sources = array();
        foreach ($raw as $source) {
            if (!is_array($source) || empty($source['pattern'])) {
                continue;
            }
            $comparison = isset($source['comparison']) ? strtolower((string) $source['comparison']) : 'exact';
            if (!array_key_exists($comparison, Store::comparisons())) {
                $report->skipped(
                    sprintf(
                        /* translators: %s: an unrecognised comparison type. */
                        __('Redirect source uses an unsupported comparison type "%s" and was imported as an exact match', 'sightline-seo'),
                        $comparison
                    ),
                    '#' . $row['id']
                );
                $comparison = 'exact';
            }
            $sources[] = array(
                'pattern'    => (string) $source['pattern'],
                'comparison' => $comparison,
                'ignore'     => isset($source['ignore']) ? (string) $source['ignore'] : '',
            );
        }

        if (empty($sources)) {
            $report->skipped(__('Redirect has no usable source patterns', 'sightline-seo'), '#' . $row['id']);
            return;
        }

        $summary = $sources[0]['pattern'] . ' → ' . $row['url_to'];

        if (Store::find_by_sources($sources)) {
            $report->skipped(__('Redirect already exists and was left alone', 'sightline-seo'), $summary);
            return;
        }

        $code = (int) $row['header_code'];
        if (!array_key_exists($code, Store::status_codes())) {
            $report->skipped(
                sprintf(
                    /* translators: %d: an HTTP status code. */
                    __('Redirect uses status %d, which is not offered; imported as 301', 'sightline-seo'),
                    $code
                ),
                $summary
            );
            $code = 301;
        }

        // Targets are stored absolute by Rank Math, which means an export
        // from a local environment carries localhost into production. Only
        // this site's own URLs are made relative -- an external target is
        // still a real destination and is left exactly as written.
        $url_to = (string) $row['url_to'];
        $home   = home_url('/');
        if ($url_to && 0 === strpos($url_to, $home)) {
            $url_to = '/' . ltrim(substr($url_to, strlen($home)), '/');
        }

        $id = Store::save(array(
            'sources'     => $sources,
            'url_to'      => $url_to,
            'header_code' => $code,
            'status'      => ('active' === $row['status']) ? 'active' : 'inactive',
        ));

        if ($id) {
            $report->imported('redirects');
        } else {
            $report->skipped(__('Redirect could not be written', 'sightline-seo'), $summary);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Field helpers                                                     */
    /* ---------------------------------------------------------------- */

    /**
     * Copy one text field, translating tokens where the field holds a
     * template.
     *
     * @param Report $report
     * @param string $type
     * @param int    $object_id
     * @param string $from_key
     * @param string $to_field
     * @param bool   $overwrite
     * @param string $subject
     * @param bool   $is_template
     * @return void
     */
    private static function copy_text(Report $report, $type, $object_id, $from_key, $to_field, $overwrite, $subject, $is_template)
    {
        $value = self::read_meta($type, $object_id, $from_key);
        if ('' === $value) {
            return;
        }

        $existing = Meta::get($type, $object_id, $to_field);
        if ('' !== $existing && !$overwrite) {
            $report->skipped(
                sprintf(
                    /* translators: %s: a field name. */
                    __('%s already had a value in Sightline and was left alone', 'sightline-seo'),
                    $to_field
                ),
                $subject
            );
            return;
        }

        if ($is_template) {
            $value = Tokens::translate($value, $report, $subject);
        }

        if ('' === trim($value)) {
            $report->skipped(
                sprintf(
                    /* translators: %s: a field name. */
                    __('%s was empty after translating its template tokens', 'sightline-seo'),
                    $to_field
                ),
                $subject
            );
            return;
        }

        Meta::set($type, $object_id, $to_field, $value);
        $report->imported($to_field);
    }

    /**
     * Copy the robots directives.
     *
     * Stored by Rank Math as a serialised array. `index` is not a directive
     * in Sightline -- it is the absence of `noindex` -- so a row holding
     * only `index` writes nothing. That is not a silent no-op: all 327 rows
     * on the reference store are exactly this, and a report that counted
     * them as imported would be claiming work it did not do.
     *
     * @param Report $report
     * @param string $type
     * @param int    $object_id
     * @param bool   $overwrite
     * @param string $subject
     * @return void
     */
    private static function copy_robots(Report $report, $type, $object_id, $overwrite, $subject)
    {
        $raw = self::read_meta($type, $object_id, 'rank_math_robots', false);
        $raw = maybe_unserialize($raw);
        if (!is_array($raw) || empty($raw)) {
            return;
        }

        $existing = (array) Meta::get($type, $object_id, 'robots');
        if (!empty($existing) && !$overwrite) {
            $report->skipped(__('Robots directives already set in Sightline and left alone', 'sightline-seo'), $subject);
            return;
        }

        $allowed = array('noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex');
        $directives = array_values(array_intersect($allowed, array_map('strval', $raw)));

        if (empty($directives)) {
            $report->skipped(
                __('Robots value said only "index", which is the default and stores nothing', 'sightline-seo'),
                $subject
            );
            return;
        }

        Meta::set($type, $object_id, 'robots', $directives);
        $report->imported('robots');
    }

    /**
     * Copy the social image.
     *
     * Rank Math keeps two of these. `rank_math_facebook_image_id` is what
     * the editor chose; `rank_math_og_content_image` is a cache Rank Math
     * derived from the content, and it is not the attachment ID it looks
     * like -- it is a serialised array of a checksum and a list whose
     * entries are sometimes IDs and sometimes URLs, and sometimes empty.
     * The authored value wins; the derived one is only a fallback, because
     * importing a cache as if it were a decision is how a migration invents
     * data nobody chose.
     *
     * @param Report $report
     * @param int    $post_id
     * @param bool   $overwrite
     * @param string $subject
     * @return void
     */
    private static function copy_og_image(Report $report, $post_id, $overwrite, $subject)
    {
        $existing = Meta::get(Context::POST, $post_id, 'og_image');
        if ('' !== $existing && !$overwrite) {
            return;
        }

        $value = trim((string) get_post_meta($post_id, 'rank_math_facebook_image_id', true));

        if ('' === $value) {
            $raw = maybe_unserialize(get_post_meta($post_id, 'rank_math_og_content_image', true));
            if (is_array($raw) && !empty($raw['images']) && is_array($raw['images'])) {
                $first = reset($raw['images']);
                if (is_numeric($first)) {
                    $value = (string) (int) $first;
                } elseif (is_string($first) && '' !== $first) {
                    $value = $first;
                }
            } elseif (is_array($raw)) {
                $report->skipped(__('Rank Math’s derived social image held no image', 'sightline-seo'), $subject);
                return;
            }
        }

        if ('' === $value) {
            return;
        }

        Meta::set(Context::POST, $post_id, 'og_image', $value);
        $report->imported('og_image');
    }

    /**
     * Copy the primary term choices, one key per taxonomy.
     *
     * @param Report $report
     * @param int    $post_id
     * @param bool   $overwrite
     * @param string $subject
     * @return void
     */
    private static function copy_primary_terms(Report $report, $post_id, $overwrite, $subject)
    {
        foreach (get_taxonomies(array(), 'names') as $taxonomy) {
            $value = (int) get_post_meta($post_id, 'rank_math_primary_' . $taxonomy, true);
            if ($value <= 0) {
                continue;
            }

            $key = Meta::primary_term_key($taxonomy);
            if ('' !== (string) get_post_meta($post_id, $key, true) && !$overwrite) {
                continue;
            }

            $term = get_term($value, $taxonomy);
            if (!$term instanceof \WP_Term) {
                $report->skipped(
                    sprintf(
                        /* translators: 1: term id, 2: taxonomy name. */
                        __('Primary term %1$d no longer exists in %2$s', 'sightline-seo'),
                        $value,
                        $taxonomy
                    ),
                    $subject
                );
                continue;
            }

            update_post_meta($post_id, $key, $value);
            $report->imported('primary_term');
        }
    }

    /**
     * Read a meta value from whichever table the object lives in.
     *
     * @param string $type
     * @param int    $object_id
     * @param string $key
     * @param bool   $as_string
     * @return mixed
     */
    private static function read_meta($type, $object_id, $key, $as_string = true)
    {
        switch ($type) {
            case Context::TERM:
                $value = get_term_meta($object_id, $key, true);
                break;
            case Context::USER:
                $value = get_user_meta($object_id, $key, true);
                break;
            default:
                $value = get_post_meta($object_id, $key, true);
        }

        if (!$as_string) {
            return $value;
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param int $post_id
     * @return string
     */
    private static function describe_post($post_id)
    {
        $title = get_the_title($post_id);
        return ('' !== $title ? $title : __('(no title)', 'sightline-seo')) . ' (#' . (int) $post_id . ')';
    }

    /**
     * @return bool
     */
    private static function redirect_table_exists()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'rank_math_redirections';
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    /**
     * Things in Rank Math's tables that are deliberately not imported.
     *
     * Said out loud on the screen rather than skipped in silence: these are
     * Rank Math's own working data and render nothing, but "we did not
     * bring this" is a statement the person running the migration is
     * entitled to see.
     *
     * @return array<string,string>
     */
    public static function not_imported()
    {
        return array(
            'rank_math_seo_score'               => __('Rank Math’s own content score. Renders nothing.', 'sightline-seo'),
            'rank_math_internal_links_processed'=> __('A processing flag for Rank Math’s internal link counter.', 'sightline-seo'),
            'rank_math_analytic_object_id'      => __('An identifier for Rank Math’s Analytics module.', 'sightline-seo'),
            'rank_math_news_sitemap_robots'     => __('Belongs to the News sitemap module.', 'sightline-seo'),
            'rank_math_schema_*'                => __('Rank Math’s own schema builder blocks. Sightline generates its schema from the content instead.', 'sightline-seo'),
            'wp_rank_math_analytics_*'          => __('Analytics tables — Search Console history, cached and re-fetchable.', 'sightline-seo'),
            'wp_rank_math_internal_links'       => __('Rank Math’s internal link graph, rebuilt from content.', 'sightline-seo'),
        );
    }
}
