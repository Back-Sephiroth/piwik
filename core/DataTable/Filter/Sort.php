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
            $this->order = 'asc';
        } else {
            $this->order = 'desc';
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

        $this->columnToSort = $this->selectColumnToSort($row);
        $this->secondaryColumnToSort = $this->selectSecondaryColumnToSort($row, $this->columnToSort);

        $this->sort($table, null);
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
     * Get the secondary sort column to be used for sorting
     *
     * @param Row $row
     * @param string|int $firstColumnToSort
     * @return int
     */
    protected function selectSecondaryColumnToSort($row, $firstColumnToSort)
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

        $rowsWithValues = array();
        $rowsWithoutValues = array();

        // get column value and label only once for performance tweak
        $newValues = array();
        foreach ($rows as $key => $row) {
            $value = $this->getColumnValue($row);
            if (isset($value)) {
                $newValues[] = $value;
                $rowsWithValues[] = $row;
            } else {
                $rowsWithoutValues[] = $row;
            }
        }
        unset($rows);

        if (is_null($sortFlags)) {
            $sortFlags = $this->getBestSortFlags(reset($newValues));
        }

        $order = SORT_DESC;
        if ($this->order === 'asc') {
            $order = SORT_ASC;
        }

        if ($sortFlags === SORT_NUMERIC && $this->secondaryColumnToSort) {
            $secondaryValues = array();
            foreach ($rowsWithValues as $key => $row) {
                $secondaryValues[$key] = $row->getColumn($this->secondaryColumnToSort);
            }

            $secondarySortFlag = $sortFlags;
            $secondaryColumnOrder = $order;

            if ($this->secondaryColumnToSort === 'label') {
                $secondarySortFlag = SORT_NATURAL | SORT_FLAG_CASE;
                $secondaryColumnOrder = SORT_ASC;
                if ($this->order === 'asc') {
                    $secondaryColumnOrder = SORT_DESC;
                }
            }

            array_multisort($newValues, $order, $sortFlags, $secondaryValues, $secondaryColumnOrder, $secondarySortFlag, $rowsWithValues);

            if (!empty($rowsWithoutValues)) {
                $secondaryValues = array();
                foreach ($rowsWithoutValues as $key => $row) {
                    $secondaryValues[$key] = $row->getColumn($this->secondaryColumnToSort);
                }

                array_multisort($secondaryValues, $secondaryColumnOrder, $secondarySortFlag, $rowsWithoutValues);
            }

        } else {
            array_multisort($newValues, $order, $sortFlags, $rowsWithValues);
        }

        foreach ($rowsWithoutValues as $row) {
            $rowsWithValues[] = $row;
        }

        $rowsWithValues = array_values($rowsWithValues);
        $table->setRows($rowsWithValues);
        unset($rowsWithValues);
        unset($rowsWithoutValues);

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

}
