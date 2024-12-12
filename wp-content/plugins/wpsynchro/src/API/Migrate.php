<?php

namespace WPSynchro\API;

use WPSynchro\Migration\MigrationController;

/**
 * Class for handling service "migrate"
 * Call should already be verified by permissions callback
 *
 */
class Migrate extends WPSynchroService
{
    public function service()
    {
        // Extract parameters
        $body = $this->getRequestBody();
        $parameters = json_decode($body);

        if (isset($parameters->migration_id)) {
            $migration_id = $parameters->migration_id;
        } else {
            $migration_id = '';
        }
        if (isset($parameters->job_id)) {
            $job_id = $parameters->job_id;
        } else {
            $job_id = '';
        }
        if (isset($parameters->migration_restart)) {
            $migration_restart = filter_var($parameters->migration_restart, FILTER_VALIDATE_BOOLEAN);
        } else {
            $migration_restart = false;
        }

        $migrate = MigrationController::getInstance();
        $migrate->setup($migration_id, $job_id);

        // If we should restart a failed migration and try to continue
        if ($migration_restart) {
            $migrate->attemptMigrationResume();
        }

        $sync_response = $migrate->runMigration();

        if (isset($sync_response->errors) && count($sync_response->errors) > 0) {
            // Set that we should not continue migration from frontend JS
            $sync_response->should_continue = false;
        } else {
            // Set to frontend to continue migration
            $sync_response->should_continue = true;
        }

        if (isset($sync_response->is_completed) && $sync_response->is_completed === true) {
            $sync_response->should_continue = false;
        }

        echo json_encode($sync_response);
    }
}
