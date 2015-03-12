<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik\Metrics;

use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\Metrics;
use Piwik\Plugin\Metric;
use Piwik\Plugin\Report;

class Sorter
{
    /**
     * @var Sorter\Config
     */
    private $config;

    public function __construct(Sorter\Config $config)
    {
        $this->config = $config;
    }

    /**
     * Sorts the DataTable rows using the supplied callback function.
     *
     * @param DataTable $table The table to sort.
     */
    public function sort(DataTable $table)
    {
        $table->setTableSortedBy($this->config->primaryColumnToSort);

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

        if ($this->config->primarySortFlags === SORT_NUMERIC && $this->config->secondaryColumnToSort) {

            $secondaryValues = array();
            foreach ($rowsWithValues as $key => $row) {
                $secondaryValues[$key] = $row->getColumn($this->config->secondaryColumnToSort);
            }

            array_multisort($valuesToSort, $this->config->primarySortOrder, $this->config->primarySortFlags, $secondaryValues, $this->config->secondarySortOrder, $this->config->secondarySortFlags, $rowsWithValues);

            if (!empty($rowsWithoutValues)) {
                $secondaryValues = array();
                foreach ($rowsWithoutValues as $key => $row) {
                    $secondaryValues[$key] = $row->getColumn($this->config->secondaryColumnToSort);
                }

                array_multisort($secondaryValues, $this->config->secondarySortOrder, $this->config->secondarySortFlags, $rowsWithoutValues);
            }

            unset($secondaryValues);

        } else {
            array_multisort($valuesToSort, $this->config->primarySortOrder, $this->config->primarySortFlags, $rowsWithValues);
        }

        foreach ($rowsWithoutValues as $row) {
            $rowsWithValues[] = $row;
        }

        $rowsWithValues = array_values($rowsWithValues);
        $table->setRows($rowsWithValues);

        unset($rowsWithValues);
        unset($rowsWithoutValues);
    }

    private function getColumnValue(Row $row)
    {
        $value = $row->getColumn($this->config->primaryColumnToSort);

        if ($value === false || is_array($value)) {
            return null;
        }

        return $value;
    }

    /**
     * @param string $order   'asc' or 'desc'
     * @return int
     */
    public function getPrimarySortOrder($order)
    {
        if ($order === 'asc') {
            return SORT_ASC;
        }

        return SORT_DESC;
    }

    /**
     * @param string $order   'asc' or 'desc'
     * @param string|int $secondarySortColumn  column name or column id
     * @return int
     */
    public function getSecondarySortColumnOrder($order, $secondarySortColumn)
    {
        if ($secondarySortColumn === 'label') {

            $secondaryOrder = SORT_ASC;
            if ($order === 'asc') {
                $secondaryOrder = SORT_DESC;
            }

            return $secondaryOrder;
        }

        return $this->getPrimarySortOrder($order);
    }

    /**
     * Detect the column to be used for sorting
     *
     * @param DataTable $table
     * @param string|int $columnToSort  column name or column id
     * @return int
     */
    public function getPrimaryColumnToSort(DataTable $table, $columnToSort)
    {
        // we fallback to nb_visits in case columnToSort does not exist
        $columnsToCheck = array($columnToSort, Metrics::INDEX_NB_VISITS);

        $row = $table->getFirstRow();

        foreach ($columnsToCheck as $column) {
            $column = Metric::getActualMetricColumn($table, $column);

            if ($row->hasColumn($column)) {
                // since getActualMetricColumn() returns a default value, we need to make sure it actually has that column
                return $column;
            }
        }
    }

    /**
     * Detect the secondary sort column to be used for sorting
     *
     * @param Row $row
     * @param int|string $primaryColumnToSort
     * @return int
     */
    public function getSecondaryColumnToSort(Row $row, $primaryColumnToSort)
    {
        $defaultSecondaryColumn = array(Metrics::INDEX_NB_VISITS, 'nb_visits');

        if (in_array($primaryColumnToSort, $defaultSecondaryColumn)) {
            // if sorted by visits, then sort by label as a secondary column
            $column = 'label';
            $value  = $row->getColumn($column);
            if ($value !== false) {
                return $column;
            }
        }

        if ($primaryColumnToSort !== 'label') {
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

    public function getBestSortFlags(DataTable $table, $columnToSort)
    {
        if ($columnToSort === 'label') {
            return SORT_NATURAL | SORT_FLAG_CASE;
        }

        foreach ($table->getRows() as $row) {
            $value = $row->getColumn($columnToSort);

            if ($value !== false && $value !== null && !is_array($value)) {

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

        return SORT_NATURAL | SORT_FLAG_CASE;
    }


}