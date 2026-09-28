<?php

namespace Sightline\SEO\Sitemap;

use Sightline\SEO\Integrations\EDD;
use Sightline\SEO\Meta;
use Sightline\SEO\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What belongs in a sitemap, and what does not.
 *
 * R6 says "exclude everything R8 marks noindex", so the exclusions are
 * derived from the indexation rules rather than configured a second time.
 * Configuring them twice is how a site ends up telling crawlers not to
 * index a page and then handing them a sitemap that lists it -- which is
 * precisely what the reference store did with checkout, my-account and
 * every purchase page.
 *
 * Written as SQL rather than WP_Query with a meta_query because a sitemap
 * is one page of a possibly very long list and does not need post objects,
 * their meta caches or their term caches hydrated to print a URL.
 */
class Query
{
    /**
     * Post IDs never listed, whatever their own settings say.
     *
     * @return int[]
     */
    public static function excluded_post_ids()
    {
        $ids = array();

        if (Options::get('store/noindex_transactional', true)) {
            $ids = EDD::transactional_page_ids();
        }

        /**
         * Filter the posts excluded from every sitemap.
         *
         * @param int[] $ids
         */
        return array_values(array_unique(array_map('intval', (array) apply_filters('sightline_sitemap_excluded_posts', $ids))));
    }

    /**
     * @param string $post_type
     * @return int
     */
    public static function count_posts($post_type)
    {
        global $wpdb;

        $sql = 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' p ' . self::post_where($post_type);

        return (int) $wpdb->get_var($wpdb->prepare($sql, $post_type));
    }

    /**
     * One page of a post type's URLs.
     *
     * @param string $post_type
     * @param int    $limit
     * @param int    $offset
     * @return array<int,array{loc:string,lastmod:string,images:string[]}>
     */
    public static function posts($post_type, $limit, $offset)
    {
        global $wpdb;

        $sql = 'SELECT p.ID, p.post_modified_gmt FROM ' . $wpdb->posts . ' p '
            . self::post_where($post_type)
            . ' ORDER BY p.post_modified_gmt DESC LIMIT %d OFFSET %d';

        $rows = $wpdb->get_results($wpdb->prepare($sql, $post_type, (int) $limit, (int) $offset));

        $with_images = (bool) Options::get('sitemap/images', true);
        $urls = array();

        foreach ((array) $rows as $row) {
            $permalink = get_permalink((int) $row->ID);
            if (!$permalink) {
                continue;
            }

            $images = array();
            if ($with_images) {
                $thumbnail = get_the_post_thumbnail_url((int) $row->ID, 'full');
                if ($thumbnail) {
                    $images[] = $thumbnail;
                }
            }

            $urls[] = array(
                'loc'     => $permalink,
                'lastmod' => gmdate('c', strtotime($row->post_modified_gmt . ' UTC')),
                'images'  => $images,
            );
        }

        /**
         * Filter a post type's sitemap entries.
         *
         * @param array  $urls
         * @param string $post_type
         */
        return (array) apply_filters('sightline_sitemap_post_urls', $urls, $post_type);
    }

    /**
     * The most recent modification date in a post type, for the index.
     *
     * @param string $post_type
     * @return string
     */
    public static function latest_post_date($post_type)
    {
        global $wpdb;

        $sql = 'SELECT p.post_modified_gmt FROM ' . $wpdb->posts . ' p '
            . self::post_where($post_type)
            . ' ORDER BY p.post_modified_gmt DESC LIMIT 1';

        $date = $wpdb->get_var($wpdb->prepare($sql, $post_type));

        return $date ? gmdate('c', strtotime($date . ' UTC')) : '';
    }

    /**
     * @param string $taxonomy
     * @return int
     */
    public static function count_terms($taxonomy)
    {
        return count(self::term_ids($taxonomy, 0, 0));
    }

    /**
     * One page of a taxonomy's URLs.
     *
     * @param string $taxonomy
     * @param int    $limit
     * @param int    $offset
     * @return array<int,array{loc:string,lastmod:string,images:string[]}>
     */
    public static function terms($taxonomy, $limit, $offset)
    {
        $ids = self::term_ids($taxonomy, $limit, $offset);

        $urls = array();
        foreach ($ids as $term_id) {
            $link = get_term_link((int) $term_id, $taxonomy);
            if (is_wp_error($link)) {
                continue;
            }
            $urls[] = array('loc' => (string) $link, 'lastmod' => '', 'images' => array());
        }

        /**
         * Filter a taxonomy's sitemap entries.
         *
         * @param array  $urls
         * @param string $taxonomy
         */
        return (array) apply_filters('sightline_sitemap_term_urls', $urls, $taxonomy);
    }

    /**
     * Term IDs for a taxonomy, minus the ones marked noindex.
     *
     * Empty terms are left out: an archive with nothing on it is a thin page
     * whether or not anybody set a flag on it.
     *
     * @param string $taxonomy
     * @param int    $limit  0 for all.
     * @param int    $offset
     * @return int[]
     */
    private static function term_ids($taxonomy, $limit, $offset)
    {
        $args = array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
            'fields'     => 'ids',
            'orderby'    => 'term_id',
            'order'      => 'ASC',
            'meta_query' => array(
                'relation' => 'OR',
                array('key' => Meta::key('robots'), 'compare' => 'NOT EXISTS'),
                array('key' => Meta::key('robots'), 'value' => 'noindex', 'compare' => 'NOT LIKE'),
            ),
        );

        if ($limit > 0) {
            $args['number'] = (int) $limit;
            $args['offset'] = (int) $offset;
        }

        $ids = get_terms($args);

        return is_wp_error($ids) ? array() : array_map('intval', (array) $ids);
    }

    /**
     * The shared WHERE clause. The caller supplies the post type as the
     * first prepare() argument.
     *
     * @param string $post_type
     * @return string
     */
    private static function post_where($post_type)
    {
        global $wpdb;

        $where = " WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_password = ''";

        // A per-object noindex is stored as an array, so the serialised
        // value is matched rather than compared. Narrow enough: the only
        // values this key ever holds are robots directives.
        $where .= ' AND NOT EXISTS (SELECT 1 FROM ' . $wpdb->postmeta . ' m'
            . ' WHERE m.post_id = p.ID AND m.meta_key = "' . esc_sql(Meta::key('robots')) . '"'
            . ' AND m.meta_value LIKE "%noindex%")';

        $excluded = self::excluded_post_ids();
        if (!empty($excluded)) {
            $where .= ' AND p.ID NOT IN (' . implode(',', array_map('intval', $excluded)) . ')';
        }

        return $where;
    }
}
