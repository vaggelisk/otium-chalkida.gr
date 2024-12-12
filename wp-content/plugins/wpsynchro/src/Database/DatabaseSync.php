<?php

namespace WPSynchro\Database;

use WPSynchro\Database\Exception\SerializedStringException;
use WPSynchro\Logger\FileLogger;
use WPSynchro\Logger\LoggerInterface;
use WPSynchro\Migration\MigrationController;
use WPSynchro\Transport\Destination;
use WPSynchro\Transport\RemoteTransport;
use WPSynchro\Utilities\SyncTimerList;

/**
 * Class for handling database migration
 */
class DatabaseSync
{
    // Constants
    const TMP_TABLE_PREFIX = 'wpsyntmp_';
    // Data objects
    public $job = null;
    public $migration = null;
    // Timers and limits
    public $timer = null;
    public $max_time_per_sync = 0;
    // Throttling
    public $has_backed_off_because_of_memory = false;
    // Dependencies
    public $logger;
    public $serialized_string_handler;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->logger = MigrationController::getInstance()->getLogger();
        $this->serialized_string_handler = new SerializedStringHandler();
    }

    /**
     * Start a migration chunk - Returns completion percent
     */
    public function runDatabaseSync(&$migration, &$job)
    {
        // Start timer
        $this->timer = SyncTimerList::getInstance();

        $this->migration = &$migration;
        $this->job = &$job;

        $this->logger->log('INFO', 'Starting database migration loop with remaining time: ' . $this->timer->getRemainingSyncTime());

        // Prepare sync data
        $this->prepareSyncData();

        // Check preflight errors
        if (count($this->job->errors) > 0) {
            return;
        }

        // Now, do some work
        $lastrun_time = 2;

        while ($this->timer->shouldContinueWithLastrunTime($lastrun_time)) {
            $nomorework = true;
            foreach ($this->job->from_dbmasterdata as &$table) {
                if ($table->is_completed) {
                    $table->rows = $table->completed_rows;
                } else {
                    // Pre processing throttling stuff
                    $this->handlePreProcessingThrottling($table);

                    // Call proper service to get/send data depending on pull/push
                    $lastrun_timer = $this->timer->startTimer('databasesync', 'while', 'lastrun');

                    if ($this->migration->type == 'pull') {
                        $result_from_remote_service = $this->retrieveDataFromRemoteService($table);
                    } elseif ($this->migration->type == 'push') {
                        $result_from_remote_service = $this->sendDataToRemoteService($table);
                    }

                    $table->completed_rows += $result_from_remote_service;
                    if ($table->completed_rows > $table->rows) {
                        $table->rows = $table->completed_rows;
                    }
                    $nomorework = false;

                    // Throttling
                    $lastrun_time = $this->timer->getElapsedTimeToNow($lastrun_timer);
                    $this->handlePostProcessingThrottling($lastrun_time);
                    $this->logger->log('DEBUG', 'Lastrun in : ' . $lastrun_time . ' seconds - response size throttle: ' . $this->job->db_throttle_table_response_size . ' and remaining time: ' . $this->timer->getRemainingSyncTime());
                    // Break out to test if we have time for more
                    break;
                }
            }

            // Recalculate completion and update state in job
            $this->updateCompletionStatusPercent();

            // If no more work, mark as completed
            if ($nomorework) {
                $this->job->database_completed = true;
                break;
            }

            // If we found errors, break out
            if (count($this->job->errors) > 0) {
                break;
            }

            // Save status to DB
            $this->job->save();
        }

        $this->logger->log('INFO', 'Ending database migration loop with remaining time: ' . $this->timer->getRemainingSyncTime() . ' seconds');
    }

    /**
     * Prepare and fetch data for sync
     */
    private function prepareSyncData()
    {
        // Determine max time per sync
        $this->max_time_per_sync = ceil($this->timer->getSyncMaxExecutionTime() / 5);
        if ($this->max_time_per_sync > 10) {
            $this->max_time_per_sync = 10;
        }

        // Check if first run
        if (!$this->job->db_first_run_setup) {
            $this->createTablesOnRemoteDatabase();
            $this->job->db_first_run_setup = true;
        }
    }

    /**
     *  Handle pre processing throttling of rows based on time per sync
     */
    private function handlePreProcessingThrottling($table)
    {
        // If table is different than last time this ran
        if ($table->name != $this->job->db_throttle_table) {
            $this->job->db_throttle_table = $table->name;
            $this->job->db_throttle_table_response_size = $this->job->db_response_size_wanted_default;

            $this->logger->log('INFO', 'New table is started: ' . sanitize_text_field($table->name) . ' and setting new max response size: ' . $this->job->db_throttle_table_response_size);
        }
    }

    /**
     *  Handle post processing throttling of rows based on time per sync
     *
     */
    private function handlePostProcessingThrottling($lastrun_time)
    {
        // Check if we are too close to max memory (aka handling too large datasets and risking outofmemory) - One time thing per run
        $current_peak = \memory_get_peak_usage();

        if (!$this->has_backed_off_because_of_memory && $current_peak > $this->job->masterdata_max_memory_limit_bytes) {
            // Back off a bit
            $this->has_backed_off_because_of_memory = true;
            $new_response_limit = floor($this->job->db_throttle_table_response_size * 0.70);
            $this->logger->log('WARNING', 'Hit memory peak - Current peak: ' . $current_peak . ' and memory limit: ' . $this->job->masterdata_max_memory_limit_bytes . ' - Backing off from: ' . $this->job->db_throttle_table_response_size . ' rows to: ' . $new_response_limit . ' rows');
            $this->job->db_throttle_table_response_size = $new_response_limit;
            return;
        }

        // Check that last return response size in bytes does not exceed the max limit
        if ($this->job->db_last_response_length > 0 && $this->job->db_last_response_length > $this->job->db_response_size_wanted_max) {
            // Back off
            $this->job->db_throttle_table_response_size = intval($this->job->db_throttle_table_response_size * 0.80);
            return;
        }

        // Throttle rows per sync
        if ($lastrun_time < $this->max_time_per_sync) {
            // Scale up
            $this->job->db_throttle_table_response_size = ceil($this->job->db_throttle_table_response_size * 1.05);
        } else {
            // Back off
            $this->job->db_throttle_table_response_size = ceil($this->job->db_throttle_table_response_size * 0.90);
        }

        // Make sure the response size never gets above the max
        if ($this->job->db_throttle_table_response_size > $this->job->db_response_size_wanted_max) {
            $this->job->db_throttle_table_response_size = $this->job->db_response_size_wanted_max;
        }
    }

    /**
     *  Send data to remote service (used for push)
     */
    private function sendDataToRemoteService(&$table)
    {
        if ($this->migration == null) {
            return 0;
        }

        $calculated_inital_rows_per_request = floor($this->job->db_response_size_wanted_default / $table->row_avg_bytes);
        if ($calculated_inital_rows_per_request < 2) {
            $calculated_inital_rows_per_request = 2;
        }

        $timer = SyncTimerList::getInstance();

        $database_helper_functions = new DatabaseHelperFunctions();
        $data_result_from_db = $database_helper_functions->getDataFromDB(
            $table->name,
            $table->getColumnNames(),
            $table->primary_key_column,
            $table->last_primary_key,
            $table->completed_rows,
            $this->job->db_throttle_table_response_size,
            $calculated_inital_rows_per_request,
            $timer->getRemainingSyncTime() / 2    // only allow it some time, so there is time to process it also
        );

        $rows_fetched = count($data_result_from_db->data);

        // If there is no more rows, mark it as completed
        if (!$data_result_from_db->has_more_rows_in_table) {
            $this->logger->log('INFO', 'Marking table: ' . $table->name . ' as completed');
            $table->is_completed = true;
        }

        // Generate SQL queries from data
        $sql_inserts = [];
        if ($rows_fetched > 0) {
            $sql_inserts = $this->generateSQLInserts($table, $data_result_from_db->data, $this->job->masterdata_max_sql_packet_bytes);
        } else {
            return 0;
        }

        // Create POST request to remote
        foreach ($sql_inserts as $sql_insert) {
            $body = new \stdClass();
            $body->sql_inserts = $sql_insert;
            $body->type = $this->migration->type;
            $this->callRemoteClientDBService($body, 'to');
            // Check for error
            if (count($this->job->errors) > 0) {
                return;
            }
        }

        return $rows_fetched;
    }

    /**
     *  Call service for executing sql queries
     */
    public function callRemoteClientDBService(&$body, $to_or_from = 'to')
    {
        // Start timer
        $this->timer = SyncTimerList::getInstance();

        // Set destination
        if ($to_or_from == "to") {
            $destination = new Destination(Destination::TARGET);
        } else {
            $destination = new Destination(Destination::SOURCE);
        }

        $url = $destination->getFullURL() . '?action=wpsynchro_db_sync';

        // Get remote transfer object
        $remotetransport = new RemoteTransport();
        $remotetransport->setDestination($destination);
        $remotetransport->init();
        $remotetransport->setUrl($url);
        $remotetransport->setDataObject($body);
        $database_result = $remotetransport->remotePOST();

        if ($database_result->isSuccess()) {
            $result_body = $database_result->getBody();
            $this->job->db_last_response_length = $database_result->getBodyLength();
            $this->logger->log('DEBUG', "Got a proper response from 'clientsyncdatabase' with response length: " . $this->job->db_last_response_length);

            // Check for returning data
            if (isset($result_body->data)) {
                return $result_body;
            }
        } else {
            $this->job->errors[] = __('Database migration failed with error, which means we can not continue the migration.', 'wpsynchro');
        }
    }

    /**
     *  Retrieve data from remote service (used for pull)
     */
    private function retrieveDataFromRemoteService(&$table)
    {
        global $wpdb;

        if ($this->migration == null) {
            return 0;
        }

        $calculated_inital_rows_per_request = floor($this->job->db_response_size_wanted_default / $table->row_avg_bytes);
        if ($calculated_inital_rows_per_request < 2) {
            $calculated_inital_rows_per_request = 2;
        }

        $timer = SyncTimerList::getInstance();

        $body = new \stdClass();
        $body->table = $table->name;
        $body->last_primary_key = $table->last_primary_key;
        $body->primary_key_column = $table->primary_key_column;
        $body->completed_rows = $table->completed_rows;
        $body->max_response_size = $this->job->db_throttle_table_response_size;
        $body->type = $this->migration->type;
        $body->default_rows_per_request = $calculated_inital_rows_per_request;
        $body->column_names = $table->getColumnNames();
        $body->time_limit = $timer->getRemainingSyncTime() / 2;    // only allow it some time, so there is time to process it also

        // Call remote service
        $this->logger->log('DEBUG', 'Getting data from remote DB with data: ' . json_encode($body));
        $remote_result = $this->callRemoteClientDBService($body, 'from');

        // Check for errors
        if (count($this->job->errors) > 0) {
            return 0;
        }

        if (is_array($remote_result->data)) {
            $rows_fetched = count($remote_result->data);
        } else {
            $rows_fetched = 0;
        }
        $this->logger->log('DEBUG', 'Got rows: ' . $rows_fetched);

        if (!$remote_result->has_more_rows_in_table) {
            $this->logger->log('INFO', 'Marking table: ' . $table->name . ' as completed');
            $table->is_completed = true;
        }

        // Insert statements
        if ($rows_fetched > 0) {
            $sql_inserts = $this->generateSQLInserts($table, $remote_result->data, $this->job->masterdata_max_sql_packet_bytes);
            $wpdb->query('SET FOREIGN_KEY_CHECKS=0;');
            foreach ($sql_inserts as $sql_insert) {
                $wpdb->query($sql_insert);
                if (strlen($wpdb->last_error) > 0) {
                    $this->job->errors[] = $wpdb->last_error;
                    $wpdb->last_error = '';
                }
                $wpdb->flush();
            }
        }

        $this->logger->log('DEBUG', 'Inserted ' . $rows_fetched . ' rows into target database');

        return $rows_fetched;
    }

    /**
     *  Generate sql inserts, queued together inside max_packet_allowed gathered from metadata and setup in preparesyncdata method
     */
    public function generateSQLInserts(&$table, &$rows, $max_packet_length)
    {
        $insert_buffer = '';
        $insert_buffer_length = 0;
        $insert_count = 0;
        $insert_count_max = 998;    // Max 1000 inserts per statement, limit in mysql (minus a few such as foreign key check)
        $last_primary_key = 0;
        $inserts_array = [];

        $sql_insert_prefix = function ($temp_tablename, $col_and_val) {
            $cols = array_keys($col_and_val);

            $insert_buffer = 'INSERT INTO `' . $temp_tablename . '` (`' . implode('`,`', $cols) . '`) VALUES ';
            return $insert_buffer;
        };

        foreach ($rows as $row) {
            // If beginning of new buffer
            $col_and_val = get_object_vars($row);

            // Check if we have a generated column, in that case, remove it
            foreach ($col_and_val as $col => $val) {
                if ($table->column_types->isGenerated($col)) {
                    unset($col_and_val[$col]);
                }
            }

            if ($insert_buffer == '') {
                $insert_buffer = $sql_insert_prefix($table->temp_name, $col_and_val);
                $insert_buffer_length = strlen($insert_buffer);
            }

            $temp_insert_add = '(';
            $error_during_column_handling = false;
            foreach ($col_and_val as $col => $val) {
                if ($col == $table->primary_key_column) {
                    $last_primary_key = $val;
                }

                // Handle NULL values
                if (is_null($val)) {
                    $temp_insert_add .= 'NULL,';
                } elseif ($table->column_types->isString($col)) {
                    // Handle string values
                    if ($col != 'guid') {
                        $this->handleSearchReplace($val);
                    }
                    $temp_insert_add .= "'" . $this->escape($val) . "',";
                } elseif ($table->column_types->isNumeric($col)) {
                    // Handle numeric values
                    if (strpos($val, 'e') > -1 || strpos($val, 'E') > -1) {
                        $temp_insert_add .= "'" . $this->escape($val) . "',";
                    } else {
                        $temp_insert_add .= $this->escape($val) . ',';
                    }
                } elseif ($table->column_types->isBinary($col)) {
                    // Handle binary values
                    $available_memory = $this->job->masterdata_max_memory_limit_bytes;
                    $val_length = strlen($val);
                    $expected_length = $val_length * 2;
                    if ($expected_length > $available_memory) {
                        $warningsmsg = sprintf(__('Large row with binary column ignored from table: %s - Size of value: %d - Max size %d bytes - Increase memory limit on server', 'wpsynchro'), $table->name, $val_length, $available_memory);
                        $this->logger->log('WARNING', $warningsmsg);
                        $this->job->warnings[] = $warningsmsg;
                        $error_during_column_handling = true;
                        break;
                    } else {
                        if (strlen($val) > 0) {
                            $temp_insert_add .= '0x' . bin2hex($val) . ',';
                        } else {
                            $temp_insert_add .= 'NULL,';
                        }
                    }
                } elseif ($table->column_types->isBit($col)) {
                    // Handle bit values
                    $temp_insert_add .= "b'" . decbin($val) . "',";
                }
            }

            if ($error_during_column_handling) {
                continue;
            }

            $temp_insert_add = trim($temp_insert_add, ', ') . '),';
            $tmp_insert_add_length = strlen($temp_insert_add);

            if ($tmp_insert_add_length > $max_packet_length) {
                $warningsmsg = sprintf(__('Large row ignored from table: %s - Size: %d - This happens when a table row is larger than your system limits allows. These limits are a combination of max SQL packet size, memory limits and PHP max_post_size on both ends of the migration.', 'wpsynchro'), $table->name, $tmp_insert_add_length);
                $this->logger->log('WARNING', $warningsmsg);
                $this->job->warnings[] = $warningsmsg;
                continue;
            }

            if ((($insert_buffer_length + $tmp_insert_add_length) < $max_packet_length) && $insert_count < $insert_count_max) {
                $insert_buffer .= $temp_insert_add;
                $insert_buffer_length += $tmp_insert_add_length;
                $insert_count++;
            } else {
                // Save sql to array
                $insert_buffer = trim($insert_buffer, ', ');
                $inserts_array[] = $insert_buffer;
                // Start from beginning
                $insert_buffer = $sql_insert_prefix($table->temp_name, $col_and_val);
                $insert_buffer .= $temp_insert_add;
                $insert_buffer_length = strlen($insert_buffer);
                $insert_count = 1;
            }
        }
        if (strlen($insert_buffer) > 0 && $insert_count > 0) {
            $insert_buffer = trim($insert_buffer, ', ');
            $inserts_array[] = $insert_buffer;
        }

        $table->last_primary_key = $last_primary_key;

        return $inserts_array;
    }

    /**
     * Handle SQL escape
     */
    private function escape($data)
    {
        global $wpdb;
        return \mysqli_real_escape_string($wpdb->__get('dbh'), $data);
    }

    /**
     * Handle in-data search/replace
     */
    public function handleSearchReplace(&$data)
    {
        // Check data type
        if (is_serialized($data)) {
            try {
                $this->serialized_string_handler->searchReplaceSerialized($data, $this->job->db_search_replaces);
            } catch (SerializedStringException $ex) {
                $this->logger->log('ERROR', $ex->getMessage(), $ex->data);
            }
        } else {
            // Its just plain data, so simple fixy fixy
            foreach ($this->job->db_search_replaces as $replaces) {
                $data = str_replace($replaces->from, $replaces->to, $data);
            }
        }
    }

    /**
     *  Create tables on remote (and filter out temp tables)
     */
    private function createTablesOnRemoteDatabase()
    {
        global $wpdb;

        // the list of queries to setup tables
        $sql_queries = [];

        // Disable foreign key checks
        $sql_queries[] = 'SET FOREIGN_KEY_CHECKS = 0;';

        // Create the temp tables (and drop them if already exists)
        foreach ($this->job->from_dbmasterdata as &$table) {
            if (!isset($table->temp_name) || strlen($table->temp_name) == 0) {
                $table->temp_name = self::TMP_TABLE_PREFIX . uniqid();
            }

            $create_table = str_replace('`' . $table->name . '`', '`' . $table->temp_name . '`', $table->create_table);

            // Go through every table name, so see if table is referenced in create statement - Could be a innodb constraint or whatever
            foreach ($this->job->from_dbmasterdata as &$inside_table) {
                if ($inside_table->name == $table->name) {
                    // Ignore if it is the same table
                    continue;
                }

                // Check if the create statement contains the name of inside-table
                if (strpos($table->create_table, '`' . $inside_table->name . '`') > -1) {
                    // If not yet given a temp name, set that first
                    if (!isset($inside_table->temp_name) || strlen($inside_table->temp_name) == 0) {
                        $inside_table->temp_name = self::TMP_TABLE_PREFIX . uniqid();
                    }
                    // Replace in create statement, so inside tables new temp name is there instead
                    $create_table = str_replace('`' . $inside_table->name . '`', '`' . $inside_table->temp_name . '`', $create_table);
                }
            }

            // Adapt create statement according to MySQL version, key naming etc
            $sql_queries[] = $this->adaptCreateStatement($create_table, $this->job->to_sql_version);
        }

        if ($this->migration->type == 'pull') {
            // Execute the sql queries
            foreach ($sql_queries as $sql_query) {
                $sql_result = $wpdb->query($sql_query);
                if ($sql_result === false) {
                    $database_helper_functions = new DatabaseHelperFunctions();
                    $logs = $database_helper_functions->getLastDBQueryErrors();
                    foreach ($logs['log_errors'] as $log_error) {
                        $this->logger->log('CRITICAL', $log_error);
                    }
                    foreach ($logs['user_errors'] as $user_error) {
                        $this->job->errors[] = $user_error;
                    }
                    break;
                }
            }
        } elseif ($this->migration->type == 'push') {
            // if push, then always call remote service for sql create tables

            $body = new \stdClass();
            $body->sql_inserts = $sql_queries;
            $body->type = $this->migration->type;

            $this->callRemoteClientDBService($body, 'to');
        }
    }

    /**
     *  Change create statements according to MySQL version, key name, constraint name etc
     */
    public function adaptCreateStatement($create, $to_db_version)
    {
        // Change name to random in all constraints, if there, to prevent trouble with existing
        $create = preg_replace_callback("/CONSTRAINT\s`(\S+)`/", function () {
            return 'CONSTRAINT `' . uniqid() . '`';
        }, $create);

        // Change index names to random, just to prevent overlaps
        $create = preg_replace_callback("/KEY\s`(\S+)`/", function () {
            return 'KEY `' . uniqid() . '`';
        }, $create);

        // utf8mb4_0900_ai_ci collation is only from mysql 8
        if (stripos($create, 'utf8mb4_0900_ai_ci') !== false) {
            // utf8mb4_0900_ai_ci is only for MySQL 8
            if (version_compare($to_db_version, '8', '<')) {
                $create = str_replace("utf8mb4_0900_ai_ci", "utf8mb4_unicode_520_ci", $create);
            }
        }

        // Changes according to MySQL version
        if (version_compare($to_db_version, '5.5', '>=') && version_compare($to_db_version, '5.6', '<')) {  // MySQL
            $create = str_replace("utf8mb4_unicode_520_ci", "utf8mb4_unicode_ci", $create);
        } elseif (version_compare($to_db_version, '10.1', '>=') && version_compare($to_db_version, '10.2', '<')) {   // MariaDB
            $create = str_replace("utf8mb4_unicode_520_ci", "utf8mb4_unicode_ci", $create);
        }

        return $create;
    }

    /**
     *  Calculate completion percent
     */
    private function updateCompletionStatusPercent()
    {
        if (!isset($this->job->from_dbmasterdata)) {
            return;
        }

        $totalrows = 0;
        $completedrows = 0;
        $percent_completed = 0;
        // Data sizes
        $total_data_size = 0;

        foreach ($this->job->from_dbmasterdata as $table) {
            if (isset($table->rows)) {
                $temp_rows = $table->rows;
            } else {
                $temp_rows = 0;
            }
            if (isset($table->completed_rows)) {
                $temp_completedrows = $table->completed_rows;
            } else {
                $temp_completedrows = 0;
            }
            $totalrows += $temp_rows;
            $completedrows += $temp_completedrows;
            $total_data_size += $table->data_total_bytes;
        }

        if ($totalrows > 0) {
            $percent_completed = floor(($completedrows / $totalrows) * 100);
        } else {
            $percent_completed = 100;
        }
        // :)
        if ($percent_completed > 100) {
            $percent_completed = 100;
        }

        $this->job->database_progress = $percent_completed;

        // Update status description
        $current_number = $total_data_size * ($percent_completed / 100);
        $total_number = $total_data_size;
        $one_mb = 1012 * 1024;

        if ($total_number < $one_mb) {
            $total_number = number_format_i18n($total_number / 1024, 0) . 'kB';
            $current_number = number_format_i18n($current_number / 1024, 0) . 'kB';
        } else {
            $total_number = number_format_i18n($total_number / $one_mb, 1) . 'MB';
            $current_number = number_format_i18n($current_number / $one_mb, 1) . 'MB';
        }

        $completed_desc_rows = number_format_i18n($completedrows, 0);
        $total_desc_rows = number_format_i18n($totalrows, 0);

        if ($this->job->database_progress < 100) {
            $database_progress_description = sprintf(__('Data: %s / %s - Rows: %s / %s', 'wpsynchro'), $current_number, $total_number, $completed_desc_rows, $total_desc_rows);
        } else {
            $database_progress_description = '';
        }

        $this->logger->log('INFO', 'Database progress update: ' . $database_progress_description);
        $this->job->database_progress_description = $database_progress_description;
    }
}
