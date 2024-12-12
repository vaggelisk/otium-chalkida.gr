<?php

namespace WPSynchro\Utilities;

use WPSynchro\Utilities\DatabaseTables;
use WPSynchro\Transport\TransferAccessKey;
use WPSynchro\Utilities\Configuration\PluginConfiguration;
use WPSynchro\Utilities\Upgrade\DatabaseUpgrade;

/**
 * Class for handling activate/deactivate/uninstall tasks for WP Synchro
 */
class Activation
{
    /**
     *  Activate
     */
    public static function activate($networkwide)
    {
        /**
         *  If multisite and network activated, give error to prevent it from happening
         */
        if (is_multisite() && $networkwide) {
            wp_die(__('WP Synchro does not support being network activated - Activate it on the sites needed instead. Beware that multisite is not supported, so use at own risk.', 'wpsynchro'), '', ['back_link' => true]);
        }

        /**
         *  Make sure there is a default access key for migration
         */
        $accesskey = get_option('wpsynchro_accesskey');
        if (!$accesskey || strlen($accesskey) < 10) {
            $new_accesskey = TransferAccessKey::generateAccesskey();
            update_option('wpsynchro_accesskey', $new_accesskey, false);
        }

        /**
         * Create uploads log dir
         */
        $plugins_dirs = new PluginDirs();
        $plugins_dirs->getUploadsFilePath();

        /**
         * Check PHP/MySQL/WP versions
         */
        $commonfunctions = new \WPSynchro\Utilities\CommonFunctions();
        $compat_errors = $commonfunctions->checkEnvCompatability();
        // @codeCoverageIgnoreStart
        if (count($compat_errors) > 0) {
            foreach ($compat_errors as $error) {
                echo $error . '<br>';
            }
            die();
        }
        // @codeCoverageIgnoreEnd

        /**
         * Check that DB contains current WP Synchro DB version
         */
        DatabaseUpgrade::checkDBVersion();

        /**
         * Set a license key if empty
         */
        $licensekey = get_option('wpsynchro_license_key');
        if (!$licensekey) {
            update_option('wpsynchro_license_key', '', false);
        }

        /**
         *  Active the MU plugin
         */
        $plugin_configuration = new PluginConfiguration();
        $plugin_configuration->setMUPluginEnabledState(true);

        /**
         *  Create tables
         */
        $database_tables = new DatabaseTables();
        $database_tables->createSyncListTable();
        $database_tables->createFilePopulationTable();
    }

    /**
     *  Deactivation
     */
    public static function deactivate()
    {
        // Deactivate MU plugin if exists
        $mupluginhandler = new \WPSynchro\Utilities\Compatibility\MUPluginHandler();
        $mupluginhandler->disablePlugin();

        // Clear cron
        wp_clear_scheduled_hook('wpsynchro_cron_scheduled_migrations');
    }

    /**
     *  Uninstall
     */
    public static function uninstall()
    {
        // Deactivate MU plugin if exists
        $mupluginhandler = new \WPSynchro\Utilities\Compatibility\MUPluginHandler();
        $mupluginhandler->disablePlugin();

        // Remove database tables
        global $wpdb;
        $tablename = $wpdb->prefix . DatabaseTables::FILE_POPULATION;
        $wpdb->query('drop table if exists `' . $tablename . '`');
        $tablename = $wpdb->prefix . DatabaseTables::SYNC_LIST;
        $wpdb->query('drop table if exists `' . $tablename . '`');

        // Remove all database entries
        global $wpdb;
        $wpdb->query('delete FROM ' . $wpdb->options . " WHERE option_name like '%wpsynchro%' ");

        // Remove log dir and all files
        $plugins_dirs = new PluginDirs();
        $log_dir = $plugins_dirs->getUploadsFilePath();
        $filelist = scandir($log_dir);
        foreach ($filelist as $file) {
            if ($file == '..' || $file == '.') {
                continue;
            }
            @unlink($log_dir . '/' . $file);
        }
        @rmdir($log_dir);

        // Thats all, all should be clear
        // kk bye thx
    }
}
