<?php

/**
 * Class for handling what to show when clicking on support in the menu in wp-admin
 */

namespace WPSynchro\Pages;

use WPSynchro\Utilities\CommonFunctions;
use WPSynchro\Utilities\DebugInformation;
use WPSynchro\Utilities\Licensing\Licensing;
use WPSynchro\Utilities\PluginDirs;

class AdminSupport
{
    private $show_delete_settings_notice = false;

    /**
     *  Called from WP menu to show support
     */
    public static function render()
    {
        $instance = new self();
        // Handle post
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $instance->handlePOST();
        }
        $instance->handleGET();
    }

    /**
     *  Handle the update of data from support screen
     */
    private function handlePOST()
    {
        // Check if it is delete settings
        if (isset($_POST['deletesettings']) && $_POST['deletesettings'] == 1) {
            $nonce = $_POST['nonce'] ?? '';
            if (!wp_verify_nonce($nonce, 'wpsynchro_delete_all_data')) {
                echo "<div class='notice wpsynchro-notice'><p>" . __('Security token is no longer valid - Go back and try again.', 'wpsynchro') . '</p></div>';
                return;
            }
            $this->cleanUpPluginMigration();
            $this->show_delete_settings_notice = true;
            return;
        }
    }

    /**
     *  Show WP Synchro support screen
     */
    private function handleGET()
    {
        $debug_obj = new DebugInformation();
        $debug_json = $debug_obj->getJSONDebugInformation();

        if (CommonFunctions::isPremiumVersion()) {
            // Licensing
            $licensing = new Licensing();
        }

        // Nonces
        $delete_all_settings_nonce = wp_create_nonce('wpsynchro_delete_all_data');

        // Data for JS
        $data_for_js = [
            'delete_all_settings_nonce' => $delete_all_settings_nonce,
            'show_delete_settings_notice' => $this->show_delete_settings_notice,
            'isPro' => CommonFunctions::isPremiumVersion() && $licensing->verifyLicense(),
            'debugJson' => $debug_json,
        ];
        wp_localize_script('wpsynchro_admin_js', 'wpsynchro_support_data', $data_for_js);

        // Print content
        echo '<div id="wpsynchro-support" class="wpsynchro"></div>';
    }

    /**
     *  Clean up WP Synchro migration (used in setup)
     */
    public function cleanUpPluginMigration()
    {
        // Setup
        $plugins_dirs = new PluginDirs();
        $log_dir = $plugins_dirs->getUploadsFilePath();

        // Clean files
        @array_map('unlink', glob("$log_dir*.log"));
        @array_map('unlink', glob("$log_dir*.sql"));
        @array_map('unlink', glob("$log_dir*.txt"));
        @array_map('unlink', glob("$log_dir*.tmp"));

        // Delete from database
        $options_to_keep = [
            'wpsynchro_license_key',
            'wpsynchro_dbversion',
            'wpsynchro_accesskey',
            'wpsynchro_allowed_methods',
            'wpsynchro_muplugin_enabled',
            'wpsynchro_uploads_dir_secret'
        ];

        global $wpdb;
        $wpdb->query('delete FROM ' . $wpdb->options . " WHERE option_name like 'wpsynchro_%' and option_name not in ('" . implode("','", $options_to_keep) . "') ");
    }
}
