<?php
/**
 * Plugin Name:       Sightline SEO
 * Plugin URI:        https://github.com/uilibrary/sightline-seo
 * Description:       Titles, descriptions, social cards, structured data, sitemaps and redirects — with correct Product schema for digital-download stores, and a migrator out of Rank Math.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Peak Pulse
 * Author URI:        https://ui-lib.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sightline-seo
 * Domain Path:       /languages
 *
 * @package Sightline\SEO
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SIGHTLINE_VERSION', '1.0.0');
define('SIGHTLINE_FILE', __FILE__);
define('SIGHTLINE_DIR', plugin_dir_path(__FILE__));
define('SIGHTLINE_URL', plugin_dir_url(__FILE__));

/**
 * Autoloader.
 *
 * Sightline\SEO\Schema\Product -> includes/Schema/Product.php
 *
 * Hand-rolled rather than Composer's so the plugin ships without a
 * vendor directory: there are no third-party dependencies to manage,
 * and a vendor/ tree is one more thing a wordpress.org review has to
 * read through.
 */
spl_autoload_register(function ($class) {
    $prefix = 'Sightline\\SEO\\';
    if (0 !== strncmp($prefix, $class, strlen($prefix))) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = SIGHTLINE_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

register_activation_hook(__FILE__, array('Sightline\\SEO\\Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('Sightline\\SEO\\Plugin', 'deactivate'));

add_action('plugins_loaded', array('Sightline\\SEO\\Plugin', 'instance'), 5);
