<?php

/**
 * Save migration
 */

namespace WPSynchro\API;

use WPSynchro\Migration\Migration;
use WPSynchro\Migration\MigrationFactory;
use WPSynchro\Schedule\ScheduleFactory;
use WPSynchro\Utilities\CommonFunctions;

class SaveMigration extends WPSynchroService
{
    public function service()
    {
        $nonce = $_GET['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'wpsynchro-addedit')) {
            \http_response_code(401);
            return;
        }

        // Extract parameters
        $body = $this->getRequestBody();
        $parameters = json_decode($body);

        // If json is invalid for some reason
        if (is_null($parameters)) {
            \http_response_code(400);
            return;
        }

        $migration = Migration::map($parameters);
        if (strlen($migration->id) == 0) {
            $migration->id = uniqid();
        }

        // Sanitize
        $migration->sanitize();

        // Save
        $migration_factory = MigrationFactory::getInstance();
        $migration_factory->addMigration($migration);

        // Update all the scheduled migrations according to our migrations
        if (CommonFunctions::isPremiumVersion()) {
            $all_migrations = $migration_factory->getAllMigrations();
            $schedule_factory = ScheduleFactory::getInstance();
            $schedule_factory->synchronizeWithMigrations($all_migrations);
        }

        // Return the sanitized version
        echo json_encode($migration);
    }
}
