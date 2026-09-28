<?php

namespace Sightline\SEO;

use Sightline\SEO\Admin\Admin;
use Sightline\SEO\Frontend\Head;
use Sightline\SEO\Frontend\Robots;
use Sightline\SEO\Integrations\EDD;
use Sightline\SEO\Redirects\Router;
use Sightline\SEO\Redirects\Store;
use Sightline\SEO\Schema\Graph;
use Sightline\SEO\Sitemap\Sitemaps;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bootstrap and wiring.
 *
 * One place that knows which components exist and in what order they hook,
 * so "what does this plugin do to a page" is answerable by reading a single
 * method rather than grepping for add_action.
 */
class Plugin
{
    /** @var self|null */
    private static $instance = null;

    /** @var array<string,object> */
    private $components = array();

    /**
     * @return self
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
            self::$instance->boot();
        }
        return self::$instance;
    }

    /**
     * @return void
     */
    private function boot()
    {
        load_plugin_textdomain('sightline-seo', false, dirname(plugin_basename(SIGHTLINE_FILE)) . '/languages');

        // Redirects run before anything renders, and on every request
        // including ones that would otherwise 404.
        $this->components['redirects'] = new Router();
        $this->components['redirects']->register();

        $this->components['sitemaps'] = new Sitemaps();
        $this->components['sitemaps']->register();

        if (is_admin()) {
            $this->components['admin'] = new Admin();
            $this->components['admin']->register();
        } else {
            $this->components['head'] = new Head();
            $this->components['head']->register();

            $this->components['robots'] = new Robots();
            $this->components['robots']->register();

            $this->components['graph'] = new Graph();
            $this->components['graph']->register();
        }

        // Late, so the store's own plugin has registered its filters first.
        add_action('wp', array($this, 'hand_over'), 5);

        add_action('admin_init', array($this, 'maybe_upgrade'));
    }

    /**
     * Take structured data over from whatever emits it today.
     *
     * @return void
     */
    public function hand_over()
    {
        EDD::take_over_microdata();
        EDD::suppress_duplicate_robots();
    }

    /**
     * @param string $name
     * @return object|null
     */
    public function component($name)
    {
        return isset($this->components[$name]) ? $this->components[$name] : null;
    }

    /**
     * @return void
     */
    public static function activate()
    {
        Store::install_table();

        $stored = get_option(Options::KEY, null);
        if (null === $stored) {
            update_option(Options::KEY, self::first_run_settings());
        }

        // The sitemap lives on rewrite rules, and R3 of the audit found the
        // site advertising a sitemap URL that 404s. Flushing on activation
        // is what keeps that window closed.
        Sitemaps::add_rewrite_rules();
        flush_rewrite_rules(false);

        update_option('sightline_version', SIGHTLINE_VERSION);
    }

    /**
     * @return void
     */
    public static function deactivate()
    {
        flush_rewrite_rules(false);
    }

    /**
     * Run the table install and rewrite flush after a version bump.
     *
     * @return void
     */
    public function maybe_upgrade()
    {
        if (get_option('sightline_version') === SIGHTLINE_VERSION) {
            return;
        }

        Store::install_table();
        Sitemaps::add_rewrite_rules();
        flush_rewrite_rules(false);

        update_option('sightline_version', SIGHTLINE_VERSION);
    }

    /**
     * Settings for a site that has never had this plugin.
     *
     * Deliberately almost empty. Everything else has a working default in
     * Options::defaults(), including R8's noindex on the store's own
     * transactional pages -- those are resolved from the store's settings
     * on each request rather than copied here, so they stay right when the
     * store's pages change.
     *
     * @return array
     */
    private static function first_run_settings()
    {
        $settings = array();

        $name = get_bloginfo('name');
        if ($name) {
            $settings['organization'] = array('name' => $name);
        }

        return $settings;
    }
}
