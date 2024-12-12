<?php

namespace WPSynchro\API;

use WPSynchro\Status\MigrateStatus;

/**
 * Class for handling service "status"
 * Call should already be verified by permissions callback
 *
 */
class Status extends WPSynchroService
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

        $status = new MigrateStatus();
        $status->setup($migration_id, $job_id);
        $sync_response = $status->getMigrationStatus();

        if (isset($sync_response->errors) && count($sync_response->errors) > 0) {
            $sync_response->should_continue = false;
        } else {
            $sync_response->should_continue = true;
        }

        echo json_encode($sync_response);
    }
}
