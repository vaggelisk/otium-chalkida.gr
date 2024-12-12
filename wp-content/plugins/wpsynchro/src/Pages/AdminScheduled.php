<?php

/**
 * List of scheduled migrations
 */

namespace WPSynchro\Pages;

use WPSynchro\Migration\MigrationFactory;
use WPSynchro\Schedule\ScheduleFactory;

class AdminScheduled
{
    public static function render()
    {
        // Load scheduled migrations
        $scheduled_factory = ScheduleFactory::getInstance();
        $scheduled_migrations = $scheduled_factory->getScheduledMigrations();

        // Decorate data for presentation
        foreach ($scheduled_migrations as $schduled_migration) {
            $schduled_migration->decorateForPresentation();
        }

        // Data for JS
        $data_for_js = [
            'scheduled_migrations' => $scheduled_migrations,
        ];
        wp_localize_script('wpsynchro_admin_js', 'wpsynchro_scheduled_data', $data_for_js);

        // Print content
        echo '<div id="wpsynchro-scheduled" class="wpsynchro"></div>';
    }
}
