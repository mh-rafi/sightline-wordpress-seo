<?php

namespace Sightline\SEO\Admin;

use Sightline\SEO\Context;
use Sightline\SEO\Options;
use Sightline\SEO\Tokens;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The same panel on a term edit screen.
 *
 * Terms carry 9 titles and 9 descriptions on the reference store, all of
 * which the migrator brings across -- so a term editing UI is not optional
 * scope either, or those 9 become uneditable the moment Rank Math goes.
 */
class TermFields
{
    /**
     * @return void
     */
    public function register()
    {
        foreach (get_taxonomies(array('public' => true), 'names') as $taxonomy) {
            add_action($taxonomy . '_edit_form', array($this, 'render'), 10, 2);
            add_action('edited_' . $taxonomy, array($this, 'save'));
            add_action('created_' . $taxonomy, array($this, 'save'));
        }
    }

    /**
     * @param \WP_Term $term
     * @param string   $taxonomy
     * @return void
     */
    public function render($term, $taxonomy)
    {
        $context = new Context();
        $context->type      = Context::TERM;
        $context->object_id = (int) $term->term_id;
        $context->subtype   = $taxonomy;
        $context->object    = $term;

        $defaults = Options::taxonomy_defaults($taxonomy);

        echo '<h2 class="sightline-term-heading">' . esc_html__('Sightline SEO', 'sightline-seo') . '</h2>';
        echo '<div class="sightline-term-panel">';
        Fields::panel(Context::TERM, (int) $term->term_id, array(
            'title'       => Tokens::expand($defaults['title'], $context),
            'description' => Fields::preview(Tokens::expand($defaults['description'], $context)),
        ));
        echo '</div>';
    }

    /**
     * @param int $term_id
     * @return void
     */
    public function save($term_id)
    {
        if (!isset($_POST['sightline_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['sightline_nonce'])), 'sightline_save_meta')) {
            return;
        }
        if (!current_user_can('edit_term', $term_id)) {
            return;
        }

        Fields::save(Context::TERM, (int) $term_id);
    }
}
