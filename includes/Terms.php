<?php

namespace Sightline\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which term stands for a post.
 *
 * A product in three categories has three possible breadcrumb trails and
 * one correct one, so the editor picks it (R5). The reference store has 31
 * of these choices recorded in Rank Math and the migrator brings them
 * across, which is the only reason the trails survive the swap.
 */
class Terms
{
    /**
     * The primary term for a post in a taxonomy.
     *
     * Falls back to the first assigned term: that is the one the editor sees
     * selected, so it is the honest default when no choice was made.
     *
     * @param int    $post_id
     * @param string $taxonomy
     * @return \WP_Term|null
     */
    public static function primary($post_id, $taxonomy)
    {
        $post_id = (int) $post_id;

        $primary = Meta::primary_term($post_id, $taxonomy);
        if ($primary) {
            $term = get_term($primary, $taxonomy);
            if ($term instanceof \WP_Term) {
                return self::filter($term, $post_id, $taxonomy);
            }
        }

        $terms = get_the_terms($post_id, $taxonomy);
        if (is_array($terms) && !empty($terms)) {
            return self::filter($terms[0], $post_id, $taxonomy);
        }

        return self::filter(null, $post_id, $taxonomy);
    }

    /**
     * The first hierarchical public taxonomy a post type has, which is the
     * one a breadcrumb trail should walk.
     *
     * @param string $post_type
     * @return string ''
     */
    public static function trail_taxonomy($post_type)
    {
        foreach (get_object_taxonomies($post_type, 'objects') as $taxonomy) {
            if ($taxonomy->public && $taxonomy->hierarchical) {
                /**
                 * Filter the taxonomy a post type's breadcrumb trail walks.
                 *
                 * @param string $name
                 * @param string $post_type
                 */
                return (string) apply_filters('sightline_trail_taxonomy', $taxonomy->name, $post_type);
            }
        }

        return (string) apply_filters('sightline_trail_taxonomy', '', $post_type);
    }

    /**
     * @param \WP_Term|null $term
     * @param int           $post_id
     * @param string        $taxonomy
     * @return \WP_Term|null
     */
    private static function filter($term, $post_id, $taxonomy)
    {
        /**
         * Filter the resolved primary term.
         *
         * @param \WP_Term|null $term
         * @param int           $post_id
         * @param string        $taxonomy
         */
        return apply_filters('sightline_primary_term', $term, $post_id, $taxonomy);
    }
}
