<?php

/**
 * Class for handling database finalize
 */

namespace WPSynchro\Database;

use WPSynchro\Masterdata\MasterdataSync;
use WPSynchro\Migration\MigrationController;
use WPSynchro\Transport\Destination;
use WPSynchro\Utilities\SyncTimerList;

class DatabaseFinalize
{
    // Data objects
    public $job = null;
    public $migration = null;
    public $databasesync = null;

    // Dependencies
    public $logger = null;
    public $timer = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->logger = MigrationController::getInstance()->getLogger();
        $this->timer = SyncTimerList::getInstance();
    }

    /**
     *  Calculate completion percent
     */
    public function finalize()
    {
        $sync_controller = MigrationController::getInstance();
        $this->job = $sync_controller->job;
        $this->migration = $sync_controller->migration;

        $this->databasesync = new DatabaseSync();
        $this->databasesync->job = $this->job;
        $this->databasesync->migration = $this->migration;

        $this->logger->log("INFO", "Starting database finalize with remaining time: " . $this->timer->getRemainingSyncTime());

        // Prepare SQL statements, if not done yet
        if (!$this->job->finalize_db_initialized) {
            $this->job->finalize_progress_description = __("Preparing database finalize", "wpsynchro");
            $this->logger->log("INFO", "Prepare SQL queries for database finalize");
            $this->prepareSQLQueries();
            $this->job->finalize_db_initialized = true;
            $this->logger->log("INFO", "Done preparing SQL queries for database finalize");
            $this->job->request_full_timeframe = true;
            return;
        }

        // Execute a group of queries
        if (count($this->job->finalize_db_sql_queries) > 0) {
            $this->job->finalize_progress_description = sprintf(
                __("Finalizing table %d out of %d", "wpsynchro"),
                $this->job->finalize_db_sql_queries_count - count($this->job->finalize_db_sql_queries),
                $this->job->finalize_db_sql_queries_count
            );

            $sql_group = array_pop($this->job->finalize_db_sql_queries);

            // Execute a set of queries
            $body = new \stdClass();
            $body->sql_inserts = $sql_group;
            $body->type = 'finalize'; // For executing sql

            $this->logger->log("DEBUG", "Calling remote client db service with " . count($body->sql_inserts) . " SQL statements:", $sql_group);
            $this->databasesync->callRemoteClientDBService($body, 'to');

            $this->job->request_full_timeframe = true;
            return;
        }

        // After all tables is renamed, we remove all the extra temporary tables on the target (only leftovers from other syncs)
        if (count($this->job->errors) == 0 && !$this->job->finalize_db_excess_tables_initialized) {
            $this->job->finalize_db_excess_table_queries = $this->cleanUpAfterFinalizing();
            $this->job->finalize_db_excess_table_queries_count = count($this->job->finalize_db_excess_table_queries);
            $this->job->finalize_db_excess_tables_initialized = true;
            $this->job->request_full_timeframe = true;
            return;
        }

        // If any excess SQL queries to clean up excess tables, run them
        if (count($this->job->errors) == 0 && count($this->job->finalize_db_excess_table_queries) > 0) {
            $this->job->finalize_progress_description = sprintf(
                __("Removing old temporary table - %d out of %d", "wpsynchro"),
                $this->job->finalize_db_excess_table_queries_count - count($this->job->finalize_db_excess_table_queries),
                $this->job->finalize_db_excess_table_queries_count
            );

            $sql = array_pop($this->job->finalize_db_excess_table_queries);

            // Execute a clean up query
            $body = new \stdClass();
            $body->sql_inserts = [$sql];
            $body->type = 'finalize'; // For executing sql

            $this->logger->log("DEBUG", "Calling remote client db service with for excess table cleanup - SQL statement:", $body->sql_inserts);
            $this->databasesync->callRemoteClientDBService($body, 'to');

            $this->job->request_full_timeframe = true;
            return;
        }

        // Check for table case issues on the migration
        if (count($this->job->errors) == 0) {
            $this->job->finalize_progress_description = __("Check that all tables on target is in correct case", "wpsynchro");
            $this->checkTableCasesCorrect($this->job->finalize_db_table_to_expect_on_target);
        }

        if (count($this->job->errors) > 0) {
            // Errors during finalize
            return;
        } else {
            // All good
            $this->job->finalize_db_completed = true;
        }
    }

    /**
     *  Prepare the list of sql queries to run for finalize
     */
    public function prepareSQLQueries()
    {
        // Handle preserving data
        $sql_queries = [];
        $sql_queries_last = [];

        // Handle data to keep
        $sql_queries[] = $this->handleDataToKeep();

        // Get latest and greatest from target db
        $dbtables = $this->retrieveDatabaseTables();

        // Create lookup array
        $to_table_lookup = [];
        foreach ($dbtables as $to_table) {
            $to_table_lookup[$to_table->name] = $to_table->rows;
        }

        // Run finalize checks
        foreach ($this->job->from_dbmasterdata as $from_table) {
            $from_rows = $from_table->rows;
            // If its old temp table on source, just ignore
            if (strpos($from_table->name, DatabaseSync::TMP_TABLE_PREFIX) > -1) {
                $this->logger->log("DEBUG", "Table " . $from_table->name . " is a old temp table, so ignore");
                continue;
            }

            // Check if table exists on "to", which it should
            if (!isset($to_table_lookup[$from_table->temp_name])) {
                // Not transferred - Error
                $this->logger->log("CRITICAL", "Table " . $from_table->name . " does not exist on target, but it should. It is not transferred. Temp name is " . $from_table->temp_name);
                $this->job->errors[] = sprintf(__("Finalize: Error in database migration for table %s - It is not transferred", "wpsynchro"), $from_table->name);
                continue;
            }

            $to_rows = $to_table_lookup[$from_table->temp_name];
            $this->checkRowCountCompare($from_table->name, $from_rows, $to_rows);
        }

        // Get tables to be renamed
        foreach ($this->job->from_dbmasterdata as $table) {
            if (!isset($from_table->temp_name) || strlen($from_table->temp_name) == 0) {
                continue;
            }

            $table_name = $table->name;
            $table_temp_name = $table->temp_name;

            $sql_queries_in_group = [];

            // If table prefix change is enabled
            if ($this->migration->db_table_prefix_change) {
                // Check if we need to change prefixes and therefore need to rewrite table name
                $table_name = DatabaseHelperFunctions::handleTablePrefixChange($table_name, $this->job->from_wpdb_prefix, $this->job->to_wpdb_prefix);

                // Handle the data updates in table when doing prefix change
                $prefix_change_sql_queries = $this->handleDataChangeOnPrefixChange($table_name, $table_temp_name);
                $sql_queries_in_group = array_merge($sql_queries_in_group, $prefix_change_sql_queries);
            }

            // Add tables to the list for "expected to be on target"
            $this->job->finalize_db_table_to_expect_on_target[] = $table_name;

            // Add sql statements
            $this->logger->log("DEBUG", "Add drop table in database on " . $table_name . " and rename from " . $table_temp_name);
            $sql_queries_in_group[] = 'DROP TABLE IF EXISTS `' . $table_name . '`';
            $sql_queries_in_group[] = 'RENAME TABLE `' . $table_temp_name . '` TO `' . $table_name . '`';

            // Check if it is special table
            if ($table_name == $this->job->to_wp_users_table) {
                $sql_queries_last[0] = $sql_queries_in_group;
            } elseif ($table_name == $this->job->to_wp_usermeta_table) {
                $sql_queries_last[1] = $sql_queries_in_group;
            } elseif ($table_name == $this->job->to_wp_options_table) {
                $sql_queries_last[2] = $sql_queries_in_group;
            } else {
                $sql_queries[] = $sql_queries_in_group;
            }
        }

        // Handle multisite
        $sql_queries = array_merge($sql_queries, $this->getMultisiteFinalizeSQL());

        // Add the last queries
        ksort($sql_queries_last);
        foreach ($sql_queries_last as $query) {
            $sql_queries[] = $query;
        }

        // Add views
        foreach ($this->job->db_views_to_be_synced as $table) {
            // Add sql statements
            $this->logger->log("DEBUG", "Add drop view in database on " . $table->name . " and create it again");
            $view_sql = [
                'DROP VIEW IF EXISTS `' . $table->name . '`',
                $table->create_table
            ];
            $sql_queries[] = $view_sql;
        }

        // Turn it around, so we can pop of from top
        $sql_queries = array_reverse($sql_queries);

        // Log sql queries
        $this->logger->log("DEBUG", "Finalize SQL queries:", $sql_queries);
        $this->job->finalize_db_sql_queries = $sql_queries;
        $this->job->finalize_db_sql_queries_count = count($sql_queries);
    }

    /**
     *  Handle the data to keep (such as WP Synchro data etc.)
     */
    public function handleDataToKeep()
    {
        // Figure out if we actually migrate the options table
        $target_options_table_tempname = "";
        $sql_queries = [];
        foreach ($this->job->from_dbmasterdata as $table) {
            if ($table->name == $this->job->from_wp_options_table) {
                $target_options_table_tempname = $table->temp_name;
                break;
            }
        }
        if ($target_options_table_tempname == "") {
            return $sql_queries;
        }


        // Preserving data in options table, if it is migrated
        if ($this->migration->include_all_database_tables || in_array($this->job->from_wp_options_table, $this->migration->only_include_database_table_names)) {
            global $wpdb;

            $delete_from_sql = "DELETE FROM `" . $target_options_table_tempname . "`  WHERE option_name LIKE '" . $wpdb->esc_like("wpsynchro_") . "%'";
            $insert_into_sql = "INSERT INTO `" . $target_options_table_tempname . "` (option_name,option_value,autoload) SELECT option_name,option_value,autoload FROM " . $this->job->to_wp_options_table . " where option_name like 'wpsynchro_%'";

            $sql_queries[] = $delete_from_sql;
            $this->logger->log("INFO", "Add sql statement to delete WP Synchro options: " . $delete_from_sql);
            $sql_queries[] = $insert_into_sql;
            $this->logger->log("INFO", "Add sql statement to copy current WP Synchro options to temp table: " . $insert_into_sql);

            // Extract all the keys we want to preserve in options table
            $preserve_options_keys = $this->migration->db_preserve_options_table_keys;
            $custom_options_keys = explode(',', $this->migration->db_preserve_options_custom);
            $preserve_options_keys = array_merge($preserve_options_keys, $custom_options_keys);
            $preserve_options_keys = array_map('trim', $preserve_options_keys);

            // Get the SQL for the preserve and add it to our list of SQL's to do
            foreach ($preserve_options_keys as $preserve_key) {
                $preserve_sql = $this->getPreserveOptionsFieldSQL($target_options_table_tempname, $preserve_key);
                $sql_queries = array_merge($sql_queries, $preserve_sql);
            }
        }

        return $sql_queries;
    }

    /**
     *  Add preserve data from options table SQL
     */
    public function getPreserveOptionsFieldSQL($target_options_table_tempname, $options_key)
    {
        $delete_from_sql = "DELETE FROM `" . $target_options_table_tempname . "`  WHERE option_name = '{$options_key}'";
        $insert_into_sql = "INSERT INTO `" . $target_options_table_tempname . "` (option_name,option_value,autoload) SELECT option_name,option_value,autoload FROM " . $this->job->to_wp_options_table . " WHERE option_name = '{$options_key}'";

        $sql = [];
        $sql[] = $delete_from_sql;
        $this->logger->log("INFO", "Add sql statement to delete options field (db preserve): " . $delete_from_sql);
        $sql[] = $insert_into_sql;
        $this->logger->log("INFO", "Add sql statement to copy existing value from options table (db preserve): " . $insert_into_sql);

        return $sql;
    }

    /**
     *  Handle data to be renamed inside tables when changing prefix
     */
    public function handleDataChangeOnPrefixChange($table_name, $table_temp_name)
    {

        $source_prefix = $this->job->from_wpdb_prefix;
        $target_prefix = $this->job->to_wpdb_prefix;
        $sql_queries = [];
        global $wpdb;

        if ($source_prefix != $target_prefix) {
            // Add sql queries to change meta data if options table or user_meta table
            $temp_wp_usermeta = $this->job->to_wpdb_prefix . "usermeta";
            if ($table_name == $this->job->to_wp_usermeta_table || $table_name == $temp_wp_usermeta) {
                // Update prefixes in usermeta table
                $sql_queries[] = "DELETE FROM  `" . $table_temp_name . "` WHERE meta_key LIKE '" . $wpdb->esc_like($target_prefix) . "%'";
                $sql_queries[] = "UPDATE `" . $table_temp_name . "` SET meta_key = REPLACE(meta_key, '" . $source_prefix . "', '" . $target_prefix . "') WHERE meta_key LIKE '" . $wpdb->esc_like($source_prefix) . "%'";
                $this->logger->log("DEBUG", "update data in temp table " . $table_temp_name . " (" . $table_name . ") to replace source prefix " . $source_prefix . " with target prefix " . $target_prefix);
            } elseif ($table_name == $this->job->to_wp_options_table) {
                // Update prefix in options table
                $sql_queries[] = "DELETE FROM  `" . $table_temp_name . "` WHERE option_name LIKE '" . $wpdb->esc_like($target_prefix) . "%'";
                $sql_queries[] = "UPDATE `" . $table_temp_name . "` SET option_name = REPLACE(option_name, '" . $source_prefix . "', '" . $target_prefix . "') WHERE option_name LIKE '" . $wpdb->esc_like($source_prefix) . "%'";
                $this->logger->log("DEBUG", "update data in temp table " . $table_temp_name . " (" . $table_name . ") to replace source prefix " . $source_prefix . " with target prefix " . $target_prefix);
            }
        }
        return $sql_queries;
    }

    /**
     *  Retrieve new database data from target
     */
    public function retrieveDatabaseTables($temp_table = true)
    {
        // Retrieve new db tables list from destination
        $masterdata_obj = new MasterdataSync();
        $data_to_retrieve = ["dbdetails"];
        $masterdata_obj->migration = $this->migration;
        $masterdata_obj->job = $this->job;
        $masterdata_obj->logger = $this->logger;
        $this->logger->log("DEBUG", "Retrieving new masterdata from target");
        $masterdata = $masterdata_obj->retrieveMasterdata(new Destination(Destination::TARGET), $data_to_retrieve);

        if (!is_object($masterdata) || !isset($masterdata->tmptables_dbdetails)) {
            $this->job->errors[] = __("Could not retrieve data from remote site for finalizing", "wpsynchro");
            $this->logger->log("CRITICAL", "Could not retrieve data from target site for finalizing");
            return;
        }
        $this->logger->log("DEBUG", "Retrieving new masterdata completed");

        if ($temp_table) {
            return $masterdata->tmptables_dbdetails;
        } else {
            return $masterdata->dbdetails;
        }
    }

    /**
     *  Try to clean up if any temporary tables are left on target
     */
    public function cleanUpAfterFinalizing()
    {
        $temp_tables_left = $this->retrieveDatabaseTables();
        $sql_queries = [];
        foreach ($temp_tables_left as $table) {
            $sql_queries[] = 'DROP TABLE IF EXISTS `' . $table->name . '`';
            $this->logger->log("DEBUG", "Add sql to delete excess temp table: " . $table->name);
        }

        if (count($sql_queries) == 0) {
            $this->logger->log("DEBUG", "No excess temp tables to delete");
        }
        return $sql_queries;
    }

    /**
     *  Check that tables have correct case
     */
    public function checkTableCasesCorrect($tables_to_be_expected_on_target)
    {
        $tables_on_target = $this->retrieveDatabaseTables(false);

        $tables_check_ignore = $this->getMultisiteTableExclusions();

        foreach ($tables_to_be_expected_on_target as $checktablename) {
            // Check if table name is excluded from check, such as due to multisite stuff
            if (in_array($checktablename, $tables_check_ignore)) {
                continue;
            }

            $found = false;
            foreach ($tables_on_target as $targettable) {
                if ($checktablename == $targettable->name) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                // Not found in correct case, now check for case insensitive
                foreach ($tables_on_target as $targettable) {
                    $found_case_insensitive = false;
                    $found_table_case = $targettable->name;
                    if (strcasecmp($checktablename, $targettable->name) == 0) {
                        $found_case_insensitive = true;
                        break;
                    }
                }

                if ($found_case_insensitive) {
                    $warningmsg = sprintf(__("Finalize: Table %s is not found with the correct case. We found a table called %s. This may or may not give you problems. This happens due to SQL server configuration.", "wpsynchro"), $checktablename, $found_table_case);
                    $this->job->warnings[] = $warningmsg;
                    $this->logger->log("WARNING", $warningmsg);
                } else {
                    $warningmsg = sprintf(__("Finalize: Table %s is not found on target. It may be a problem with the rename from temp table name.", "wpsynchro"), $checktablename, $found_table_case);
                    $this->job->warnings[] = $warningmsg;
                    $this->logger->log("WARNING", $warningmsg);
                }
            }
        }
    }

    /**
     *  Function to help with finalizing database data and checks if rows are with reasonable limits
     */
    public function checkRowCountCompare($from_tablename, $from_rows, $to_rows)
    {

        $margin_for_warning_rows_equal = 5; // 5%

        // If from has no rows, the to table should also be empty
        if ($from_rows == 0 && $to_rows != 0) {
            $this->job->errors[] = sprintf(__("Finalize: Error in database migration for table %s - It should not contain any rows", "wpsynchro"), $from_tablename);
            return;
        }

        // If from has rows, but the to table is empty, could be memory limit hit, exceeding post max size or mysql max_packet_size
        if ($from_rows > 0 && $to_rows == 0) {
            $this->job->errors[] = sprintf(__("Finalize: Error in database migration for table %s - No rows has been transferred, but should contain %d rows. Normally this is because the ressource limits has been hit and the database content is too large. Contact support if this continues to fail.", "wpsynchro"), $from_tablename, $from_rows);
            return;
        }

        // Check that rows approximately equal. Could have been changed a bit while synching, which is okay, but raises a warning if too much. Its okay if it is bigger
        if ($to_rows < ((1 - ($margin_for_warning_rows_equal / 100)) * $from_rows)) {
            $this->job->warnings[] = sprintf(__("Finalize: Warning in database migration for table %s - It differs more than %d%% in size, which indicate something has gone wrong during transfer. We found %d rows, but expected around %d rows.", "wpsynchro"), $from_tablename, $margin_for_warning_rows_equal, $to_rows, $from_rows);
        }
    }

    /**
     *  Handle the multisite finalize sql
     */
    public function getMultisiteFinalizeSQL()
    {
        $multisite_sql = [];
        return $multisite_sql;
    }

    /**
     *  Handle which tables to not check for on the target - aka those that might be renamed or removed, such as users table on multisite
     */
    public function getMultisiteTableExclusions()
    {
        $tables_to_exclude = [];
        return $tables_to_exclude;
    }

    /**
     *  Get percentage completed for database finalize
     */
    public function getPercentCompletedForDatabaseFinalize()
    {
        // We have four primary steps - Initialize, rename queries, drop excess tables and the last checks
        $completion = 0;

        if ($this->job->finalize_db_initialized) {
            $completion += 10;
        }

        if ($this->job->finalize_db_sql_queries_count > 0) {
            $completion += 80 * (count($this->job->finalize_db_sql_queries) / $this->job->finalize_db_sql_queries_count);
        } else {
            $completion += 80;
        }

        if ($this->job->finalize_db_excess_table_queries_count > 0) {
            $completion += 10 * (count($this->job->finalize_db_excess_table_queries) / $this->job->finalize_db_excess_table_queries_count);
        } else {
            $completion += 10;
        }

        return 1 - ($completion / 100);
    }
}
