<?php

namespace WPSynchro\Utilities\Upgrade;

use WPSynchro\Migration\MigrationFactory;
use WPSynchro\Utilities\Configuration\PluginConfiguration;
use WPSynchro\Utilities\PluginDirs;

/**
 * Handle database upgrades
 */
class DatabaseUpgrade
{
    /**
     *  Check WP Synchro database version and compare with current
     */
    public static function checkDBVersion()
    {
        $dbversion = get_option('wpsynchro_dbversion');

        // If not set yet, just set it and continue with life
        if (!$dbversion || $dbversion == "") {
            $dbversion = WPSYNCHRO_DB_VERSION;
            update_option('wpsynchro_dbversion', WPSYNCHRO_DB_VERSION, true);
        }

        // Check if it is same as current
        if ($dbversion == WPSYNCHRO_DB_VERSION) {
            // Puuurfect, all good, so return
            return;
        } else {
            // Database is different than current version
            if ($dbversion > WPSYNCHRO_DB_VERSION) {
                // Its newer? :|
                return;
            } else {
                // Its older, so lets upgrade
                self::handleDBUpgrade($dbversion);
            }
        }
    }

    /**
     *  Handle upgrading of DB versions
     */
    public static function handleDBUpgrade($current_version)
    {
        if ($current_version > WPSYNCHRO_DB_VERSION) {
            return false;
        }

        // Version 1 - First DB version, no upgrades needed
        if ($current_version < 1) {
            // nothing to do for first version
        }

        // Version 1 > 2
        if ($current_version < 2) {
            // Enable MU Plugin by default
            $plugin_configuration = new PluginConfiguration();
            $plugin_configuration->setMUPluginEnabledState(true);
        }

        // Version 2 > 3
        if ($current_version < 3) {
            // Update migrations with the new preset setting
            $migration_factory = MigrationFactory::getInstance();
            $migration_factory->getAllMigrations();
            foreach ($migration_factory->migrations as &$migration) {
                $migration->sync_preset = 'none';
                $migration->db_make_backup = false;
                $migration->searchreplaces = [];
            }
            $migration_factory->save();
        }

        // Version 3 > 4
        if ($current_version < 4) {
            // Update migrations with the new table prefix setting
            $migration_factory = MigrationFactory::getInstance();
            $migration_factory->getAllMigrations();
            foreach ($migration_factory->migrations as &$migration) {
                $migration->db_table_prefix_change = true;
            }
            $migration_factory->save();
        }

        // Version 4 > 5
        if ($current_version < 5) {
            // Clear file population object from db, as it has been changed
            delete_option("wpsynchro_filepopulation_current");
            // Remove IP security option, as it is removed in 1.6.0
            delete_option("wpsynchro_ip_security_enabled");
            // Set all migrations as "direct" connections
            $migration_factory = MigrationFactory::getInstance();
            $migration_factory->getAllMigrations();
            foreach ($migration_factory->migrations as &$migration) {
                $migration->connection_type = "direct";
            }
            $migration_factory->save();
        }

        // Version 5 > 6
        if ($current_version < 6) {
            delete_option("wpsynchro_debuglogging_enabled");
        }

        // Version 6 > 7 (1.6.4 > 1.7.0)
        if ($current_version < 7) {
            $migration_factory = MigrationFactory::getInstance();
            $migration_factory->getAllMigrations();
            foreach ($migration_factory->migrations as &$migration) {
                $migration->files_ask_user_for_confirm = false;
            }
            $migration_factory->save();
        }

        // Version 7 > 8 (1.7.3 > 1.8.0)
        if ($current_version < 8) {
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name='wpsynchro_migrations'");
            $wpdb->query("UPDATE {$wpdb->options} SET option_name='wpsynchro_migrations' WHERE option_name='wpsynchro_installations'");
            // WordPress cache flush, because of wp updates
            wp_cache_flush();

            $migration_factory = MigrationFactory::getInstance();
            $migration_factory->getAllMigrations();
            foreach ($migration_factory->migrations as &$migration) {
                // Add new field
                $migration->db_preserve_options_table_keys = [];
                // Add preserve plugins, if previously set
                if (isset($migration->db_preserve_activeplugins) && $migration->db_preserve_activeplugins == true) {
                    $migration->db_preserve_options_table_keys[] = 'active_plugins';
                }
                unset($migration->db_preserve_activeplugins);
            }
            $migration_factory->save();
        }

        // Version 8 > 9 (1.8.1 > 1.8.2) - Remove migrations with empty search/replaces, because of bug (WS-99)
        if ($current_version < 9) {
            $migration_factory = MigrationFactory::getInstance();
            $migration_factory->getAllMigrations();
            foreach ($migration_factory->migrations as &$migration) {
                if (empty($migration->searchreplaces)) {
                    $migration_factory->deleteMigration($migration->id);
                }
            }
            $migration_factory->save();
        }

        // Version 9 > 10 (1.11.5 > 1.12.0) - Move log files to a new location
        if ($current_version < 10) {
            $old_log_dir = wp_upload_dir()['basedir'] . "/wpsynchro/";
            $plugin_dirs = new PluginDirs();
            $new_log_dir = $plugin_dirs->getUploadsFilePath();
            if (file_exists($old_log_dir)) {
                $filelist = scandir($old_log_dir);
                foreach ($filelist as $file) {
                    if ($file == '.' || $file == '..') {
                        continue;
                    }
                    @rename($old_log_dir . $file, $new_log_dir . $file);
                }
                @rmdir($old_log_dir);
            }
        }

        // Set to the db version for this release
        update_option('wpsynchro_dbversion', WPSYNCHRO_DB_VERSION, true);
        return true;
    }
}
