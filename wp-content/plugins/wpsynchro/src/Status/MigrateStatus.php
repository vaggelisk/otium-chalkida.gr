<?php

namespace WPSynchro\Status;

use WPSynchro\Migration\Job;
use WPSynchro\Migration\MigrationFactory;

/**
 * Class for serving migration status to the frontend
 * Called from status service
 *
 */
class MigrateStatus
{
    // Data id's
    public $migration_id;
    public $job_id;
    // Objects
    public $job = null;
    public $migration = null;
    // What are we doing
    public $db_backup = false;
    public $database_sync = false;
    public $files_sync = false;
    // Stages
    public $stages = null;

    /**
     * Setup the data needed for migration status thread
     * @codeCoverageIgnore
     */
    public function setup($migration_id, $job_id)
    {
        $this->migration_id = $migration_id;
        $this->job_id = $job_id;

        // Get job data
        $this->job = new Job();
        $this->job->load($this->migration_id, $this->job_id);

        // Get migration
        $migrationFactory = MigrationFactory::getInstance();
        $this->migration = $migrationFactory->retrieveMigration($this->migration_id);

        $this->setupStages();
    }

    /**
     * Get default sync stages
     */
    public function setupStages()
    {
        /**
         * Figure out what we actually doing
         */
        if (isset($this->migration->sync_database) && $this->migration->sync_database && isset($this->migration->db_make_backup) && $this->migration->db_make_backup) {
            $this->db_backup = true;
        }
        if (isset($this->migration->sync_database) && $this->migration->sync_database) {
            $this->database_sync = true;
        }
        if (isset($this->migration->sync_files) && $this->migration->sync_files) {
            $this->files_sync = true;
        }

        /**
         *  Stages
         */
        $this->stages = [];

        // Initiate
        $this->stages[] = $this->createMigrationStage("initialize", __("Initialize", "wpsynchro"), __("Initiating migration on source and target and sets up security tokens on both ends", "wpsynchro"));

        // Masterdata
        $this->stages[] = $this->createMigrationStage("masterdata", __("Masterdata", "wpsynchro"), __("Fetching masterdata on both source and target and check that we are ready to migrate", "wpsynchro"));

        // Database backup
        if ($this->db_backup) {
            $this->stages[] = $this->createMigrationStage("databasebackup", __("Database backup", "wpsynchro"), __("Backup of database tables that will be changed by database sync. Backup location can be found in the log file, which can be found in the 'Logs' menu", "wpsynchro"));
        }

        // Database sync
        if ($this->database_sync) {
            $this->stages[] = $this->createMigrationStage("databasesync", __('Migrate database', 'wpsynchro'), __("Migrate the database, moving the database table rows to the target", "wpsynchro"));
        }

        // Files sync
        if ($this->files_sync) {
            $this->stages[] = $this->createMigrationStage("filessync", __('Migrate files', 'wpsynchro'), __("Migrate the files, by comparing and transferring the missing files", "wpsynchro"));
        }

        // Finalize
        $this->stages[] = $this->createMigrationStage("finalize", __('Finalizing', 'wpsynchro'), __("Completes the migration by doing the last few steps and cleaning up", "wpsynchro"));
    }

    /**
     * Set status for stage id
     */
    public function setStatus($id, $percent_complete, $status_text)
    {
        foreach ($this->stages as $stage) {
            if ($stage->id == $id) {
                $stage->percent_complete = $percent_complete;
                $stage->status_text = $status_text;
                break;
            }
        }
    }

    /**
     * Get stages
     */
    public function getStages()
    {
        return $this->stages;
    }

    /**
     * Create migration stage object
     */
    public function createMigrationStage($id, $title, $help_text = "")
    {
        $temp = new \stdClass();
        $temp->id = $id;
        $temp->title = $title;
        $temp->help_text = $help_text;
        $temp->percent_complete = 0;
        $temp->status_text = "";
        return $temp;
    }

    /**
     * Get migration status
     */
    public function getMigrationStatus()
    {

        // Initialize
        $this->setStatus("initialize", ($this->job->initiation_completed ? 100 : 10), "");
        // Metadata
        $this->setStatus("masterdata", $this->job->masterdata_progress, "");
        // Database backup
        $this->setStatus("databasebackup", $this->job->database_backup_progress, $this->job->database_backup_progress_description);
        // Database
        $this->setStatus("databasesync", $this->job->database_progress, $this->job->database_progress_description);
        // Files
        $this->setStatus("filessync", $this->job->files_progress, $this->job->files_progress_description);
        // Finalize
        $this->setStatus("finalize", $this->job->finalize_progress, $this->job->finalize_progress_description);

        // User confirms - stuff we want confirmed by user
        $user_confirms = [];
        if ($this->job->files_ready_for_user_confirm && !$this->job->files_user_confirmed_actions) {
            $user_confirms['confirmFileActions'] = true;
        }

        // Check if PHP process may have stopped, due to errors, but only if we are not waiting for any confirms
        if (count($user_confirms) == 0) {
            if ($this->job->run_lock_problem_time > 0 && $this->job->run_lock_problem_time < time()) {
                $this->job->errors[] = __("The migration process seem to have problems - It may be PHP errors - please check the PHP logs", "wpsynchro");
            }
        }

        // Set results
        $result = new \stdClass();
        $result->is_completed = $this->job->is_completed;
        $result->stages = $this->stages;
        $result->errors = $this->job->errors;
        $result->warnings = $this->job->warnings;
        $result->userConfirms = $user_confirms;

        return $result;
    }
}
