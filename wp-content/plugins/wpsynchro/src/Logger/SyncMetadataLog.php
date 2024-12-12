<?php

namespace WPSynchro\Logger;

use WPSynchro\Utilities\CommonFunctions;
use WPSynchro\Migration\Job;
use WPSynchro\Utilities\PluginDirs;

/**
 * Class for handling logging data on sync for use in logs menu (not the logger-logger, but just a log...  :) )
 */
class SyncMetadataLog
{
    /**
     *  Start a migration entry in the log
     */
    public function startMigration($job_id, $migration_id, $description)
    {
        // Get logs
        $synclog = $this->getAllLogs();

        // Create the new one
        $newsync = new \stdClass();
        $newsync->start_time = time();
        $newsync->state = 'started';
        $newsync->description = $description;
        $newsync->job_id = $job_id;
        $newsync->migration_id = $migration_id;

        // If list is above 19 (20+), remove one
        if (count($synclog) > 19) {
            $synclog = array_reverse($synclog);
            $this->removeSingleLogs(array_splice($synclog, 19, 9999));
            $synclog = array_reverse($synclog);
        }

        $synclog[] = $newsync;
        update_option("wpsynchro_sync_logs", $synclog, 'no');
    }

    /**
     *  Mark a migration entry as completed
     */
    public function stopMigration($job_id, $migration_id)
    {
        $synclog = $this->getAllLogs();
        foreach ($synclog as &$log) {
            if ($log->job_id === $job_id && $log->migration_id === $migration_id) {
                $log->state = "completed";
                update_option("wpsynchro_sync_logs", $synclog, 'no');
                return true;
            }
        }
        return false;
    }

    /**
     *  Mark a migration entry as failed
     */
    public function setMigrationToFailed($job_id, $migration_id)
    {
        $synclog = $this->getAllLogs();
        foreach ($synclog as &$log) {
            if ($log->job_id === $job_id && $log->migration_id === $migration_id) {
                $log->state = "failed";
                update_option("wpsynchro_sync_logs", $synclog, 'no');
                return true;
            }
        }
        return false;
    }


    /**
     *  Retrieve all log entries
     */
    public function getAllLogs()
    {
        $synclog = get_option("wpsynchro_sync_logs");
        if (!is_array($synclog)) {
            $synclog = [];
        }

        // Check if any db backups are attached to these and present on the fs
        $plugins_dirs = new PluginDirs();
        $log_location = $plugins_dirs->getUploadsFilePath();
        foreach ($synclog as $log) {
            $filename = "database_backup_" . $log->job_id . ".sql";
            if (file_exists($log_location . $filename)) {
                $log->db_backup_is_on_disk = true;
            } else {
                $log->db_backup_is_on_disk = false;
            }
        }

        // Format date time according to wp settings
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $timezone_string = wp_timezone_string();
        foreach ($synclog as $log) {
            $dt = new \DateTime();
            $dt->setTimestamp($log->start_time);
            $dt->setTimezone(new \DateTimeZone($timezone_string));
            $log->start_time_formatted = $dt->format($date_format . ' ' . $time_format);
        }

        return $synclog;
    }

    /**
     *  Remove list of single logs
     */
    public function removeSingleLogs($logs)
    {
        $plugins_dirs = new PluginDirs();
        $log_dir = $plugins_dirs->getUploadsFilePath();
        $common = new CommonFunctions();

        foreach ($logs as $log) {
            // Remove data in db
            $option_to_delete = Job::getJobWPOptionName($log->migration_id, $log->job_id);
            delete_option($option_to_delete);

            // Remove associated files
            @unlink($log_dir . $common->getLogFilename($log->job_id));
            @unlink($log_dir . "database_backup_" . $log->job_id . ".sql");
        }
    }

    /**
     * Delete single log
     */
    public function deleteSingleLog(string $job_id)
    {
        $logs = $this->getAllLogs();
        foreach ($logs as $index => $log) {
            if ($log->job_id == $job_id) {
                $this->removeSingleLogs([$log]);
                array_splice($logs, $index, 1);
                update_option("wpsynchro_sync_logs", $logs, 'no');
                break;
            }
        }
    }

    /**
     *  Remove all log entries
     */
    public function removeAllLogs()
    {
        // Get all current logs
        $logs = $this->getAllLogs();

        // Remove all log files from wpsynchro dir
        $plugins_dirs = new PluginDirs();
        $log_dir = $plugins_dirs->getUploadsFilePath();

        // Clean files *.log, *.sql and *.txt
        @array_map('unlink', glob("$log_dir*.sql"));
        @array_map('unlink', glob("$log_dir*.log"));
        @array_map('unlink', glob("$log_dir*.txt"));
        $options_to_delete = [];
        foreach ($logs as $log) {
            if (isset($log->installation_id)) {
                $options_to_delete[] = 'wpsynchro_' . $log->installation_id . '_' . $log->job_id;
            } elseif (isset($log->migration_id)) {
                $options_to_delete[] = 'wpsynchro_' . $log->migration_id . '_' . $log->job_id;
            }

            if (count($options_to_delete) > 30) {
                // @codeCoverageIgnoreStart
                $this->deleteLogEntriesInDatabase($options_to_delete);
                $options_to_delete = [];
                // @codeCoverageIgnoreEnd
            }
        }
        if (count($options_to_delete) > 0) {
            $this->deleteLogEntriesInDatabase($options_to_delete);
            $options_to_delete = [];
        }

        update_option("wpsynchro_sync_logs", [], 'no');
    }

    /**
     *  Delete log entries in database
     */
    public function deleteLogEntriesInDatabase($log_options_to_delete)
    {
        global $wpdb;
        foreach ($log_options_to_delete as $log_option) {
            delete_option($log_option);
        }
    }
}
