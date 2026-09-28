<?php

namespace Sightline\SEO\Import;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What an import run did, and what it did not.
 *
 * "Honest about what it skipped, and why. Never claim a clean run." That
 * rule is in the spec because it is what caught a page in this project
 * whose only copy of its content would otherwise have been destroyed. An
 * importer that prints "done" and a number is an importer nobody can check.
 *
 * Kept in an option across batches, because a run happens over many
 * requests and the report has to survive all of them to be worth anything.
 */
class Report
{
    const OPTION = 'sightline_import_report';

    /** @var array */
    private $data;

    /**
     * @param array $data
     */
    public function __construct(array $data = array())
    {
        $this->data = array_merge(array(
            'started'   => '',
            'finished'  => '',
            'source'    => 'rank-math',
            'imported'  => array(),
            'skipped'   => array(),
            'notes'     => array(),
            'progress'  => array(),
        ), $data);
    }

    /**
     * @return self
     */
    public static function load()
    {
        $stored = get_option(self::OPTION, array());
        return new self(is_array($stored) ? $stored : array());
    }

    /**
     * @return self
     */
    public static function start()
    {
        $report = new self(array('started' => current_time('mysql', true)));
        $report->save();
        return $report;
    }

    /**
     * @return void
     */
    public static function clear()
    {
        delete_option(self::OPTION);
    }

    /**
     * Count something that was written.
     *
     * @param string $key
     * @param int    $n
     * @return void
     */
    public function imported($key, $n = 1)
    {
        if (!isset($this->data['imported'][$key])) {
            $this->data['imported'][$key] = 0;
        }
        $this->data['imported'][$key] += (int) $n;
    }

    /**
     * Record something that was not written, and why.
     *
     * Grouped by reason with a sample of the objects involved, so a report
     * covering 300 rows is still readable but still names names.
     *
     * @param string $reason
     * @param string $subject
     * @return void
     */
    public function skipped($reason, $subject = '')
    {
        if (!isset($this->data['skipped'][$reason])) {
            $this->data['skipped'][$reason] = array('count' => 0, 'examples' => array());
        }
        $this->data['skipped'][$reason]['count']++;
        if ('' !== $subject && count($this->data['skipped'][$reason]['examples']) < 12) {
            $this->data['skipped'][$reason]['examples'][] = $subject;
        }
    }

    /**
     * A standing observation about the source data, said once.
     *
     * @param string $note
     * @return void
     */
    public function note($note)
    {
        if (!in_array($note, $this->data['notes'], true)) {
            $this->data['notes'][] = $note;
        }
    }

    /**
     * @param string $step
     * @param int    $done
     * @param int    $total
     * @return void
     */
    public function progress($step, $done, $total)
    {
        $this->data['progress'][$step] = array('done' => (int) $done, 'total' => (int) $total);
    }

    /**
     * @return void
     */
    public function finish()
    {
        $this->data['finished'] = current_time('mysql', true);
        $this->save();
    }

    /**
     * @return void
     */
    public function save()
    {
        update_option(self::OPTION, $this->data, false);
    }

    /**
     * @return array
     */
    public function data()
    {
        return $this->data;
    }

    /**
     * @return bool
     */
    public function is_empty()
    {
        return empty($this->data['started']);
    }
}
