<?php

namespace WPSynchro\Pages;

use WPSynchro\Utilities\CommonFunctions;
use WPSynchro\Migration\Migration;
use WPSynchro\Migration\MigrationFactory;

/**
 * Adding or editing a migration in wp-admin
 */
class AdminAddEdit
{
    public static function render()
    {
        $instance = new self();
        $instance->handleGET();
    }

    private function handleGET()
    {
        // Check php/wp/mysql versions
        $commonfunctions = new CommonFunctions();
        $compat_errors = $commonfunctions->checkEnvCompatability();

        // Set the id
        if (isset($_REQUEST['migration_id'])) {
            $id = sanitize_text_field($_REQUEST['migration_id']);
        } else {
            $id = '';
        }

        // Get the data
        $migration_factory = MigrationFactory::getInstance();
        $migration = $migration_factory->retrieveMigration($id);

        if ($migration == false) {
            $migration = new Migration();
        }

        // Is PRO version
        $is_pro = $commonfunctions::isPremiumVersion();

        // Localize the script with data
        $js_data = [
            'nonce' => wp_create_nonce('wpsynchro-addedit'),
            'compat_errors' => $compat_errors,
            'migration' => $migration,
            'overview_url' => menu_page_url('wpsynchro_menu', false),
            'is_pro' => $is_pro,
            'home_url' => trailingslashit(get_home_url()),

        ];
        wp_localize_script('wpsynchro_admin_js', 'wpsynchro_addedit', $js_data);

        // Print content
        echo '<div id="wpsynchro-addedit"></div>';
    }
}
