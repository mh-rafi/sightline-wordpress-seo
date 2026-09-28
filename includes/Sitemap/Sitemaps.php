<?php

namespace Sightline\SEO\Sitemap;

use Sightline\SEO\Options;
use Sightline\SEO\Text;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * XML sitemaps (R6).
 *
 * An index plus one child per included type. The URLs keep Rank Math's
 * shape -- `/sitemap_index.xml` and `/{type}-sitemap.xml` -- because Search
 * Console, robots.txt and every crawler that has already fetched this site
 * know those addresses, and changing them at the same moment as changing
 * plugins turns one migration into two.
 *
 * The gap this closes: with Rank Math deactivated, `/sitemap_index.xml`
 * 404s and so does core's `/wp-sitemap.xml`, even after a rewrite flush --
 * core's sitemap does not take over. Meanwhile robots.txt keeps advertising
 * a sitemap URL. Between removing one plugin and shipping this, the site
 * points crawlers at a sitemap that does not exist.
 *
 * Exclusions follow R8 rather than being configured twice: anything marked
 * noindex is not in the sitemap. On the reference store the sitemap listed
 * checkout, my-account and every purchase page.
 */
class Sitemaps
{
    const QUERY_VAR = 'sightline_sitemap';
    const PAGE_VAR  = 'sightline_sitemap_page';

    /**
     * @return void
     */
    public function register()
    {
        if (!Options::get('sitemap/enabled', true)) {
            return;
        }

        add_action('init', array(__CLASS__, 'add_rewrite_rules'));
        add_filter('query_vars', array($this, 'query_vars'));
        add_action('parse_request', array($this, 'maybe_render'), 5);
        add_filter('robots_txt', array($this, 'robots_txt'), 10, 2);

        // Two sitemaps at two addresses is two things for a crawler to
        // reconcile, and core's is the one nothing links to.
        add_filter('wp_sitemaps_enabled', '__return_false');
    }

    /**
     * @return void
     */
    public static function add_rewrite_rules()
    {
        add_rewrite_rule('^sitemap_index\.xml$', 'index.php?' . self::QUERY_VAR . '=index', 'top');
        add_rewrite_rule('^sitemap\.xsl$', 'index.php?' . self::QUERY_VAR . '=stylesheet', 'top');
        add_rewrite_rule('^([a-z0-9_\-]+)-sitemap([0-9]+)?\.xml$', 'index.php?' . self::QUERY_VAR . '=$matches[1]&' . self::PAGE_VAR . '=$matches[2]', 'top');
    }

    /**
     * @param string[] $vars
     * @return string[]
     */
    public function query_vars($vars)
    {
        $vars[] = self::QUERY_VAR;
        $vars[] = self::PAGE_VAR;
        return $vars;
    }

    /**
     * @param \WP $wp
     * @return void
     */
    public function maybe_render($wp)
    {
        $type = isset($wp->query_vars[self::QUERY_VAR]) ? (string) $wp->query_vars[self::QUERY_VAR] : '';
        if ('' === $type) {
            return;
        }

        $page = isset($wp->query_vars[self::PAGE_VAR]) ? max(1, (int) $wp->query_vars[self::PAGE_VAR]) : 1;

        if ('stylesheet' === $type) {
            $this->render_stylesheet();
        } elseif ('index' === $type) {
            $this->render_index();
        } else {
            $this->render_child($type, $page);
        }

        exit;
    }

    /**
     * Advertise the index in robots.txt.
     *
     * @param string $output
     * @param mixed  $public
     * @return string
     */
    public function robots_txt($output, $public)
    {
        if (!$public) {
            return $output;
        }

        $line = 'Sitemap: ' . self::index_url();
        if (false === strpos($output, $line)) {
            $output = rtrim($output) . "\n\n" . $line . "\n";
        }

        return $output;
    }

    /**
     * @return string
     */
    public static function index_url()
    {
        return home_url('/sitemap_index.xml');
    }

    /**
     * @param string $type
     * @param int    $page
     * @return string
     */
    public static function child_url($type, $page = 1)
    {
        $suffix = $page > 1 ? (string) $page : '';
        return home_url('/' . $type . '-sitemap' . $suffix . '.xml');
    }

    /**
     * The types with something to list.
     *
     * @return array<string,array{type:string,name:string}>
     */
    public function included_types()
    {
        $types = array();

        $configured_pt = (array) Options::get('sitemap/post_types', array());
        foreach (get_post_types(array('public' => true), 'objects') as $post_type) {
            if ('attachment' === $post_type->name) {
                continue;
            }
            $default = Options::post_type_defaults($post_type->name);
            $included = array_key_exists($post_type->name, $configured_pt)
                ? (bool) $configured_pt[$post_type->name]
                : !empty($default['in_sitemap']);
            if ($included && !$default['noindex']) {
                $types[$post_type->name] = array('type' => 'post_type', 'name' => $post_type->name);
            }
        }

        $configured_tax = (array) Options::get('sitemap/taxonomies', array());
        foreach (get_taxonomies(array('public' => true), 'objects') as $taxonomy) {
            if (in_array($taxonomy->name, array('post_format'), true)) {
                continue;
            }
            $default = Options::taxonomy_defaults($taxonomy->name);
            $included = array_key_exists($taxonomy->name, $configured_tax)
                ? (bool) $configured_tax[$taxonomy->name]
                : !empty($default['in_sitemap']);
            if ($included && !$default['noindex']) {
                $types[$taxonomy->name] = array('type' => 'taxonomy', 'name' => $taxonomy->name);
            }
        }

        /**
         * Filter the sitemap's child types.
         *
         * @param array $types
         */
        return (array) apply_filters('sightline_sitemap_types', $types);
    }

    /**
     * @return void
     */
    private function render_index()
    {
        $entries = array();

        foreach ($this->included_types() as $key => $type) {
            $total = 'post_type' === $type['type']
                ? Query::count_posts($type['name'])
                : Query::count_terms($type['name']);

            if ($total < 1) {
                // An empty child sitemap is a URL a crawler fetches for
                // nothing, and an index that lists one is advertising it.
                continue;
            }

            $pages = (int) ceil($total / $this->per_page());
            for ($page = 1; $page <= $pages; $page++) {
                $entries[] = array(
                    'loc'     => self::child_url($key, $page),
                    'lastmod' => 'post_type' === $type['type'] ? Query::latest_post_date($type['name']) : '',
                );
            }
        }

        $this->headers();

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?xml-stylesheet type="text/xsl" href="' . esc_url(home_url('/sitemap.xsl')) . '"?>' . "\n";
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $entry) {
            echo "  <sitemap>\n";
            echo '    <loc>' . esc_url($entry['loc']) . "</loc>\n";
            if ('' !== $entry['lastmod']) {
                echo '    <lastmod>' . esc_html($entry['lastmod']) . "</lastmod>\n";
            }
            echo "  </sitemap>\n";
        }
        echo '</sitemapindex>';
    }

    /**
     * @param string $type
     * @param int    $page
     * @return void
     */
    private function render_child($type, $page)
    {
        $types = $this->included_types();
        if (!isset($types[$type])) {
            $this->not_found();
            return;
        }

        $per_page = $this->per_page();
        $offset   = ($page - 1) * $per_page;

        $urls = 'post_type' === $types[$type]['type']
            ? Query::posts($types[$type]['name'], $per_page, $offset)
            : Query::terms($types[$type]['name'], $per_page, $offset);

        if (empty($urls)) {
            $this->not_found();
            return;
        }

        $with_images = (bool) Options::get('sitemap/images', true);

        $this->headers();

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?xml-stylesheet type="text/xsl" href="' . esc_url(home_url('/sitemap.xsl')) . '"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        foreach ($urls as $url) {
            echo "  <url>\n";
            echo '    <loc>' . esc_url($url['loc']) . "</loc>\n";
            if (!empty($url['lastmod'])) {
                echo '    <lastmod>' . esc_html($url['lastmod']) . "</lastmod>\n";
            }
            if ($with_images && !empty($url['images'])) {
                foreach ($url['images'] as $image) {
                    echo "    <image:image>\n";
                    echo '      <image:loc>' . esc_url($image) . "</image:loc>\n";
                    echo "    </image:image>\n";
                }
            }
            echo "  </url>\n";
        }
        echo '</urlset>';
    }

    /**
     * A stylesheet, so a person opening the sitemap sees a table.
     *
     * @return void
     */
    private function render_stylesheet()
    {
        header('Content-Type: application/xslt+xml; charset=UTF-8');
        header('X-Robots-Tag: noindex, follow', true);

        $title = esc_html(Text::plain(get_bloginfo('name')));

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        ?>
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9">
  <xsl:output method="html" encoding="UTF-8" indent="yes"/>
  <xsl:template match="/">
    <html>
      <head>
        <title><?php echo $title; ?> — XML sitemap</title>
        <style>
          body { font: 14px/1.5 -apple-system, system-ui, sans-serif; margin: 2rem; color: #1a1a1a; }
          h1 { font-size: 1.25rem; }
          table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
          th, td { text-align: left; padding: .4rem .6rem; border-bottom: 1px solid #e5e5e5; }
          th { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #666; }
          p.count { color: #666; }
        </style>
      </head>
      <body>
        <h1><?php echo $title; ?> — XML sitemap</h1>
        <xsl:if test="s:sitemapindex">
          <p class="count"><xsl:value-of select="count(s:sitemapindex/s:sitemap)"/> sitemaps.</p>
          <table>
            <tr><th>Sitemap</th><th>Last modified</th></tr>
            <xsl:for-each select="s:sitemapindex/s:sitemap">
              <tr>
                <td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td>
                <td><xsl:value-of select="s:lastmod"/></td>
              </tr>
            </xsl:for-each>
          </table>
        </xsl:if>
        <xsl:if test="s:urlset">
          <p class="count"><xsl:value-of select="count(s:urlset/s:url)"/> URLs.</p>
          <table>
            <tr><th>URL</th><th>Last modified</th></tr>
            <xsl:for-each select="s:urlset/s:url">
              <tr>
                <td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td>
                <td><xsl:value-of select="s:lastmod"/></td>
              </tr>
            </xsl:for-each>
          </table>
        </xsl:if>
      </body>
    </html>
  </xsl:template>
</xsl:stylesheet>
        <?php
    }

    /**
     * @return int
     */
    private function per_page()
    {
        $per_page = (int) Options::get('sitemap/per_page', 500);

        // The specification's ceiling is 50,000; staying well under it keeps
        // the files small enough to open in a browser.
        return max(50, min(2000, $per_page));
    }

    /**
     * @return void
     */
    private function headers()
    {
        if (headers_sent()) {
            return;
        }
        header('Content-Type: application/xml; charset=UTF-8');
        header('X-Robots-Tag: noindex, follow', true);
    }

    /**
     * @return void
     */
    private function not_found()
    {
        status_header(404);
        nocache_headers();
        $this->headers();
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
    }
}
