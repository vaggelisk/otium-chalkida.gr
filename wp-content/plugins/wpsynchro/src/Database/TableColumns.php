<?php

namespace WPSynchro\Database;

/**
 * Class for a database table column data
 */
class TableColumns
{
    public $string = [];
    public $numeric = [];
    public $bit = [];
    public $binary = [];
    public $unknown = [];
    public $generated = [];
    public $column_types_used = [];

    public function __construct()
    {
    }

    public function addColumnTypeUsed($column_type)
    {
        $this->column_types_used[strtolower($column_type)] = true;
    }

    public function isColumnTypeUsed($column_type)
    {
        return isset($this->column_types_used[strtolower($column_type)]);
    }

    public function isString($column)
    {
        return isset($this->string[$column]);
    }

    public function isNumeric($column)
    {
        return isset($this->numeric[$column]);
    }

    public function isBit($column)
    {
        return isset($this->bit[$column]);
    }

    public function isBinary($column)
    {
        return isset($this->binary[$column]);
    }

    public function isGenerated($column)
    {
        return isset($this->generated[$column]);
    }

    public function getAllColumnNames()
    {
        return array_merge($this->string, $this->numeric, $this->bit, $this->binary, $this->unknown, $this->generated);
    }
}
