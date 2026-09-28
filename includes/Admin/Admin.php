<?php

namespace Sightline\SEO\Admin;

use Sightline\SEO\Admin\Screen\Import;
use Sightline\SEO\Admin\Screen\Redirects;
use Sightline\SEO\Admin\Screen\Settings;
use Sightline\SEO\Admin\Screen\Tools;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The admin side.
 *
 * Every screen here exists because of DEPLOY.md: this site deploys files
 * and never its database, so anything that lives in the database has to be
 * enterable on production through a screen. That is why the Rank Math
 * migration is an admin page rather than a WP-CLI command, and why the
 * settings export is JSON a person can carry between two installs.
 *
 * No dashboard widget, no notices on other plugins' screens, no upsell
 * (R11). A plugin that nags is a plugin people uninstall angry.
 */
class Admin
{
    const CAPABILITY = 'manage_options';
    const SLUG       = 'sightline-seo';

    /** @var array<string,object> */
    private $screens = array();

    /**
     * @return void
     */
    public function register()
    {
        $this->screens = array(
            'settings'  => new Settings(),
            'redirects' => new Redirects(),
            'import'    => new Import(),
            'tools'     => new Tools(),
        );

        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));

        foreach ($this->screens as $screen) {
            if (method_exists($screen, 'register')) {
                $screen->register();
            }
        }

        (new MetaBox())->register();
        (new TermFields())->register();
        (new UserFields())->register();
    }

    /**
     * @return void
     */
    public function menu()
    {
        add_menu_page(
            __('Sightline SEO', 'sightline-seo'),
            __('Sightline SEO', 'sightline-seo'),
            self::CAPABILITY,
            self::SLUG,
            array($this->screens['settings'], 'render'),
            'dashicons-visibility',
            58
        );

        $pages = array(
            self::SLUG                 => __('Settings', 'sightline-seo'),
            self::SLUG . '-redirects'  => __('Redirects', 'sightline-seo'),
            self::SLUG . '-import'     => __('Import', 'sightline-seo'),
            self::SLUG . '-tools'      => __('Tools', 'sightline-seo'),
        );

        $callbacks = array(
            self::SLUG                => array($this->screens['settings'], 'render'),
            self::SLUG . '-redirects' => array($this->screens['redirects'], 'render'),
            self::SLUG . '-import'    => array($this->screens['import'], 'render'),
            self::SLUG . '-tools'     => array($this->screens['tools'], 'render'),
        );

        foreach ($pages as $slug => $label) {
            add_submenu_page(self::SLUG, $label, $label, self::CAPABILITY, $slug, $callbacks[$slug]);
        }
    }

    /**
     * @param string $hook
     * @return void
     */
    public function assets($hook)
    {
        $is_own_screen = false !== strpos((string) $hook, self::SLUG);
        $is_editor     = in_array($hook, array('post.php', 'post-new.php', 'term.php', 'edit-tags.php', 'user-edit.php', 'profile.php'), true);

        if (!$is_own_screen && !$is_editor) {
            return;
        }

        wp_enqueue_style(
            'sightline-admin',
            SIGHTLINE_URL . 'assets/admin.css',
            array(),
            SIGHTLINE_VERSION
        );

        if ($is_editor || $is_own_screen) {
            wp_enqueue_media();
            wp_enqueue_script(
                'sightline-admin',
                SIGHTLINE_URL . 'assets/admin.js',
                array('jquery'),
                SIGHTLINE_VERSION,
                true
            );
            wp_localize_script('sightline-admin', 'sightlineAdmin', array(
                'chooseImage' => __('Choose an image', 'sightline-seo'),
                'useImage'    => __('Use this image', 'sightline-seo'),
                'ajaxUrl'     => admin_url('admin-ajax.php'),
                'importNonce' => wp_create_nonce('sightline_import'),
            ));
        }
    }

    /**
     * A page URL within this menu.
     *
     * @param string $page
     * @param array  $args
     * @return string
     */
    public static function url($page = self::SLUG, array $args = array())
    {
        return add_query_arg(array_merge(array('page' => $page), $args), admin_url('admin.php'));
    }

    /**
     * The tab strip shared by every screen.
     *
     * @param string $current
     * @return void
     */
    public static function tabs($current)
    {
        $tabs = array(
            self::SLUG                => __('Settings', 'sightline-seo'),
            self::SLUG . '-redirects' => __('Redirects', 'sightline-seo'),
            self::SLUG . '-import'    => __('Import', 'sightline-seo'),
            self::SLUG . '-tools'     => __('Tools', 'sightline-seo'),
        );

        echo '<h2 class="nav-tab-wrapper sightline-tabs">';
        foreach ($tabs as $slug => $label) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url(self::url($slug)),
                $slug === $current ? ' nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</h2>';
    }
}
