<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\DataTable\Filter;

use Piwik\DataTable\BaseFilter;
use Piwik\DataTable\Row;
use Piwik\DataTable\Simple;
use Piwik\DataTable;
use Piwik\Metrics;

/**
 * Sorts a {@link DataTable} based on the value of a specific column.
 *
 * It is possible to specify a natural sorting (see [php.net/natsort](http://php.net/natsort) for details).
 *
 * @api
 */
class Sort extends BaseFilter
{
    protected $columnToSort;
    protected $order;

    /**
     * Constructor.
     *
     * @param DataTable $table The table to eventually filter.
     * @param string $columnToSort The name of the column to sort by.
     * @param string $order order `'asc'` or `'desc'`.
     * @param bool $naturalSort Whether to use a natural sort or not (see {@link http://php.net/natsort}).
     * @param bool $recursiveSort Whether to sort all subtables or not.
     */
    public function __construct($table, $columnToSort, $order = 'desc', $naturalSort = true, $recursiveSort = false)
    {
        parent::__construct($table);

        if ($recursiveSort) {
            $table->enableRecursiveSort();
        }

        $this->columnToSort = $columnToSort;
        $this->naturalSort  = $naturalSort;
        $this->setOrder($order);
    }

    /**
     * Updates the order
     *
     * @param string $order asc|desc
     */
    public function setOrder($order)
    {
        if ($order == 'asc') {
            $this->order = 'asc';
        } else {
            $this->order = 'desc';
        }
    }

    protected function getColumnValue(Row $row)
    {
        $value = $row->getColumn($this->columnToSort);

        if ($value === false
            || is_array($value)
        ) {
            return null;
        }

        return $value;
    }

    /**
     * Sets the column to be used for sorting
     *
     * @param Row $row
     * @return int
     */
    protected function selectColumnToSort($row)
    {
        $value = $row->getColumn($this->columnToSort);
        if ($value !== false) {
            return $this->columnToSort;
        }

        $columnIdToName = Metrics::getMappingFromNameToId();
        // sorting by "nb_visits" but the index is Metrics::INDEX_NB_VISITS in the table
        if (isset($columnIdToName[$this->columnToSort])) {
            $column = $columnIdToName[$this->columnToSort];
            $value = $row->getColumn($column);

            if ($value !== false) {
                return $column;
            }
        }

        // eg. was previously sorted by revenue_per_visit, but this table
        // doesn't have this column; defaults with nb_visits
        $column = Metrics::INDEX_NB_VISITS;
        $value = $row->getColumn($column);
        if ($value !== false) {
            return $column;
        }

        // even though this column is not set properly in the table,
        // we select it for the sort, so that the table's internal state is set properly
        return $this->columnToSort;
    }

    /**
     * See {@link Sort}.
     *
     * @param DataTable $table
     * @return mixed
     */
    public function filter($table)
    {
        if ($table instanceof Simple) {
            return;
        }

        if (empty($this->columnToSort)) {
            return;
        }

        $rows = $table->getRowsWithoutSummaryRow();
        if (count($rows) == 0) {
            return;
        }

        $row = current($rows);
        if ($row === false) {
            return;
        }

        $this->columnToSort = $this->selectColumnToSort($row);

        $this->sort($table, null);
    }

    /**
     * Sorts the DataTable rows using the supplied callback function.
     *
     * @param string $sortFlags A comparison callback compatible with {@link usort}.
     * @param string $columnSortedBy The column name `$functionCallback` sorts by. This is stored
     *                               so we can determine how the DataTable was sorted in the future.
     */
    private function sort(DataTable $table, $sortFlags)
    {
        $table->setTableSortedBy($this->columnToSort);

        $rows = $table->getRowsWithoutSummaryRow();

        // get column value and label only once for performance tweak
        $newValues = array();
        foreach ($rows as $key => $row) {
            $newValues[$key] = $this->getColumnValue($row);
        }

        if (is_null($sortFlags)) {
            $sortFlags = $this->getBestSortFlag($newValues);
        }

        $order      = SORT_DESC;
        $labelOrder = SORT_ASC;
        if ($this->order === 'asc') {
            $order      = SORT_ASC;
            $labelOrder = SORT_DESC;
        }

        if ($sortFlags === SORT_NUMERIC) {
            $labels = array();
            foreach ($rows as $key => $row) {
                $labels[$key] = $row->getColumn('label');
            }

            array_multisort($newValues, $order, $sortFlags, $labels, $labelOrder, SORT_NATURAL | SORT_FLAG_CASE, $rows);
        } else {
            array_multisort($newValues, $order, $sortFlags, $rows);
        }

        $table->setRows(array_values($rows));
        unset($rows);

        if ($table->isSortRecursiveEnabled()) {
            foreach ($table->getRows() as $row) {

                $subTable = $row->getSubtable();
                if ($subTable) {
                    $subTable->enableRecursiveSort();
                    $this->sort($subTable, $sortFlags);
                }
            }
        }

    }

    private function getBestSortFlag($newValues)
    {
        $sortFlags = SORT_STRING | SORT_FLAG_CASE;

        foreach ($newValues as $val) {
            if ($val !== false && $val !== null) {
                if (is_numeric($val)) {
                    $sortFlags = SORT_NUMERIC;
                } else {
                    if ($this->naturalSort) {
                        $sortFlags = SORT_NATURAL | SORT_FLAG_CASE;
                    } else {
                        $sortFlags = SORT_STRING | SORT_FLAG_CASE;
                    }
                }
                return $sortFlags;
            }
        }

        return $sortFlags;
    }

}
