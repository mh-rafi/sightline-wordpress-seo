<?php

namespace Sightline\SEO\Redirects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The redirect table (R7).
 *
 * Not optional scope. Redirects are the one part of a Rank Math install
 * that cannot be recreated from content: 26 live rules on the reference
 * store, nothing outside Rank Math's own table knows they exist, and no
 * file deploy would surface them. Deactivate the plugin and 26 URLs start
 * 404ing with no error anywhere to say why.
 *
 * The row shape mirrors Rank Math's deliberately -- `sources` is a list,
 * each source has its own comparison type -- because a migrator that
 * flattened a multi-source rule into one pattern would quietly change what
 * that rule matches, and nobody would find out until traffic dropped.
 * Stored as JSON rather than a PHP serialisation: it survives a database
 * dump being read by something that is not PHP, which is what the export
 * in R11 needs.
 */
class Store
{
    const TABLE   = 'sightline_redirects';
    const CACHE   = 'sightline_active_redirects';
    const DB_VERSION = '1.0';

    /**
     * How large the autoloaded rule cache is allowed to get, in bytes.
     *
     * 64KB is roughly a couple of hundred rules. Past that the cost of
     * carrying it on every request outweighs saving one query on the few
     * requests that redirect.
     */
    const AUTOLOAD_MAX_BYTES = 65536;

    /** @var array|null */
    private static $rules = null;

    /**
     * @return string
     */
    public static function table()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * The comparison types a source may use, and their labels.
     *
     * @return array<string,string>
     */
    public static function comparisons()
    {
        return array(
            'exact'    => __('Exact match', 'sightline-seo'),
            'contains' => __('Contains', 'sightline-seo'),
            'start'    => __('Starts with', 'sightline-seo'),
            'end'      => __('Ends with', 'sightline-seo'),
            'regex'    => __('Regular expression', 'sightline-seo'),
        );
    }

    /**
     * The status codes a rule may send.
     *
     * 410 and 451 are destinations in their own right: a rule using one
     * takes no target URL, which is why the admin screen hides the field
     * rather than accepting one and ignoring it.
     *
     * @return array<int,string>
     */
    public static function status_codes()
    {
        return array(
            301 => __('301 Moved Permanently', 'sightline-seo'),
            302 => __('302 Found (temporary)', 'sightline-seo'),
            307 => __('307 Temporary Redirect', 'sightline-seo'),
            410 => __('410 Gone', 'sightline-seo'),
            451 => __('451 Unavailable For Legal Reasons', 'sightline-seo'),
        );
    }

    /**
     * Does a status code carry a destination?
     *
     * @param int $code
     * @return bool
     */
    public static function code_has_target($code)
    {
        return in_array((int) $code, array(301, 302, 307), true);
    }

    /**
     * Create or update the table.
     *
     * @return void
     */
    public static function install_table()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sources longtext NOT NULL,
            url_to text NOT NULL,
            header_code smallint(4) unsigned NOT NULL DEFAULT 301,
            hits bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            last_accessed datetime NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY status (status)
        ) {$collate};";

        dbDelta($sql);

        update_option('sightline_redirects_db_version', self::DB_VERSION);
    }

    /**
     * Does the table exist?
     *
     * @return bool
     */
    public static function table_exists()
    {
        global $wpdb;
        $table = self::table();
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    /**
     * Every active rule, decoded, cached for the request and in an option.
     *
     * An option rather than a transient: this is read on every front-end
     * request, and a transient that a cache flush evicts turns a redirect
     * into a 404 for however long the next query takes.
     *
     * Autoloaded only while the set is small enough to be worth carrying on
     * every request -- see build_cache(). A table of 26 rules costs nothing
     * to autoload; a table of twenty thousand would be loaded into memory on
     * every page view of the site, including the ones that never redirect.
     *
     * @return array
     */
    public static function active_rules()
    {
        if (null !== self::$rules) {
            return self::$rules;
        }

        $cached = get_option(self::CACHE, null);
        if (is_array($cached)) {
            self::$rules = $cached;
            return self::$rules;
        }

        self::$rules = self::build_cache();

        return self::$rules;
    }

    /**
     * Rebuild the active-rule cache from the table.
     *
     * @return array
     */
    public static function build_cache()
    {
        global $wpdb;

        if (!self::table_exists()) {
            update_option(self::CACHE, array(), 'yes');
            return array();
        }

        $table = self::table();
        $rows  = $wpdb->get_results("SELECT id, sources, url_to, header_code FROM {$table} WHERE status = 'active' ORDER BY id ASC", ARRAY_A);

        $rules = array();
        foreach ((array) $rows as $row) {
            $sources = self::decode_sources($row['sources']);
            if (empty($sources)) {
                continue;
            }
            $rules[] = array(
                'id'          => (int) $row['id'],
                'sources'     => $sources,
                'url_to'      => (string) $row['url_to'],
                'header_code' => (int) $row['header_code'],
            );
        }

        // Below the threshold the cache rides along with the options WP
        // already loads, and matching a request costs no query at all. Above
        // it, autoloading would put the whole redirect table in memory on
        // every request, so it is fetched only when the router asks.
        $autoload = strlen((string) maybe_serialize($rules)) <= self::AUTOLOAD_MAX_BYTES ? 'yes' : 'no';

        update_option(self::CACHE, $rules, $autoload);
        self::$rules = $rules;

        return $rules;
    }

    /**
     * @return void
     */
    public static function flush_cache()
    {
        self::$rules = null;
        delete_option(self::CACHE);
    }

    /**
     * Normalise a stored sources value into a list.
     *
     * Accepts JSON (what this plugin writes) and PHP serialisation (what an
     * import may hand over before it is re-encoded), so a half-migrated
     * table still routes.
     *
     * @param mixed $raw
     * @return array<int,array{pattern:string,comparison:string,ignore:string}>
     */
    public static function decode_sources($raw)
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded)) {
                $decoded = maybe_unserialize((string) $raw);
            }
        }

        if (!is_array($decoded)) {
            return array();
        }

        $sources = array();
        foreach ($decoded as $source) {
            if (!is_array($source) || empty($source['pattern'])) {
                continue;
            }
            $comparison = isset($source['comparison']) ? strtolower((string) $source['comparison']) : 'exact';
            if (!array_key_exists($comparison, self::comparisons())) {
                $comparison = 'exact';
            }
            $sources[] = array(
                'pattern'    => (string) $source['pattern'],
                'comparison' => $comparison,
                'ignore'     => isset($source['ignore']) ? (string) $source['ignore'] : '',
            );
        }

        return $sources;
    }

    /**
     * Insert or update a rule.
     *
     * @param array    $data {sources, url_to, header_code, status}
     * @param int|null $id
     * @return int|false The row id.
     */
    public static function save(array $data, $id = null)
    {
        global $wpdb;

        $sources = self::decode_sources(isset($data['sources']) ? $data['sources'] : array());
        if (empty($sources)) {
            return false;
        }

        $code = (int) (isset($data['header_code']) ? $data['header_code'] : 301);
        if (!array_key_exists($code, self::status_codes())) {
            $code = 301;
        }

        $url_to = self::code_has_target($code) ? esc_url_raw((string) (isset($data['url_to']) ? $data['url_to'] : '')) : '';

        $row = array(
            'sources'     => wp_json_encode(array_values($sources)),
            'url_to'      => $url_to,
            'header_code' => $code,
            'status'      => (isset($data['status']) && 'inactive' === $data['status']) ? 'inactive' : 'active',
            'updated'     => current_time('mysql', true),
        );

        $table = self::table();

        if ($id) {
            $wpdb->update($table, $row, array('id' => (int) $id), array('%s', '%s', '%d', '%s', '%s'), array('%d'));
            $result = (int) $id;
        } else {
            $row['created'] = current_time('mysql', true);
            $wpdb->insert($table, $row, array('%s', '%s', '%d', '%s', '%s', '%s'));
            $result = (int) $wpdb->insert_id;
        }

        self::flush_cache();

        return $result ? $result : false;
    }

    /**
     * @param int $id
     * @return array|null
     */
    public static function get($id)
    {
        global $wpdb;

        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $id), ARRAY_A);
        if (!$row) {
            return null;
        }
        $row['sources'] = self::decode_sources($row['sources']);

        return $row;
    }

    /**
     * @param int $id
     * @return void
     */
    public static function delete($id)
    {
        global $wpdb;

        $wpdb->delete(self::table(), array('id' => (int) $id), array('%d'));
        self::flush_cache();
    }

    /**
     * Every rule, for the admin list and the JSON export.
     *
     * @param array $args
     * @return array
     */
    public static function all(array $args = array())
    {
        global $wpdb;

        if (!self::table_exists()) {
            return array();
        }

        $args = array_merge(array(
            'status'  => '',
            'search'  => '',
            'orderby' => 'id',
            'order'   => 'DESC',
            'number'  => 0,
            'offset'  => 0,
        ), $args);

        $table  = self::table();
        $where  = array('1=1');
        $params = array();

        if ('' !== $args['status']) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }
        if ('' !== $args['search']) {
            $where[]  = '(sources LIKE %s OR url_to LIKE %s)';
            $like     = '%' . $wpdb->esc_like($args['search']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $allowed_orderby = array('id', 'hits', 'header_code', 'created', 'updated', 'last_accessed');
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'id';
        $order   = 'ASC' === strtoupper($args['order']) ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY {$orderby} {$order}";

        if ($args['number'] > 0) {
            $sql .= ' LIMIT %d OFFSET %d';
            $params[] = (int) $args['number'];
            $params[] = (int) $args['offset'];
        }

        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        foreach ($rows as &$row) {
            $row['sources'] = self::decode_sources($row['sources']);
        }
        unset($row);

        return (array) $rows;
    }

    /**
     * How many rules match the given filter.
     *
     * @param array $args
     * @return int
     */
    public static function count(array $args = array())
    {
        global $wpdb;

        if (!self::table_exists()) {
            return 0;
        }

        $table  = self::table();
        $where  = array('1=1');
        $params = array();

        if (!empty($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['search'])) {
            $where[]  = '(sources LIKE %s OR url_to LIKE %s)';
            $like     = '%' . $wpdb->esc_like($args['search']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', $where);

        return (int) ($params ? $wpdb->get_var($wpdb->prepare($sql, $params)) : $wpdb->get_var($sql));
    }

    /**
     * Record that a rule fired.
     *
     * The hit counter is how a dead rule gets found later: a rule with zero
     * hits since it was created is one whose old URL nothing links to any
     * more, and a list of those is the only way a redirect table stops
     * growing forever.
     *
     * @param int $id
     * @return void
     */
    public static function record_hit($id)
    {
        global $wpdb;

        $table = self::table();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET hits = hits + 1, last_accessed = %s WHERE id = %d",
            current_time('mysql', true),
            (int) $id
        ));
    }

    /**
     * Does a rule already exist with exactly these sources?
     *
     * The importer's idempotence rests on this: running it twice must not
     * produce 52 rules.
     *
     * @param array $sources
     * @return int Row id, or 0.
     */
    public static function find_by_sources(array $sources)
    {
        global $wpdb;

        if (!self::table_exists()) {
            return 0;
        }

        $normalised = self::decode_sources($sources);
        if (empty($normalised)) {
            return 0;
        }

        $table = self::table();
        $json  = wp_json_encode(array_values($normalised));

        return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE sources = %s LIMIT 1", $json));
    }
}
