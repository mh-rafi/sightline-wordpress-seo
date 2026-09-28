<?php

namespace Sightline\SEO\Admin;

use Sightline\SEO\Context;
use Sightline\SEO\Options;
use Sightline\SEO\Tokens;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The same panel on a user profile, for author archives.
 *
 * Author archives default to noindex, so this is a small screen -- but the
 * storage model has a row for it (spec section 4) and a site that does index
 * its authors needs somewhere to write their titles.
 */
class UserFields
{
    /**
     * @return void
     */
    public function register()
    {
        if (!Options::get('author/enabled', true)) {
            return;
        }

        add_action('show_user_profile', array($this, 'render'));
        add_action('edit_user_profile', array($this, 'render'));
        add_action('personal_options_update', array($this, 'save'));
        add_action('edit_user_profile_update', array($this, 'save'));
    }

    /**
     * @param \WP_User $user
     * @return void
     */
    public function render($user)
    {
        $context = new Context();
        $context->type      = Context::USER;
        $context->object_id = (int) $user->ID;
        $context->object    = $user;

        $author = Options::get('author', array());

        echo '<h2>' . esc_html__('Sightline SEO', 'sightline-seo') . '</h2>';
        echo '<div class="sightline-user-panel">';
        Fields::panel(Context::USER, (int) $user->ID, array(
            'title'       => Tokens::expand(isset($author['title']) ? $author['title'] : '', $context),
            'description' => Fields::preview(Tokens::expand(isset($author['description']) ? $author['description'] : '', $context)),
        ));
        echo '</div>';
    }

    /**
     * @param int $user_id
     * @return void
     */
    public function save($user_id)
    {
        if (!isset($_POST['sightline_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['sightline_nonce'])), 'sightline_save_meta')) {
            return;
        }
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        Fields::save(Context::USER, (int) $user_id);
    }
}
