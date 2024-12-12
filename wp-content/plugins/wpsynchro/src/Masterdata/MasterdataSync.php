<?php

namespace WPSynchro\Masterdata;

use WPSynchro\Database\DatabaseSync;
use WPSynchro\Migration\MigrationController;
use WPSynchro\Transport\Destination;
use WPSynchro\Utilities\CommonFunctions;
use WPSynchro\Utilities\Licensing\Licensing;
use WPSynchro\Utilities\SyncTimerList;

/**
 * Class for handling the masterdata of the sync
 *
 */
class MasterdataSync
{
    // Base data
    public $starttime = 0;
    public $migration = null;
    public $job = null;
    public $remote_wpdb = null;
    // Dependencies
    public $logger = null;
    public $timer = null;

    /**
     *  Constructor
     */
    public function __construct()
    {
        $this->logger = MigrationController::getInstance()->getLogger();
        $this->timer = SyncTimerList::getInstance();
    }

    /**
     *  Handle masterdata step
     */
    public function runMasterdataStep(&$migration, &$job)
    {
        $masterdata_timer = $this->timer->startTimer("masterdata", "overall", "timer");

        $this->migration = &$migration;
        $this->job = &$job;

        $this->logger->log("INFO", "Getting masterdata from source and target with remaining time:" . $this->timer->getRemainingSyncTime());

        // Figure out what data is needed
        $data_to_retrieve = [];
        $data_to_retrieve[] = "dbdetails";
        $data_to_retrieve[] = "filedetails";

        // Retrieve data
        $metadata_results = [];
        $metadata_results['from'] = $this->retrieveMasterdata(new Destination(Destination::SOURCE), $data_to_retrieve);
        $metadata_results['to'] = $this->retrieveMasterdata(new Destination(Destination::TARGET), $data_to_retrieve);

        foreach ($metadata_results as $prefix => $masterdata_content) {
            if (in_array("dbdetails", $data_to_retrieve)) {
                if (!isset($masterdata_content->dbdetails) || $masterdata_content->dbdetails == null) {
                    $errormsg = sprintf(__("Did not retrieve correct database masterdata from target '%s' - See log file", "wpsynchro"), $prefix);
                    $this->job->errors[] = $errormsg;
                    $this->logger->log("CRITICAL", $errormsg, $metadata_results);
                    return;
                }
            }

            // Process and map the data to Job object
            $this->handleMasterdataMapping($prefix, $masterdata_content);
        }

        // Handle system search replaces depending on setup
        $this->findSystemSearchReplaces();

        // Initize some configuration in the job object
        $this->inititializeConfigurations();

        // Check that the migration is compatible with the masterdata gotten
        $this->checkMigrationCompatibility();

        if (count($this->job->errors) == 0) {
            // Check that plugin versions are identical on both sides, otherwise raise error
            if ($this->job->from_plugin_version != $this->job->to_plugin_version) {
                $this->job->errors[] = sprintf(__("WP Synchro plugin versions do not match on both sides. One runs version %s and other runs %s. Make sure they use same version to prevent problems caused by different versions of plugin.", "wpsynchro"), $this->job->from_plugin_version, $this->job->to_plugin_version);
            }

            // Check if WP versions are different, then raise a warning, as it might not be a problem
            if ($this->job->to_wp_version != $this->job->from_wp_version) {
                $this->job->warnings[] = sprintf(__("WordPress versions are different on the source and target. Source WP version is: %s and target WP version is: %s. This is just a warning, as in most cases it will not cause problems. But in some cases it can cause failures in the very last steps of the migration. If you experience that, make sure to have the same WP version on both ends.", "wpsynchro"), $this->job->from_wp_version, $this->job->to_wp_version);
            }

            // Check that prefix are the same or issue warning
            if ($this->migration->sync_database && !$this->migration->db_table_prefix_change && $this->job->from_wpdb_prefix != $this->job->to_wpdb_prefix) {
                $prefix_warning = sprintf(__("Database table prefixes are different on the source and target site. Source uses '%s' and target uses '%s'. Table prefix migration is not enabled in the migration configuration. This is just a warning, as the migration can complete, but the tables will not be used by the target site. Recommended action is to turn on the table prefix migration in the migration configuration.", "wpsynchro"), $this->job->from_wpdb_prefix, $this->job->to_wpdb_prefix);
                $this->job->warnings[] = $prefix_warning;
                $this->logger->log("WARNING", $prefix_warning);
            }

            // If doing file sync, make sure the MU plugin is enabled on the target to prevent all kinds of PHP errors for half-transferred code
            if ($this->migration->sync_files && $this->job->to_mu_plugin_enabled == false) {
                $this->job->errors[] = __("When doing file migrations, it is required to have the WP Synchro MU plugin active on the target site. It can be enabled on the target site in menu WP Synchro > Setup.", "wpsynchro");
            }

            // Check licensing
            if (CommonFunctions::isPremiumVersion() && count($this->job->errors) === 0) {
                $licensing = new Licensing();
                $licens_sync_result = $licensing->verifyLicenseForMigration($this->job->from_client_home_url, $this->job->to_client_home_url);

                if ($licens_sync_result->state === false) {
                    $this->job->errors = array_merge($this->job->errors, $licens_sync_result->errors);
                }
            }
        }

        $this->logger->log("INFO", "Completed masterdata on: " . $this->timer->endTimer($masterdata_timer) . " seconds");

        if (count($this->job->errors) === 0) {
            $this->job->masterdata_completed = true;
        }
    }

    /**
     *  Check that the masterdata is compatible with the migration requested
     */
    public function checkMigrationCompatibility()
    {
        // Database migration checks
        if ($this->migration->sync_database) {
            // Make sure no tables using "json" column type is migrated to a pre 5.7 version.
            foreach ($this->job->from_dbmasterdata as $table) {
                if (version_compare($this->job->to_sql_version, '5.7', '<')) {
                    if ($table->column_types->isColumnTypeUsed('json')) {
                        $this->job->errors[] = sprintf(
                            __('The database table "%s" is not supported on the target database version (%s) and therefore it can not be migrated. To fix this error, either exclude the table from migration or upgrade the target database to a newer version.', 'wpsynchro'),
                            $table->name,
                            $this->job->to_sql_version
                        );
                    }
                }
            }
        }
    }

    /**
     *  Masterdata mapping
     */
    public function handleMasterdataMapping($prefix, $masterdata_content)
    {
        /**
         *  Base mappings
         */
        if (isset($masterdata_content->base)) {
            $mappings = [
                "_client_home_url" => "client_home_url",
                "_wpdb_prefix" => "wpdb_prefix",
                "_wp_options_table" => "wp_options_table",
                "_wp_users_table" => "wp_users_table",
                "_wp_usermeta_table" => "wp_usermeta_table",
                "_max_allowed_packet_size" => "max_allowed_packet_size",
                "_max_post_size" => "max_post_size",
                "_memory_limit" => "memory_limit",
                "_sql_version" => "sql_version",
                "_plugin_version" => "plugin_version",
                "_wp_version" => "wp_version",
                "_mu_plugin_enabled" => "mu_plugin_enabled",
            ];

            foreach ($mappings as $job_key => $masterdata_key) {
                if (!isset($masterdata_content->base->$masterdata_key)) {
                    continue;
                }
                $tmp_var = $prefix . $job_key;
                $this->job->$tmp_var = $masterdata_content->base->$masterdata_key;
            }
        }

        /**
         *  Multisite mapping
         */
        if (isset($masterdata_content->multisite)) {
            $key = $prefix . '_is_multisite';
            $this->job->$key = $masterdata_content->multisite->is_multisite;
            $key = $prefix . '_is_multisite_main_site';
            $this->job->$key = $masterdata_content->multisite->is_main_site;
            $key = $prefix . '_defined_uploads_location';
            $this->job->$key = $masterdata_content->multisite->defined_uploads_location;

            if ($masterdata_content->multisite->is_multisite) {
                $key = $prefix . '_main_blog_id';
                $this->job->$key = $masterdata_content->multisite->main_blog_id;
                $key = $prefix . '_current_blog_id';
                $this->job->$key = $masterdata_content->multisite->current_blog_id;
                $key = $prefix . '_blogs';
                $this->job->$key = $masterdata_content->multisite->blogs;
                $key = $prefix . '_default_super_admin_id';
                $this->job->$key = $masterdata_content->multisite->default_super_admin_id;
                $key = $prefix . '_default_super_admin_username';
                $this->job->$key = $masterdata_content->multisite->default_super_admin_username;
            }
        }


        /**
         *  DB details mapping
         */
        if (isset($masterdata_content->dbdetails)) {
            $tmp_var = $prefix . '_dbmasterdata';
            $this->job->$tmp_var = $masterdata_content->dbdetails;
        }

        /**
         *  Files data mapping
         */
        if (isset($masterdata_content->files)) {
            $mappings = [
                "_files_above_webroot_dir" => "files_above_webroot_dir",
                "_files_home_dir" => "files_home_dir",
                "_files_wp_content_dir" => "files_wp_content_dir",
                "_files_wp_dir" => "files_wp_dir",
                "_files_uploads_dir" => "files_uploads_dir",
                "_files_plugins_dir" => "files_plugins_dir",
                "_files_themes_dir" => "files_themes_dir",
                "_files_plugin_list" => "files_plugin_list",
                "_files_theme_list" => "files_theme_list",
                "_files_uploads_dir" => "files_uploads_dir",
            ];

            foreach ($mappings as $job_key => $masterdata_key) {
                if (!isset($masterdata_content->files->$masterdata_key)) {
                    continue;
                }
                $tmp_var = $prefix . $job_key;
                $this->job->$tmp_var = $masterdata_content->files->$masterdata_key;
            }
        }

        /**
         *  Debug data mapping
         */
        if (isset($masterdata_content->debug)) {
            $tmp_var = $prefix . '_debug';
            $this->job->$tmp_var = $masterdata_content->debug;
        }
    }

    /**
     *  Retrieve masterdata
     */
    public function retrieveMasterdata(Destination $destination, $slugs_to_retrieve = [])
    {
        // Get masterdata retrival object
        $retrieval = new MasterdataRetrieval($destination);
        $retrieval->setDataToRetrieve($slugs_to_retrieve);

        $this->logger->log("DEBUG", "Calling masterdata service on: " .  $destination->getFullURL() . " with intent to user as '" . $destination->getDestination() . "'");
        $result = $retrieval->getMasterdata();

        // Check for errors
        if ($result) {
            return $retrieval->data;
        } else {
            $errormsg = sprintf(__("Could not retrieve masterdata from target '%s', which means we can not continue the migration.", "wpsynchro"), $destination->getFullURL());
            $this->job->errors[] = $errormsg;
            $this->logger->log("CRITICAL", $errormsg);
            return [];
        }
    }

    /**
     *  Initialize some configurations based on the data retrieved from masterdata
     */
    public function inititializeConfigurations()
    {
        // Add system search/replaces
        $this->job->db_search_replaces = array_merge($this->migration->searchreplaces, $this->job->db_system_search_replaces);

        // Ignore all search/replaces - Only used for testing purposes
        if ($this->migration->ignore_all_search_replaces) {
            $this->job->db_search_replaces = [];
        }

        // Clear duplicates from search/replaces
        $this->job->db_search_replaces = $this->removeDuplicatesFromSearchReplaces($this->job->db_search_replaces);

        // Remove tables from dbdata, if not all tables should be synced
        if ($this->migration->include_all_database_tables === false) {
            $onlyinclude = $this->migration->only_include_database_table_names;
            $newdbdata = [];
            foreach ($this->job->from_dbmasterdata as $table) {
                if (in_array($table->name, $onlyinclude)) {
                    $newdbdata[] = $table;
                }
            }
            $this->job->from_dbmasterdata = $newdbdata;
        }

        // Check if any of the tables are actually views, to be handled in a special way
        $views = [];
        $this->job->from_dbmasterdata = array_filter($this->job->from_dbmasterdata, function ($table_obj) use (&$views) {
            if ($table_obj->is_view) {
                $views[] = $table_obj;
                return false;
            }
            return true;
        });
        $this->job->db_views_to_be_synced = $views;

        // Check if tables have 0 rows, so we mark them complete
        foreach ($this->job->from_dbmasterdata as &$table) {
            if ($table->rows == 0) {
                $table->is_completed = true;
            }
        }

        // Remove any temporary tables
        $this->job->from_dbmasterdata = array_filter($this->job->from_dbmasterdata, function ($table_obj) {
            if (strpos($table_obj->name, DatabaseSync::TMP_TABLE_PREFIX) === 0) {
                return false;
            }
            return true;
        });

        // Set max packet size we can send to SQL server at a time
        $this->job->masterdata_max_sql_packet_bytes = min($this->job->from_max_allowed_packet_size, $this->job->to_max_allowed_packet_size);
        // Set max size request data that can be POST'ed to a remote service, based on the lowest on both clients
        $this->job->masterdata_max_tcp_request_bytes = min($this->job->from_max_post_size, $this->job->to_max_post_size) * 0.9;
        // Set the memory limit, based on the lowest on both clients
        $this->job->masterdata_max_memory_limit_bytes = round((min($this->job->from_memory_limit, $this->job->to_memory_limit) - memory_get_usage()) * 0.7);
    }

    /**
     *  Clear duplicates for search/replaces
     */
    public function removeDuplicatesFromSearchReplaces($search_replaces)
    {
        $new_search_replaces = [];
        $search_replace_hashes = [];
        foreach ($search_replaces as $search_replace) {
            $hash = md5($search_replace->to . '-' . $search_replace->from);
            if ($search_replace->to != $search_replace->from && !isset($search_replace_hashes[$hash])) {
                $new_search_replaces[] = $search_replace;
            }
            $search_replace_hashes[$hash] = true;
        }
        return $new_search_replaces;
    }

    /**
     *  Find needed system search/replaces
     */
    public function findSystemSearchReplaces()
    {

        // Check for multisite and if we need any search/replaces
        if ($this->job->from_is_multisite || $this->job->to_is_multisite) {
            // Exclude WP Synchro on these locations also
            $this->job->files_population_source_excludes[] = $this->job->from_files_uploads_dir . "/wpsynchro";
            $this->job->files_population_target_excludes[] = $this->job->to_files_uploads_dir . "/wpsynchro";

            // If both are multisites - So either main<>main or subsite<>subsite
            if ($this->job->from_is_multisite && $this->job->to_is_multisite) {
            }

            // Multisite subsite to singlesize or other way around
            if (($this->job->from_is_multisite && !$this->job->to_is_multisite) || (!$this->job->from_is_multisite && $this->job->to_is_multisite)) {
                // If the source is a multisite and not the main site, exclude all other sites uploads folder
                if ($this->job->from_is_multisite && !$this->job->from_is_multisite_main_site) {
                    $uploads_sites_folder_on_source = dirname(dirname(trailingslashit($this->job->from_files_uploads_dir))) . "/sites/";
                    foreach ($this->job->from_blogs as $blog) {
                        if ($blog->id != $this->job->from_current_blog_id) {
                            $this->job->files_population_source_excludes[] = $uploads_sites_folder_on_source . $blog->id;
                        }
                    }
                }
            }

            // If migrating from multisite subsite to single site, make sure that UPLOADS constant is defined for single site.
            if ($this->job->from_is_multisite && !$this->job->to_is_multisite) {
                $relative_uploads_dir = trim(str_replace($this->job->to_files_home_dir, "", $this->job->to_files_uploads_dir), "/");
                $define_uploads_dir = "DEFINE('UPLOADS','" . $relative_uploads_dir . "');";
                if ($this->job->to_defined_uploads_location != $relative_uploads_dir) {
                    $this->job->finalize_success_messages_frontend[] = sprintf(__("If you are moving uploads directory (media), you must add the following line to your wp-config.php on the site %s : <code>%s</code> - Make sure to add this line before loading WordPress in the bottom of wp-config.php", "wpsynchro"), untrailingslashit($this->job->to_client_home_url), $define_uploads_dir);
                }
                $this->job->finalize_success_messages_frontend[] = __("All users from multisite is moved - Make sure to check and remove unwanted users on single site.", "wpsynchro");
            }
            // If migrating from single site to multisite subsite
            if (!$this->job->from_is_multisite && $this->job->to_is_multisite) {
                $this->job->finalize_success_messages_frontend[] = __("Users from source single site was merged with the users on the multisite. Make sure to check that the users are as expected.", "wpsynchro");
            }
        }
    }
}
