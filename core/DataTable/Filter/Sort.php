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
use Piwik\Plugin\Metric;

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
    protected $secondaryColumnToSort;
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
            $this->order = SORT_ASC;
        } else {
            $this->order = SORT_DESC;
        }
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

        if (!$table->getRowsCountWithoutSummaryRow()) {
            return;
        }

        $row = $table->getFirstRow();

        if ($row === false) {
            return;
        }

        $this->columnToSort = $this->selectColumnToSort($table, $row);
        $this->secondaryColumnToSort = $this->selectSecondaryColumnToSort($row, $this->columnToSort);

        $this->sort($table);
    }

    private function getColumnValue(Row $row)
    {
        $value = $row->getColumn($this->columnToSort);

        if ($value === false || is_array($value)) {
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
    private function selectColumnToSort(DataTable $table, $row)
    {
        // we fallback to nb_visits in case columnToSort does not exist
        $columnsToCheck = array($this->columnToSort, Metrics::INDEX_NB_VISITS);

        foreach ($columnsToCheck as $column) {
            $column = Metric::getActualMetricColumn($table, $column);

            $value = $row->getColumn($column);
            if ($value !== false) {
                return $column;
            }
        }

        // even though this column is not set properly in the table,
        // we select it for the sort, so that the table's internal state is set properly
        return $this->columnToSort;
    }

    /**
     * Get the secondary sort column to be used for sorting
     *
     * @param Row $row
     * @param string|int $firstColumnToSort
     * @return int
     */
    private function selectSecondaryColumnToSort($row, $firstColumnToSort)
    {
        $defaultSecondaryColumn = array(Metrics::INDEX_NB_VISITS, 'nb_visits');

        if (in_array($firstColumnToSort, $defaultSecondaryColumn)) {
            // if sorted by visits, then sort by label as a secondary column
            $column = 'label';
            $value  = $row->getColumn($column);
            if ($value !== false) {
                return $column;
            }
        }

        if ($firstColumnToSort !== 'label') {
            // we do not add this by default to make sure we do not sort by label as a first and secondary column
            $defaultSecondaryColumn[] = 'label';
        }

        foreach ($defaultSecondaryColumn as $column) {
            $value = $row->getColumn($column);
            if ($value !== false) {
                return $column;
            }
        }
    }

    private function getSecondarySortColumnOrder($secondarySortColumn)
    {
        $secondaryColumnOrder = $this->order;

        if ($secondarySortColumn === 'label') {
            $secondaryColumnOrder = SORT_ASC;
            if ($this->order === SORT_ASC) {
                $secondaryColumnOrder = SORT_DESC;
            }
        }

        return $secondaryColumnOrder;
    }

    private function getSecondarySortFlags($secondarySortColumn, $defaultSortFlag)
    {
        if ($secondarySortColumn === 'label') {
            return SORT_NATURAL | SORT_FLAG_CASE;
        }

        return $defaultSortFlag;
    }

    /**
     * Sorts the DataTable rows using the supplied callback function.
     *
     * @param DataTable $table The table to sort.
     * @param int $sortFlags PHP Sort flags, eg SORT_NUMERIC. Will be automatically detected initially.
     */
    private function sort(DataTable $table, $sortFlags = null)
    {
        $table->setTableSortedBy($this->columnToSort);

        list($rowsWithValues, $rowsWithoutValues, $valuesToSort) = $this->getRowsToSort($table);

        if (is_null($sortFlags)) {
            $sortFlags = $this->getBestSortFlags(reset($valuesToSort));
        }

        if ($sortFlags === SORT_NUMERIC && $this->secondaryColumnToSort) {
            $secondaryValues = array();
            foreach ($rowsWithValues as $key => $row) {
                $secondaryValues[$key] = $row->getColumn($this->secondaryColumnToSort);
            }

            $secondarySortFlag    = $this->getSecondarySortFlags($this->secondaryColumnToSort, $sortFlags);
            $secondaryColumnOrder = $this->getSecondarySortColumnOrder($this->secondaryColumnToSort);

            array_multisort($valuesToSort, $this->order, $sortFlags, $secondaryValues, $secondaryColumnOrder, $secondarySortFlag, $rowsWithValues);

            if (!empty($rowsWithoutValues)) {
                $secondaryValues = array();
                foreach ($rowsWithoutValues as $key => $row) {
                    $secondaryValues[$key] = $row->getColumn($this->secondaryColumnToSort);
                }

                array_multisort($secondaryValues, $secondaryColumnOrder, $secondarySortFlag, $rowsWithoutValues);
            }
            unset($secondaryValues);

        } else {
            array_multisort($valuesToSort, $this->order, $sortFlags, $rowsWithValues);
        }

        foreach ($rowsWithoutValues as $row) {
            $rowsWithValues[] = $row;
        }

        $rowsWithValues = array_values($rowsWithValues);
        $table->setRows($rowsWithValues);

        unset($rowsWithValues);
        unset($rowsWithoutValues);

        if ($table->isSortRecursiveEnabled()) {
            $this->sortRecursive($table, $sortFlags);
        }
    }

    private function getRowsToSort(DataTable $table)
    {
        $rows = $table->getRowsWithoutSummaryRow();

        // we need to sort rows that have a value separately from rows that do not have a value since we always want
        // to append rows that do not have a value at the end.
        $rowsWithValues    = array();
        $rowsWithoutValues = array();

        $valuesToSort = array();
        foreach ($rows as $key => $row) {
            $value = $this->getColumnValue($row);
            if (isset($value)) {
                $valuesToSort[] = $value;
                $rowsWithValues[] = $row;
            } else {
                $rowsWithoutValues[] = $row;
            }
        }

        unset($rows);

        return array($rowsWithValues, $rowsWithoutValues, $valuesToSort);
    }

    private function getBestSortFlags($value)
    {
        if (is_numeric($value)) {
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

    private function sortRecursive(DataTable $table, $sortFlags)
    {
        foreach ($table->getRows() as $row) {

            $subTable = $row->getSubtable();
            if ($subTable) {
                $subTable->enableRecursiveSort();
                $this->sort($subTable, $sortFlags);
            }
        }
    }

}
