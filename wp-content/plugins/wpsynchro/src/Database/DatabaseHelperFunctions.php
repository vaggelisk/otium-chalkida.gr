<?php

namespace WPSynchro\Database;

/**
 * Database helper functions
 */
class DatabaseHelperFunctions
{
    /**
     *  Handle table prefix name changes, if needed
     */
    public static function handleTablePrefixChange($table_name, $source_prefix, $target_prefix)
    {

        // Check if we need to change prefixes
        if ($source_prefix != $target_prefix) {
            if (substr($table_name, 0, strlen($source_prefix)) == $source_prefix) {
                $table_name = substr($table_name, strlen($source_prefix));
                $table_name = $target_prefix . $table_name;
            }
        }
        return $table_name;
    }

    /**
     *  Check if specific table is being moved, by search for table name ends with X
     */
    public static function isTableBeingTransferred($tablelist, $table_prefix, $table_ends_with)
    {
        foreach ($tablelist as $table) {
            $tablename_with_prefix = str_replace($table_prefix, "", $table->name);
            if ($tablename_with_prefix === $table_ends_with) {
                return true;
            }
        }
        return false;
    }

    /**
     *  Get last db query error
     */
    public function getLastDBQueryErrors()
    {
        global $wpdb;
        $log_errors = [];
        $user_errors = [];

        // Check what error we have
        $base_error = sprintf(
            __('Migration aborted, due to a SQL query failing. See WP Synchro log (found in menu "Logs") for specific information about the query that failed. The specific error from database server was: "%s".', 'wpsynchro'),
            $wpdb->last_error
        );
        if (strpos($wpdb->last_error, 'Specified key was too long') !== false) {
            // Too long key
            $user_errors[] = $base_error . " " . __('That means that the key was longer than supported on the target database. The table need to be fixed or excluded from migration. See documentation for further help.', 'wpsynchro');
        } elseif (strpos($wpdb->last_error, 'Unknown collation') !== false) {
            // Not supported collation/charset
            $user_errors[] = $base_error . " " . __('That means that the charset/collation used is not supported by the target database engine. The table charset/collations needs to be changed into a supported charset/collation for the target database or excluded from migration. See documentation for further help.', 'wpsynchro');
        } elseif (strpos($wpdb->last_query, 'CREATE VIEW') === 0) {
            // Could not create view. Typically, because the required other tables are not there
            $user_errors[] = $base_error . " " . __('The error was caused by trying to create a view in the database. The error is normally thrown from the database server, when the view references tables that do not exist on the target database, so make sure they are there.', 'wpsynchro');
        } else {
            // General error message
            $user_errors[] = $base_error . " " . __('If you need help, contact WP Synchro support.', 'wpsynchro');
        }

        // Logging for log files
        $log_errors[] = "SQL query failed execution: " . $wpdb->last_query;
        $log_errors[] = "WPDB last error: " . $wpdb->last_error;

        return [
            'log_errors' => $log_errors,
            'user_errors' => $user_errors,
        ];
    }

    /**
     *  Get data from local DB, with a certain primary key and max response size
     */
    public function getDataFromDB($table, $column_names, $primary_key_column, $last_primary_key, $completed_rows, $max_response_size, $default_rows_per_request, $time_limit_in_seconds)
    {
        global $wpdb;
        $data = [];
        $has_more_rows_in_table = true;
        $errors = [];

        $current_response_size = 0;
        $is_using_primary_key = strlen($primary_key_column) > 0;
        $start_time = microtime(true);

        // Generate the template sql
        $column_parts = [];
        foreach ($column_names as $column_name) {
            $column_parts[] = 'char_length(`' . $column_name . '`)';
        }
        $sql_template = "SELECT t1.*, @total:= @total + (" . implode('+', $column_parts) . ") AS wpsynchro_rowlength FROM (%s) AS t1 JOIN (SELECT @total:=0) AS r WHERE @total < %d";

        while ($current_response_size < $max_response_size) {
            $remaining_space = $max_response_size - $current_response_size;
            // Get data
            if ($is_using_primary_key) {
                $sql_stmt = 'SELECT * FROM `' . $table . '` WHERE `' . $primary_key_column . '` > ' . $last_primary_key . ' ORDER BY `' . $primary_key_column . '` ASC LIMIT ' . intval($default_rows_per_request);
                $sql_stmt = sprintf($sql_template, $sql_stmt, $remaining_space);
                $sql_stmt .= ' ORDER BY `' . $primary_key_column . '` ASC';
            } else {
                $order_by = ' ORDER BY `' . implode('`,`', $column_names) . '` ' ;
                $sql_stmt = 'SELECT * FROM `' . $table . '` ' . $order_by . ' LIMIT ' . $completed_rows . ',' . intval($default_rows_per_request);
                $sql_stmt = sprintf($sql_template, $sql_stmt, $remaining_space);
                $sql_stmt .= $order_by;
            }
            $sql_result = $wpdb->get_results($sql_stmt);
            if (strlen($wpdb->last_error) > 0) {
                $errors[] = $wpdb->last_error;
                $wpdb->last_error = '';
            }

            if (empty($sql_result)) {
                $has_more_rows_in_table = false;
                break;
            }

            foreach ($sql_result as $data_row) {
                unset($data_row->wpsynchro_rowlength);
                foreach ($data_row as $data_row_col) {
                    if ($data_row_col !== null) {
                        $current_response_size += strlen($data_row_col);
                    }
                }
                if ($is_using_primary_key) {
                    $last_primary_key = $data_row->$primary_key_column;
                } else {
                    $completed_rows += 1;
                }
                $data[] = $data_row;
                if ($current_response_size > $max_response_size) {
                    break;
                }
            }

            // Check if we passed time limit
            $current_time = \microtime(true);
            $time_spent = $current_time - $start_time;
            if ($time_spent > $time_limit_in_seconds) {
                break;
            }
        }

        return (object) [
            'data' => $data,
            'has_more_rows_in_table' => $has_more_rows_in_table,
            'errors' => $errors
        ];
    }
}
