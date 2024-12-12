<?php

namespace WPSynchro\API;

use WPSynchro\Database\DatabaseHelperFunctions;
use WPSynchro\Database\DatabaseSync;
use WPSynchro\Migration\Job;
use WPSynchro\Transport\ReturnResult;
use WPSynchro\Transport\Transfer;
use WPSynchro\Transport\TransferAccessKey;
use WPSynchro\Utilities\PluginDirs;

/**
 * Class for handling service to backup database
 * Call should already be verified by permissions callback
 *
 */
class DatabaseBackup extends WPSynchroService
{
    public function service()
    {
        $transfer = new Transfer();
        $transfer->setEncryptionKey(TransferAccessKey::getAccessKey());
        $transfer->populateFromString($this->getRequestBody());
        $body = $transfer->getDataObject();

        /**
         *  Extract parameters
         */
        $data_required_errors = false;

        if (isset($body->table)) {
            $table = $body->table;
        } else {
            $data_required_errors = true;
        }

        if (isset($body->filename)) {
            $filename = $body->filename;
            $plugins_dirs = new PluginDirs();
            $filepath = $plugins_dirs->getUploadsFilePath() . $filename;
        } else {
            $data_required_errors = true;
        }
        if (isset($body->memory_limit)) {
            $memory_limit = $body->memory_limit;
        } else {
            $data_required_errors = true;
        }
        if (isset($body->time_limit)) {
            $time_limit = $body->time_limit;
        } else {
            $data_required_errors = true;
        }

        if ($data_required_errors) {
            $returnresult = new ReturnResult();
            $returnresult->init();
            $returnresult->setHTTPStatus(400);
            return $returnresult->echoDataFromServiceAndExit();
        }

        $result = new \stdClass();
        $result->errors = [];
        $result->warnings = [];
        $result->debugs = [];
        $result->infos = [];

        // Add location to log file
        $result->infos[] = "Database backup is written to " . $filepath . " on site " . get_home_url();

        /**
         *  Get started with the export
         */
        // Calculate rows to go for
        if ($table->row_avg_bytes > 0) {
            $rows_per_run = ceil((1024 * 1024) / $table->row_avg_bytes);
        } else {
            $rows_per_run = 9900;
        }

        $database_helper_functions = new DatabaseHelperFunctions();
        $one_mb = 1024 * 1024;

        $data_result_from_db = $database_helper_functions->getDataFromDB(
            $table->name,
            $table->getColumnNames(),
            $table->primary_key_column,
            $table->last_primary_key,
            $table->completed_rows,
            $one_mb,
            $rows_per_run,
            $time_limit
        );

        $data = $data_result_from_db->data;
        $result->has_more_rows_in_table = $data_result_from_db->has_more_rows_in_table;
        $result->errors = array_merge($result->errors, $data_result_from_db->errors);

        // Get databasesync object
        $databasesync = new DatabaseSync();
        $databasesync->job = new Job();
        $databasesync->job->warnings = &$result->warnings;
        $databasesync->job->masterdata_max_memory_limit_bytes = $memory_limit;

        // Check if there is more than X backups and delete the oldest, this needs to be done before the first data is written to file - should only run once
        if (!file_exists($filepath)) {
            $dir = dirname($filepath);
            $this->deleteOldBackups($dir);
        }

        // Add create table to sql file
        if ($table->completed_rows == 0) {
            $file_append_create_table = file_put_contents($filepath, PHP_EOL . $table->create_table . ";" . PHP_EOL, FILE_APPEND);
            if ($file_append_create_table === false) {
                // translators: %s is replaced with file path to database backup
                $result->errors[] = sprintf(__("Appending create table for database backup to %s failed.", "wpsynchro"), $filepath);
            } else {
                $result->debugs[] = "Wrote create table data for table " . $table->name;
            }
        }

        // If rows fetched less than max rows, than mark table as completed
        if (!$result->has_more_rows_in_table) {
            $table->completed_rows = $table->rows;
        }

        // If any rows, get the insert sql version and insert into file
        $rows_fetched = count($data);
        if ($rows_fetched > 0) {
            $sql_inserts = ["SET FOREIGN_KEY_CHECKS=0"];
            $sql_inserts = array_merge($sql_inserts, $databasesync->generateSQLInserts($table, $data, (200 * 1024 * 1024)));

            foreach ($sql_inserts as &$sqlinsert) {
                $sqlinsert .= ";" . PHP_EOL;
            }

            // Write to sql file
            $file_append_result = file_put_contents($filepath, $sql_inserts, FILE_APPEND);
            if ($file_append_result === false) {
                // translators: %s is replaced with file path to database backup
                $result->errors[] = sprintf(__("Appending databasebackup to %s failed.", "wpsynchro"), $filepath);
            } else {
                $result->debugs[] = "Wrote data for table " . $table->name;
            }

            if ($table->completed_rows < $table->rows) {
                $table->completed_rows += $rows_fetched;
            }
        }

        $result->table = $table;

        $returnresult = new ReturnResult();
        $returnresult->init();
        $returnresult->setDataObject($result);
        return $returnresult->echoDataFromServiceAndExit();
    }

    /**
     *  Delete backup files, if needed, based on the configuration
     */
    public function deleteOldBackups($path)
    {
        // Get files ending with sql
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $value) {
            if ($value->isFile() && strtolower($value->getExtension()) === 'sql') {
                $files[] = [$value->getMTime(), $value->getRealPath()];
            }
        }

        // If more than 19, aka 20 or above, delete the oldest
        if (count($files) > 19) {
            // Sort by timestamp
            usort($files, function ($a, $b) {
                if ($a[0] == $b[0]) {
                    return 0;
                }
                return $a[0] > $b[0] ? 1 : -1;
            });

            // Remove the last 19 we want to keep
            array_splice($files, count($files) - 19, 19);

            // Delete the other files
            foreach ($files as $file) {
                @unlink($file[1]);
            }
        }
    }
}
