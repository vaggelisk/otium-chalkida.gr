<?php

/**
 * Class for handling what to show when clicking on the menu in wp-admin
 */

namespace WPSynchro\Pages;

use WPSynchro\Utilities\CommonFunctions;
use WPSynchro\Migration\MigrationFactory;
use WPSynchro\Utilities\Configuration\PluginConfiguration;
use WPSynchro\Utilities\Licensing\Licensing;

class AdminOverview
{
    public $migration_factory;

    public function __construct()
    {
        $this->migration_factory = MigrationFactory::getInstance();
    }

    public static function render()
    {

        $instance = new self();
        $instance->handleGET();
    }

    private function handleGET()
    {
        // Check php/wp/mysql versions
        $commonfunctions = new CommonFunctions();
        $plugin_configuration = new PluginConfiguration();
        $compat_errors = $commonfunctions->checkEnvCompatability();

        // Review notification
        $success_count = get_site_option("wpsynchro_success_count", 0);
        $request_review_dismissed = get_site_option("wpsynchro_dismiss_review_request", false);
        $show_review_notification = $success_count >= 10 && !$request_review_dismissed;
        $review_notification_text = sprintf(
            __("You have used WP Synchro %d times now - We hope you are enjoying it and have saved some time and troubles.<br>
            We try really hard to give you a high quality tool for WordPress site migrations.<br>
            If you enjoy using WP Synchro, we would appreciate your review on
            <a href='https://wordpress.org/support/plugin/wpsynchro/reviews/?rate=5#new-post' target='_blank'>WordPress plugin repository</a>.<br>
            Thank you for the help.", "wpsynchro"),
            $success_count
        );

        // Check if user has selected to accept usage reporting
        if (isset($_GET['usage_reporting'])) {
            $usage_reporting_selection = $_GET['usage_reporting'] == 1 ? true : false;
            $plugin_configuration->setUsageReportingSetting($usage_reporting_selection);
        }
        $show_usage_reporting = $plugin_configuration->getUsageReportingSetting() === null;

        // Check for delete
        if (isset($_GET['delete'])) {
            $delete = $_GET['delete'];
        } else {
            $delete = "";
        }

        // If delete
        if (strlen($delete) > 0) {
            $delete_nonce = $_REQUEST['nonce'] ?? '';
            if (wp_verify_nonce($delete_nonce, 'wpsynchro_delete_migration')) {
                $migration_factory = MigrationFactory::getInstance();
                $migration_factory->deleteMigration($delete);
            } else {
                echo "<div class='notice wpsynchro-notice'><p>" . __('Migration was not deleted, as the security token is no longer valid. Try again.', 'wpsynchro') . '</p></div>';
            }
        }

        // Check for duplicate
        if (isset($_GET['duplicate'])) {
            $duplicate = $_GET['duplicate'];
        } else {
            $duplicate = "";
        }

        // If duplicate
        if (strlen($duplicate) > 0) {
            $duplicate_nonce = $_REQUEST['nonce'] ?? '';
            if (wp_verify_nonce($duplicate_nonce, 'wpsynchro_duplicate_migration')) {
                $migration_factory = MigrationFactory::getInstance();
                $migration_factory->duplicateMigration($duplicate);
            } else {
                echo "<div class='notice wpsynchro-notice'><p>" . __('Migration was not duplicated, as the security token is no longer valid. Try again.', 'wpsynchro') . '</p></div>';
            }
        }

        // Check if healthcheck should be run
        $run_healthcheck = false;
        if (CommonFunctions::isPremiumVersion()) {
            $licensing = new Licensing();
            if ($licensing->hasProblemWithLicensing()) {
                $run_healthcheck = true;
            }
        }
        if (!$run_healthcheck) {
            $healthcheck_last_success = intval(get_site_option("wpsynchro_healthcheck_timestamp", 0));
            $seconds_in_week = 604800; // 604800 is one week
            if (($healthcheck_last_success + $seconds_in_week) < time()) {
                $run_healthcheck = true;
            }
        }

        // migration data
        $data = $this->migration_factory->getAllMigrations();
        usort($data, function ($a, $b) {
            return strcmp($a->name, $b->name);
        });

        // Cards
        $card_content = "";
        if (!\WPSynchro\Utilities\CommonFunctions::isPremiumVersion()) {
            $card_content .= $commonfunctions->getTemplateFile("card-pro-version");
        }
        //$card_content .= $commonfunctions->getTemplateFile("card-mailinglist");
        //$card_content .= $commonfunctions->getTemplateFile("card-facebook");

        // Nonces
        $run_migration_nonce = wp_create_nonce('wpsynchro_run_migration');
        $delete_migration_nonce = wp_create_nonce('wpsynchro_delete_migration');
        $duplicate_migration_nonce = wp_create_nonce('wpsynchro_duplicate_migration');

        // Data for JS
        $data_for_js = [
            "isPro" => \WPSynchro\Utilities\CommonFunctions::isPremiumVersion(),
            "pageUrl" => menu_page_url('wpsynchro_menu', false),
            "runSyncUrl" => admin_url('admin.php?page=wpsynchro_run'),
            "runSyncNonce" => $run_migration_nonce,
            'deleteMigrationNonce' => $delete_migration_nonce,
            'duplicateMigrationNonce' => $duplicate_migration_nonce,
            "AddEditUrl" => admin_url('admin.php?page=wpsynchro_addedit'),
            "compatErrors" => $compat_errors,
            "showReviewNotification" => $show_review_notification,
            "reviewNotificationText" => $review_notification_text,
            "cardContent" => $card_content,
            "runHealthcheck" => $run_healthcheck,
            "showUsageReporting" => $show_usage_reporting,
            "reviewNotificationDismissUrl" => add_query_arg(['wpsynchro_dismiss_review_request' => 1], admin_url()),
            "addMigrationUrl" => admin_url('admin.php?page=wpsynchro_addedit'),
            "migrationData" => $data,
        ];
        wp_localize_script('wpsynchro_admin_js', 'wpsynchro_overview_data', $data_for_js);

        // Print content
        echo '<div id="wpsynchro-overview" class="wpsynchro"></div>';
    }
}
