<?php

namespace Sightline\SEO\Admin;

use Sightline\SEO\Context;
use Sightline\SEO\Meta;
use Sightline\SEO\Options;
use Sightline\SEO\Terms;
use Sightline\SEO\Tokens;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The post editor panel.
 *
 * Adds the primary-term control on top of the shared fields, because that
 * choice only exists for posts and it is what R5's breadcrumb trail walks.
 */
class MetaBox
{
    /**
     * @return void
     */
    public function register()
    {
        add_action('add_meta_boxes', array($this, 'add'));
        add_action('save_post', array($this, 'save'), 10, 2);
    }

    /**
     * @return void
     */
    public function add()
    {
        foreach (get_post_types(array('public' => true), 'names') as $post_type) {
            if ('attachment' === $post_type) {
                continue;
            }

            /**
             * Filter whether the editor panel appears for a post type.
             *
             * @param bool   $show
             * @param string $post_type
             */
            if (!apply_filters('sightline_show_meta_box', true, $post_type)) {
                continue;
            }

            add_meta_box(
                'sightline-seo',
                __('Sightline SEO', 'sightline-seo'),
                array($this, 'render'),
                $post_type,
                'normal',
                'default'
            );
        }
    }

    /**
     * @param \WP_Post $post
     * @return void
     */
    public function render($post)
    {
        $context = new Context();
        $context->type      = Context::POST;
        $context->object_id = (int) $post->ID;
        $context->subtype   = $post->post_type;
        $context->object    = $post;

        $defaults = Options::post_type_defaults($post->post_type);

        Fields::panel(Context::POST, (int) $post->ID, array(
            'title'       => Tokens::expand($defaults['title'], $context),
            'description' => Fields::preview(Tokens::expand($defaults['description'], $context)),
        ));

        $this->primary_terms($post);
    }

    /**
     * The primary-term control, one per hierarchical public taxonomy.
     *
     * Only rendered when the post is in more than one term of a taxonomy:
     * with one term there is no choice to make, and a select with a single
     * option is a question that answers itself.
     *
     * @param \WP_Post $post
     * @return void
     */
    private function primary_terms($post)
    {
        $taxonomies = get_object_taxonomies($post->post_type, 'objects');

        foreach ($taxonomies as $taxonomy) {
            if (!$taxonomy->public || !$taxonomy->hierarchical) {
                continue;
            }

            $terms = get_the_terms($post->ID, $taxonomy->name);
            if (!is_array($terms) || count($terms) < 2) {
                continue;
            }

            $current = Meta::primary_term($post->ID, $taxonomy->name);
            if (!$current) {
                $resolved = Terms::primary($post->ID, $taxonomy->name);
                $current  = $resolved instanceof \WP_Term ? (int) $resolved->term_id : 0;
            }

            printf('<p class="sightline-field"><label for="sightline_primary_%1$s"><strong>%2$s</strong></label>',
                esc_attr($taxonomy->name),
                esc_html(sprintf(
                    /* translators: %s: taxonomy singular name. */
                    __('Primary %s', 'sightline-seo'),
                    strtolower($taxonomy->labels->singular_name)
                ))
            );

            printf('<select id="sightline_primary_%1$s" name="sightline[primary_term][%1$s]" class="widefat">', esc_attr($taxonomy->name));
            printf('<option value="0">%s</option>', esc_html__('— none —', 'sightline-seo'));
            foreach ($terms as $term) {
                printf(
                    '<option value="%d"%s>%s</option>',
                    (int) $term->term_id,
                    selected($current, (int) $term->term_id, false),
                    esc_html($term->name)
                );
            }
            echo '</select>';

            printf('<span class="sightline-hint">%s</span></p>', esc_html__('Used in the breadcrumb trail and in the structured data for this page.', 'sightline-seo'));
        }
    }

    /**
     * @param int      $post_id
     * @param \WP_Post $post
     * @return void
     */
    public function save($post_id, $post)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!isset($_POST['sightline_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['sightline_nonce'])), 'sightline_save_meta')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        Fields::save(Context::POST, (int) $post_id);
    }
}
