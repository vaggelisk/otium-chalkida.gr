<?php

namespace WPSynchro\API;

use WPSynchro\Utilities\CommonFunctions;
use WPSynchro\Database\DatabaseSync;
use WPSynchro\Database\Table;
use WPSynchro\Database\TableColumns;
use WPSynchro\Transport\ReturnResult;
use WPSynchro\Utilities\DebugInformation;

/**
 * Class for handling service "masterdata"
 * Call should already be verified by permissions callback
 *
 */
class MasterData extends WPSynchroService
{
    // Column types - in how they are inserted into database
    public $string_columns_types = ["decimal", "dec", "fixed", "numeric", "json", "date", "datetime", "timestamp", "time", "year", "char", "varchar", "tinytext", "text", "mediumtext", "longtext", "enum", "set"];
    public $numeric_column_types = ["tinyint", "smallint", "mediumint", "int", "bigint", "float", "double", "real"];
    public $bit_column_types = ["bit"];
    public $binary_column_types = ["blob", "tinyblob", "mediumblob", "longblob", "binary", "varbinary", "point", "geometry", "linestring", "polygon", "multipoint", "multilinestring", "multipolygon", "geometrycollection"];
    // Tables to exclude
    public $tables_to_exclude = ["wpsynchro_sync_list", "wpsynchro_file_population_list"];

    public function service()
    {
        $result = new \stdClass();

        // Check php/mysql/wp requirements
        $commonfunctions = new CommonFunctions();
        $compat_errors = $commonfunctions->checkEnvCompatability();

        if (count($compat_errors) > 0) {
            // @codeCoverageIgnoreStart
            foreach ($compat_errors as &$error) {
                $error = __("Error from remote server:", "wpsynchro") . " " . $error;
            }
            $result->errors = $compat_errors;

            $returnresult = new ReturnResult();
            $returnresult->init();
            $returnresult->setDataObject($result);
            $returnresult->setHTTPStatus(500);
            return $returnresult->echoDataFromServiceAndExit();
            // @codeCoverageIgnoreEnd
        }

        if (isset($_REQUEST['type'])) {
            $type = $_REQUEST['type'];
        } else {
            $type = [];
        }

        /**
         *  Insert standard information on site
         */
        $result->base = $this->getBaseSiteData();

        /**
         *  Multisite data
         */
        $result->multisite = $this->getMultisiteData();

        /**
         *  Get tables in database
         */
        if (in_array('dbtables', $type)) {
            $result->dbtables = $this->getBasicDatabaseData();
        }

        /**
         *  Get detailed listing of database tables and sizes
         */
        if (in_array('dbdetails', $type)) {
            $dbdetails = $this->getDetailsDatabaseData();
            $result->dbdetails = $dbdetails->dbdetails;
            $result->tmptables_dbdetails = $dbdetails->tmptables_dbdetails;
        }

        /**
         *  Get information needed for files
         */
        if (in_array('filedetails', $type)) {
            $result->files = $this->getFileDetailsData();
        }

        /**
         *  Include debug data for log
         */
        $debuginformation = new DebugInformation();
        $result->debug = $debuginformation->getAllDebugInformation();

        // Return
        $return_result = new ReturnResult();
        $return_result->init();
        $return_result->setDataObject($result);
        return $return_result->echoDataFromServiceAndExit();
    }

    /**
     *  Get base data for site
     */
    public function getBaseSiteData()
    {
        global $wpdb;
        $commonfunctions = new CommonFunctions();

        $result = new \stdClass();

        $result->client_home_url = home_url('/');
        $result->wpdb_prefix = $wpdb->prefix;
        $result->wp_options_table = $wpdb->options;
        $result->wp_users_table = $wpdb->users;
        $result->wp_usermeta_table = $wpdb->usermeta;

        $home_url_from_db = $wpdb->get_var("select option_value from " . $wpdb->options . " where option_name='home'");
        if (!$home_url_from_db) {
            $home_url_from_db = "";
        }

        $result->home_url_db = trim($home_url_from_db, "/");
        $result->home_url_constant = trim((defined('WP_HOME') ? WP_HOME : ""), "/");

        // Get max allowed packet size from sql
        $result->max_allowed_packet_size = (int) $wpdb->get_row("SHOW VARIABLES LIKE 'max_allowed_packet'")->Value;
        // Get max post size
        $result->max_post_size = $commonfunctions->convertPHPSizeToBytes(ini_get('post_max_size'));
        if ($result->max_post_size < 1) {
            // If set to 0, which mean unlimited, we just set it to 100mb
            $result->max_post_size = 104857600;
        }
        // Get memory limit
        $result->memory_limit = $commonfunctions->convertPHPSizeToBytes(ini_get('memory_limit'));
        // If set to -1, which mean unlimited, we just set it to 512mb
        if ($result->memory_limit < 1) {
            $result->memory_limit = 536870912;
        }
        // MySQL version
        $result->sql_version = $wpdb->get_var("select VERSION()");
        // WP Synchro plugin version
        $result->plugin_version = WPSYNCHRO_VERSION . " " . (\WPSynchro\Utilities\CommonFunctions::isPremiumVersion() ? 'PRO' : 'FREE');
        // WP version
        include ABSPATH . WPINC . '/version.php';
        $result->wp_version = $wp_version;
        // Get whether the MU plugin is enabled
        $result->mu_plugin_enabled = defined('WPSYNCHRO_MU_COMPATIBILITY_VERSION');

        return $result;
    }

    /**
     *  Get data on multisite, if it is multisite, ofc...
     */
    public function getMultisiteData()
    {
        $multisite = new \stdClass();
        $is_multisite = false;
        if (is_multisite()) {
            $is_multisite = true;
            $multisite->is_multisite = true;

            // Detect the "main" site blog id
            $multisite->main_blog_id = get_network()->site_id;
            $multisite->current_blog_id = get_current_blog_id();
            $multisite->is_main_site = ($multisite->main_blog_id === $multisite->current_blog_id);

            // Add list of other blogs
            $sitelist = get_sites();
            $multisite->blogs = [];
            foreach ($sitelist as $site) {
                $blog = new \stdClass();
                $blog->id = $site->blog_id;
                $blog->domain = $site->domain;
                $blog->path = $site->path;
                $multisite->blogs[] = $blog;
            }

            // Get the first super admin, to have a user_id to assign content to
            $super_admin = get_super_admins();
            if (count($super_admin) > 0) {
                $multisite->default_super_admin_id = username_exists($super_admin[0]);
                $multisite->default_super_admin_username = $super_admin[0];
            } else {
                $multisite->default_super_admin_id = 0;
                $multisite->default_super_admin_username = "";
            }
        } else {
            $multisite->is_multisite = false;
            $multisite->is_main_site = false;
        }
        $multisite->defined_uploads_location = defined("UPLOADS") ? UPLOADS : "";

        return $multisite;
    }

    /**
     *  Get basic data on database
     */
    public function getBasicDatabaseData()
    {
        global $wpdb;

        $tables_sql = $wpdb->get_col('SHOW TABLES');

        $tables = [];
        foreach ($tables_sql as $tablename) {
            // If temp table, just skip it
            if (strpos($tablename, DatabaseSync::TMP_TABLE_PREFIX) === 0) {
                continue;
            }
            // Check if table should be excluded, such as WP Synchro tables
            if ($this->shouldTableBeExcluded($tablename)) {
                continue;
            }
            $tables[] = $tablename;
        }
        return $tables;
    }

    /**
     *  Get details on database
     */
    public function getDetailsDatabaseData()
    {
        global $wpdb;
        $result = new \stdClass();
        $tables_sql = $wpdb->get_results('SHOW TABLE STATUS');
        $tables_details = [];
        $table_tmptables_details = [];
        foreach ($tables_sql as $tb) {
            // Check if table should be excluded, such as WP Synchro tables
            if ($this->shouldTableBeExcluded($tb->Name)) {
                continue;
            }

            // Get the actual count on rows, because show table status is not precise
            $exactrows = $wpdb->get_var("SELECT COUNT(*) FROM `" . $tb->Name . "`");
            $new_table = new Table();
            $new_table->name = $tb->Name;
            $new_table->rows = intval($exactrows);
            $new_table->completed_rows = 0;
            $new_table->row_avg_bytes = $tb->Avg_row_length;
            $new_table->data_total_bytes = $tb->Data_length;

            // Fix some cases, where row_avg_bytes are reported to be 0, even if there is some rows
            if ($new_table->rows > 0 && $new_table->row_avg_bytes == 0) {
                $new_table->row_avg_bytes = 8192;
            }

            // If temp table, add to seperate array (mostly used in finalize)
            if (strpos($new_table->name, DatabaseSync::TMP_TABLE_PREFIX) === 0) {
                $table_tmptables_details[] = $new_table;
            } else {
                $tables_details[] = $new_table;
            }
        }

        // Show create table
        foreach ($tables_details as &$tb) {
            $createsql = $wpdb->get_row('show create table `' . $tb->name . '`', ARRAY_N);
            $createsql[1] = mb_convert_encoding($createsql[1], 'UTF-8', 'UTF-8');
            $tb->create_table = $createsql[1];

            // If it is a view, make sure we mark it as one and clean it up a bit
            if (preg_match('#\CREATE[^\]]+\VIEW `#', $tb->create_table)) {
                $cleaned_view_create = preg_replace('#\CREATE[^\]]+\VIEW `#', '', $tb->create_table);
                $tb->create_table = "CREATE VIEW `" . $cleaned_view_create;
                $tb->is_view = true;
            }

            // Clean up CREATE statement
            $tb->create_table = $this->cleanUpCreateStatement($tb->create_table);
        }

        // Get primary key (for faster data fetch)
        foreach ($tables_details as &$tb) {
            $primarysql_key = $wpdb->get_results('SHOW KEYS FROM `' . $tb->name . '` WHERE Key_name = "PRIMARY"', ARRAY_N);
            // Check if composite key
            if ($primarysql_key && count($primarysql_key) > 1) {
                // Primary key is composite, so we dont use it
                $tb->primary_key_column = "";
            } elseif (isset($primarysql_key[0][4])) {
                $tb->primary_key_column = $primarysql_key[0][4];
                if (!$this->isPrimaryIndexNumeric($tb->create_table, $tb->primary_key_column)) {
                    $tb->primary_key_column = "";
                }
            } else {
                $tb->primary_key_column = "";
            }
        }

        // Check for speciel columns, ex blob's
        foreach ($tables_details as &$tb) {
            $tb->column_types = $this->extractColumnsTypeFromSQLCreate($tb->create_table);
        }

        $result->dbdetails = $tables_details;
        $result->tmptables_dbdetails = $table_tmptables_details;

        return $result;
    }

    /**
     *  Clean up create statement
     */
    public function cleanUpCreateStatement($create_statement)
    {
        // Change any fields enclosed with " to backticks ` (looking at you MySQL 8...)
        $backtick_matches = [];
        preg_match_all('/\s+"(\S+)"\s/', $create_statement, $backtick_matches);
        if (isset($backtick_matches[1])) {
            foreach ($backtick_matches[1] as $match) {
                $create_statement = str_replace('"' . $match . '"', '`' . $match . '`', $create_statement);
            }
        }

        return $create_statement;
    }


    /**
     *  Get file details data
     */
    public function getFileDetailsData()
    {
        $commonfunctions = new CommonFunctions();

        $result = new \stdClass();

        // Web root
        $documentroot = untrailingslashit($commonfunctions->fixPath(realpath($_SERVER['DOCUMENT_ROOT'])));
        if (is_multisite()) {
            $homeurl = parse_url(network_site_url('/'));
        } else {
            $homeurl = parse_url(home_url('/'));
        }
        $pathcomponent = $commonfunctions->fixPath($homeurl['path']);
        $home_dir = untrailingslashit($documentroot . $pathcomponent);

        $result->files_home_dir_readwrite = static::checkReadWriteOnDir($home_dir);
        $result->files_home_dir = $home_dir;

        // One dir above webroot
        $files_above_webroot_dir = untrailingslashit(dirname($result->files_home_dir));
        $result->files_above_webroot_dir_readwrite = static::checkReadWriteOnDir($files_above_webroot_dir);
        $result->files_above_webroot_dir = $commonfunctions->fixPath($files_above_webroot_dir);

        // Absolut directory of WordPress root folder
        $result->files_wp_dir = untrailingslashit($commonfunctions->fixPath(ABSPATH));
        $result->files_wp_dir_readwrite = static::checkReadWriteOnDir($result->files_wp_dir);

        // Absolut directory of WP_CONTENT folder, or whatever it is called
        $result->files_wp_content_dir = untrailingslashit($commonfunctions->fixPath(WP_CONTENT_DIR));
        $result->files_wp_content_dir_readwrite = static::checkReadWriteOnDir($result->files_wp_content_dir);

        // Plugins dir
        $result->files_plugins_dir = untrailingslashit($commonfunctions->fixPath(WP_PLUGIN_DIR));
        $result->files_plugins_dir_readwrite = static::checkReadWriteOnDir($result->files_plugins_dir);

        // Themes dir
        $result->files_themes_dir = untrailingslashit($commonfunctions->fixPath(get_theme_root()));
        $result->files_themes_dir_readwrite = static::checkReadWriteOnDir($result->files_themes_dir);

        // Uploads dir
        $upload_dir_obj = wp_upload_dir();
        $result->files_uploads_dir = untrailingslashit($commonfunctions->fixPath($upload_dir_obj['basedir']));
        $result->files_uploads_dir_readwrite = static::checkReadWriteOnDir($result->files_uploads_dir);

        // Get plugin list
        $result->files_plugin_list = [];
        if (!function_exists('get_plugins')) {
            require_once(ABSPATH . '/wp-admin/includes/plugin.php');
        }
        $all_pluginlist = \get_plugins();
        foreach ($all_pluginlist as $pluginslug => $plugindata) {
            $tmp_arr = [];
            $tmp_arr['slug'] = $pluginslug;
            $tmp_arr['name'] = $plugindata['Name'];

            $result->files_plugin_list[] = $tmp_arr;
        }
        // Get theme list
        $result->files_theme_list = [];
        $all_themeslist = \wp_get_themes();
        foreach ($all_themeslist as $themeslug => $wp_theme) {
            $tmp_arr = [];
            $tmp_arr['slug'] = $themeslug;
            $tmp_arr['name'] = $wp_theme->get("Name");

            $result->files_theme_list[] = $tmp_arr;
        }
        return $result;
    }

    /**
     *  Check if table should be excluded
     */
    public function shouldTableBeExcluded($tablename)
    {
        $exclude_this_table = false;

        if (strpos($tablename, DatabaseSync::TMP_TABLE_PREFIX) === 0) {
            return false;
        }

        foreach ($this->tables_to_exclude as $excludedtable) {
            if (stripos($tablename, $excludedtable) !== false) {
                $exclude_this_table = true;
                break;
            }
        }
        if ($exclude_this_table) {
            return true;
        }

        // If it is multisite and mainsite, show all. If subsite, only show global users and usermeta and the subsite tables
        if (is_multisite() && get_current_blog_id() != get_network()->site_id) {
            global $wpdb;
            $currentblog_id = get_current_blog_id();
            // Get global prefix
            switch_to_blog(get_network()->site_id);
            $global_tables = [$wpdb->prefix . "users", $wpdb->prefix . "usermeta"];
            restore_current_blog();
            if (strpos($tablename, $wpdb->prefix) !== 0) {
                $exclude_this_table = true;
            }
            if (in_array($tablename, $global_tables)) {
                $exclude_this_table = false;
            }
        }

        return $exclude_this_table;
    }

    /**
     *  Function to extract the columns "super"-type
     */
    public function extractColumnsTypeFromSQLCreate($sqlcreate)
    {
        $columns = new TableColumns();

        $lines = explode("\n", $sqlcreate);
        foreach ($lines as $line) {
            if (strpos(trim($line), "`") != 0) {
                continue;
            }

            $parts = explode("`", $line);
            if (isset($parts[1]) && isset($parts[2])) {
                // Search the column type for types we know to be
                $splitted = preg_split("/[\s,(]+/", trim($parts[2]));
                $columntype = strtolower($splitted[0]);

                $found = false;
                $colname = trim($parts[1]);

                // Check if we have a generated column
                if (strpos($line, ' GENERATED ') !== false) {
                    $columns->generated[$colname] = $colname;
                }

                // Check if string type insert
                foreach ($this->string_columns_types as $search) {
                    if ($columntype == $search) {
                        $found = true;
                        $columns->string[$colname] = $colname;
                        $columns->addColumnTypeUsed($columntype);
                        break;
                    }
                }

                if ($found) {
                    continue;
                }

                // Check if numeric type insert
                foreach ($this->numeric_column_types as $search) {
                    if ($columntype == $search) {
                        $found = true;
                        $columns->numeric[$colname] = $colname;
                        $columns->addColumnTypeUsed($columntype);
                        break;
                    }
                }

                if ($found) {
                    continue;
                }

                // Check if numeric type insert
                foreach ($this->binary_column_types as $search) {
                    if ($columntype == $search) {
                        $found = true;
                        $columns->binary[$colname] = $colname;
                        $columns->addColumnTypeUsed($columntype);
                        break;
                    }
                }

                if ($found) {
                    continue;
                }

                // Check if numeric type insert
                foreach ($this->bit_column_types as $search) {
                    if ($columntype == $search) {
                        $found = true;
                        $columns->bit[$colname] = $colname;
                        $columns->addColumnTypeUsed($columntype);
                        break;
                    }
                }

                if (!$found) {
                    $columns->unknown[$colname] = $colname;
                }
            }
        }

        return $columns;
    }

    /**
     *  Function to determine if primary index is numeric
     */
    public function isPrimaryIndexNumeric($sqlcreate, $column)
    {
        if ($column == "") {
            return false;
        }

        $lines = explode("\n", $sqlcreate);
        $column = '`' . $column . '`';
        foreach ($lines as $line) {
            if (strpos($line, $column) > -1) {
                $parts = explode("`", $line);
                $col_part = trim($parts[2]);
                $col_parts = explode(" ", $col_part);

                foreach ($this->numeric_column_types as $num_col_type) {
                    if (strpos($col_parts[0], $num_col_type) > -1) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     *  Function to check if dir can be read/written to
     */
    public static function checkReadWriteOnDir($dir)
    {
        // Default error handler is required
        set_error_handler(null);
        @trigger_error('__clean_error_info');

        // Testing...
        @is_writable($dir);
        @is_readable($dir);

        // Restore previous error handler
        restore_error_handler();

        $error = error_get_last();
        return $error['message'] === '__clean_error_info';
    }
}
