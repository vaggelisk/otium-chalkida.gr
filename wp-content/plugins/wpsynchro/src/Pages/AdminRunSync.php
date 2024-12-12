<?php

namespace WPSynchro\Pages;

use WPSynchro\Utilities\CommonFunctions;
use WPSynchro\Migration\MigrationFactory;
use WPSynchro\Status\MigrateStatus;
use WPSynchro\Utilities\PluginDirs;

/**
 * Class for handling when running a sync
 *
 */
class AdminRunSync
{
    public static function render()
    {
        $instance = new self();
        $instance->handleGET();
    }

    private function handleGET()
    {
        $commonfunctions = new CommonFunctions();

        if (isset($_REQUEST['migration_id'])) {
            $id = $_REQUEST['migration_id'];
        } else {
            $id = "";
        }
        if (isset($_REQUEST['job_id'])) {
            $job_id = $_REQUEST['job_id'];
        } else {
            $job_id = uniqid();
        }

        $nonce = $_REQUEST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'wpsynchro_run_migration')) {
            echo "<div class='notice wpsynchro-notice'><p>" . __('Security token is no longer valid - Go to overview page and try again', 'wpsynchro') . ' - <a href="' . menu_page_url('wpsynchro_menu', false) . '">' . __('Overview', 'wpsynchro') . '</a></p></div>';
            return;
        }

        if (strlen($id) < 1) {
            echo "<div class='notice wpsynchro-notice'><p>" . __('No migration_id provided - This should not happen', 'wpsynchro') . '</p></div>';
            return;
        }

        if (strlen($job_id) < 1) {
            echo "<div class='notice wpsynchro-notice'><p>" . __('No job_id provided - This should not happen', 'wpsynchro') . '</p></div>';
            return;
        }

        // Create log dir if needed
        $plugins_dirs = new PluginDirs();
        $plugins_dirs->getUploadsFilePath();

        // Create new job with this sync
        $migration_factory = MigrationFactory::getInstance();
        $job_id = $migration_factory->startMigrationSync($id, $job_id);
        if ($job_id == null) {
            echo "<div class='notice wpsynchro-notice'><p>" . __('Migration not found - This should not happen', 'wpsynchro') . '</p></div>';
            return;
        }

        // Get base stages
        $status_controller = new MigrateStatus();
        $status_controller->setup($id, $job_id);
        $default_stages = $status_controller->getStages();

        // Get cards to show
        $card_html = '';
        if (!\WPSynchro\Utilities\CommonFunctions::isPremiumVersion()) {
            $card_html .= $commonfunctions->getTemplateFile("card-pro-version");
        }

        // Localize the script with data
        $adminjsdata = [
            'id' => $id,
            'job_id' => $job_id,
            'home_url' => trailingslashit(get_home_url()),
            'default_stages' => $default_stages,
            'cardsHtml' => $card_html,
        ];
        wp_localize_script('wpsynchro_admin_js', 'wpsynchro_run', $adminjsdata);

        $file_accept_modal = [
            'headline' => __("Verify the file changes", "wpsynchro"),
            'button_accept_text' => __("Accept changes", "wpsynchro"),
            'button_decline_text' => __("Decline changes", "wpsynchro"),
            'added_changed_files_tab' => __("Added/changed", "wpsynchro"),
            'deleted_files_tab' => __("Will be deleted", "wpsynchro"),
            'controls_help_text' => __("Choose if you want to see the files with full path, or just see clipped paths that start above the web root.", "wpsynchro"),
            'show_full_path' => __("Show full paths", "wpsynchro"),
            'files_changed_pre_text' => __("Files that will be added or overwritten:", "wpsynchro"),
            'files_deleted_pre_text' => __("Files that will be deleted:", "wpsynchro"),
            'files_no_changed' => __("No files will be added or overwritten.", "wpsynchro"),
            'files_no_deletes' => __("There is no files marked for deletion.", "wpsynchro"),
        ];
        wp_localize_script('wpsynchro_admin_js', 'wpsynchro_file_changes', $file_accept_modal);

        // Print content
        echo '<div id="wpsynchro-run-migration" class="wpsynchro"></div>';
    }
}
