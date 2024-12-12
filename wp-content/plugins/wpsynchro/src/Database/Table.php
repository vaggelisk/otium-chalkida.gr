<?php

namespace WPSynchro\Database;

use WPSynchro\Database\TableColumns;

/**
 * Class for a database table returned by masterdata
 */
class Table
{
    public $name = "";
    public $rows = 0;
    public $completed_rows = 0;
    public $row_avg_bytes = 0;
    public $data_total_bytes = 0;
    public $create_table = "";
    public $is_view = false;
    // Primary key
    public $primary_key_column = "";
    public $last_primary_key = 0;
    public $column_types;
    // State
    public $is_completed = false;
    public $temp_name = '';

    public function __construct()
    {
        $this->column_types = new TableColumns();
    }

    public function getColumnNames()
    {
        return $this->column_types->getAllColumnNames();
    }
}
