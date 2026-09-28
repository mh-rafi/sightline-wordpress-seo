<?php

namespace Sightline\SEO\Redirects;

use Sightline\SEO\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Matching a request against the redirect table and acting on it (R7).
 *
 * Runs on `parse_request`, before the query is built, because a rule may
 * point away from a URL that would otherwise resolve to a real page --
 * waiting for a 404 would only catch half of them.
 *
 * Reads a pre-decoded rule list out of one option, so the cost on a request
 * that matches nothing is one already-autoloaded option and a loop, not a
 * query.
 */
class Router
{
    /** @var int The status a matched "gone" rule will send. */
    private $gone_code = 0;

    /**
     * @return void
     */
    public function register()
    {
        if (!Options::get('redirects/enabled', true)) {
            return;
        }

        add_action('parse_request', array($this, 'maybe_redirect'), 1);
    }

    /**
     * @return void
     */
    public function maybe_redirect()
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }
        if (!isset($_SERVER['REQUEST_URI'])) {
            return;
        }
        if (isset($_SERVER['REQUEST_METHOD']) && !in_array(strtoupper((string) $_SERVER['REQUEST_METHOD']), array('GET', 'HEAD'), true)) {
            return;
        }

        $uri = $this->request_path();
        if (null === $uri) {
            return;
        }

        /**
         * Filter the path a request is matched against.
         *
         * Return an array to offer more than one candidate; they are
         * tried in order and the first match wins. A multilingual
         * plugin uses this to let one rule cover every language: it
         * offers the path as requested and then the same path with the
         * language prefix removed, so 'downloads/x' fires for
         * '/es/downloads/x' without the rule being stored N times.
         *
         * Order matters. The path as requested is offered first so that
         * a rule deliberately stored *with* a prefix -- a retired slug
         * that only ever existed in one language -- still matches
         * before the stripped fallback is tried.
         *
         * @param string|string[] $uri Path, no leading or trailing slash.
         * @param string          $uri The path as this router computed it.
         */
        $candidates = apply_filters('sightline_redirect_request_path', $uri, $uri);
        $candidates = is_array($candidates) ? $candidates : array($candidates);

        $rules = Store::active_rules();
        $rule = null;

        foreach ($candidates as $candidate) {
            $candidate = is_string($candidate) ? $candidate : '';
            if ('' === $candidate && '' !== $uri) {
                continue;
            }

            $rule = $this->match($candidate, $rules);
            if ($rule) {
                $uri = $candidate;
                break;
            }
        }

        if (!$rule) {
            return;
        }

        /**
         * Filter the matched rule. Return null to let the request through.
         *
         * @param array  $rule
         * @param string $uri
         */
        $rule = apply_filters('sightline_matched_redirect', $rule, $uri);
        if (empty($rule)) {
            return;
        }

        Store::record_hit($rule['id']);

        $this->send($rule);
    }

    /**
     * The request path this plugin compares against, without the site's own
     * subdirectory, without a leading slash and without the query string.
     *
     * A rule stored as `downloads/matx-react-admin` has to match whether the
     * site lives at the domain root or in a subdirectory, which is why the
     * home path is stripped rather than assumed absent.
     *
     * @return string|null
     */
    public function request_path()
    {
        $uri = (string) wp_unslash($_SERVER['REQUEST_URI']);

        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        if ('' === $path) {
            return null;
        }

        $home_path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        if ($home_path && '/' !== $home_path && 0 === strpos($path, $home_path)) {
            $path = substr($path, strlen($home_path));
        }

        $path = ltrim(rawurldecode($path), '/');

        // A trailing slash is a formatting choice, not part of the identity
        // of a URL. Rules are stored without one and compared without one.
        return rtrim($path, '/');
    }

    /**
     * The first rule matching a path.
     *
     * @param string $uri
     * @param array  $rules
     * @return array|null
     */
    public function match($uri, array $rules)
    {
        foreach ($rules as $rule) {
            foreach ($rule['sources'] as $source) {
                if ($this->source_matches($uri, $source)) {
                    return $rule;
                }
            }
        }

        return null;
    }

    /**
     * Does one source match a path?
     *
     * Every comparison type Rank Math supports, because reading only
     * `pattern` and assuming an exact match -- the shortcut a naive
     * migrator takes -- quietly changes what a rule matches.
     *
     * @param string $uri
     * @param array  $source
     * @return bool
     */
    public function source_matches($uri, array $source)
    {
        $pattern = trim((string) $source['pattern']);
        if ('' === $pattern) {
            return false;
        }

        if ('regex' === $source['comparison']) {
            // Stored bare, delimited here, so a stored pattern can never
            // smuggle in a modifier -- /e is gone from PHP but the habit of
            // not letting stored data choose modifiers is worth keeping.
            $regex = '#' . str_replace('#', '\\#', $pattern) . '#i';
            return 1 === @preg_match($regex, $uri);
        }

        $haystack = strtolower($uri);
        $needle   = strtolower(rtrim(ltrim($pattern, '/'), '/'));

        switch ($source['comparison']) {
            case 'contains':
                return '' !== $needle && false !== strpos($haystack, $needle);
            case 'start':
                return 0 === strpos($haystack, $needle);
            case 'end':
                return '' !== $needle && substr($haystack, -strlen($needle)) === $needle;
            case 'exact':
            default:
                return $haystack === $needle;
        }
    }

    /**
     * Send the response for a matched rule.
     *
     * @param array $rule
     * @return void
     */
    private function send(array $rule)
    {
        $code = (int) $rule['header_code'];

        if (!Store::code_has_target($code)) {
            // 410 and 451 are the destination rather than a signpost to
            // one, so the response has to survive the rest of the request:
            // status_header() called here, before the query has run, is
            // overwritten by whatever WP_Query concludes -- and on a URL
            // that still resolves, redirect_canonical() sends a 301 over
            // the top of it. Deferred to template_redirect at priority 0,
            // where the 404 flag makes redirect_canonical stand down and
            // the status sticks.
            $this->gone_code = $code;
            add_action('template_redirect', array($this, 'send_gone'), 0);

            // A "gone" rule can name a URL that still resolves to a real
            // page -- an EDD page moved under /checkout/, say. Core would
            // then 301 the visitor to the live copy on template_redirect
            // and the 410 never reaches the crawler. The rule is the more
            // specific instruction, so canonicalisation stands down for
            // this request only.
            remove_action('template_redirect', 'redirect_canonical');
            add_filter('old_slug_redirect_post_id', '__return_false');

            return;
        }

        $target = (string) $rule['url_to'];
        if ('' === $target) {
            return;
        }

        // A relative target is resolved against the site, so a table
        // exported from one environment still works in another -- which
        // matters here, where every stored target carries localhost.
        if (!preg_match('#^https?://#i', $target)) {
            $target = home_url('/' . ltrim($target, '/'));
        }

        // A rule pointing at the URL that matched it is an infinite loop.
        // It should not be possible to save one, but a `contains` rule can
        // create one that no validation would have caught.
        if (untrailingslashit($target) === untrailingslashit($this->current_url())) {
            return;
        }

        nocache_headers();
        wp_redirect($target, $code, 'Sightline SEO');
        exit;
    }

    /**
     * Finish a request matched by a 410 or 451 rule.
     *
     * The theme's 404 template still renders, so a person following a dead
     * link sees the site rather than a bare status line, while a crawler is
     * told plainly that the URL is finished rather than merely missing.
     *
     * @return void
     */
    public function send_gone()
    {
        if (!$this->gone_code) {
            return;
        }

        global $wp_query;
        if ($wp_query instanceof \WP_Query) {
            $wp_query->set_404();
        }

        status_header($this->gone_code);
        nocache_headers();
    }

    /**
     * @return string
     */
    private function current_url()
    {
        $scheme = is_ssl() ? 'https' : 'http';
        $host   = isset($_SERVER['HTTP_HOST']) ? (string) wp_unslash($_SERVER['HTTP_HOST']) : '';
        $uri    = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';

        return $scheme . '://' . $host . $uri;
    }
}
