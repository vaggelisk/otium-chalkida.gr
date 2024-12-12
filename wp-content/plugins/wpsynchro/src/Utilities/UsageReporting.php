<?php

namespace WPSynchro\Utilities;

use WPSynchro\Utilities\CommonFunctions;
use WPSynchro\Migration\MigrationController;
use WPSynchro\Migration\MigrationFactory;
use WPSynchro\Transport\RemoteTransport;
use WPSynchro\Utilities\Configuration\PluginConfiguration;

/**
 * Usage reporting class
 *
 */
class UsageReporting
{
    const VERSION = 1;
    private $usage_reporting_url = "https://daev.tech/api/wpsynchro/v1/usage-reporting";
    private $migration = null;
    // Dependencies
    private $logger;

    /**
     *  Constructor
     */
    public function __construct()
    {
        $this->logger = MigrationController::getInstance()->getLogger();
    }

    /**
     * Send the usage reporting
     */
    public function sendUsageReporting($migration)
    {
        $this->migration = $migration;
        $this->migration->checkAndUpdateToPreset();

        // Check if user has accepted usage reporting
        $plugin_configuration = new PluginConfiguration();
        if (!$plugin_configuration->getUsageReportingSetting()) {
            return;
        }

        // Collect data for usage reporting
        $data = $this->getData();

        // Log the data in current sync log file, to provide transparency as to what we are sending back
        $this->logger->log("DEBUG", "Usage reporting data sent to daev.tech server:", $data);

        // Send it
        $remotetransport = new RemoteTransport();
        $remotetransport->init();
        $remotetransport->setUrl($this->usage_reporting_url);
        $remotetransport->setDataObject($data);
        $remotetransport->setSendDataAsJSON();
        $remotetransport->blocking_request = false;
        $remotetransport->remotePOST();
    }

    /**
     * Get the data to send with usage reporting
     */
    public function getData()
    {
        $migration_factory = MigrationFactory::getInstance();
        $migration_count = count($migration_factory->getAllMigrations());

        $data = [
            'version' => self::VERSION,
            'site_hash' => sha1(get_home_url()),
            'lang' => get_locale(),
            'is_pro' => CommonFunctions::isPremiumVersion(),
            'migration_count' => $migration_count,
            'total_migrations' => get_option('wpsynchro_success_count', 0),
            'features_used_this_sync' => [
                'success_notification_email' => count(explode(';', $this->migration->success_notification_email_list)),
                'error_notification_email' => count(explode(';', $this->migration->error_notification_email_list)),
                'clear_cache_on_success' => $this->migration->clear_cache_on_success,
                'sync_preset' => $this->migration->sync_preset,
                'sync_database' => $this->migration->sync_database,
                'sync_files' => $this->migration->sync_files,
                'db_make_backup' => $this->migration->db_make_backup,
                'db_table_prefix_change' => $this->migration->db_table_prefix_change,
                'db_preserve_options_table_keys' => $this->migration->db_preserve_options_table_keys,
                'db_preserve_options_custom' => $this->migration->db_preserve_options_custom,
                'include_all_database_tables' => $this->migration->include_all_database_tables,
                'only_include_database_table_count' => count($this->migration->only_include_database_table_names),
                'searchreplaces_count' => count($this->migration->searchreplaces),
                'file_locations_count' => count($this->migration->file_locations),
                'files_exclude_files_match_count' => count(explode(',', $this->migration->files_exclude_files_match)),
                'files_ask_user_for_confirm' => $this->migration->files_ask_user_for_confirm,
            ],
        ];

        return $data;
    }
}
