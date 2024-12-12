<?php

namespace WPSynchro\Utilities;

/**
 * Class for common functions
 */
class CommonFunctions
{
    /**
     * Get log filename
     */
    public function getLogFilename($job_id)
    {
        return "runsync_" . $job_id . ".txt";
    }

    /**
     * Get cron log filename
     */
    public function getCronLogFilename()
    {
        return "cron_" . date('d_m_Y') . ".txt";
    }

    /**
     * Verify php/mysql/wp compatability
     */
    public function checkEnvCompatability()
    {
        $errors = [];

        // Check PHP version
        $required_php_version = "7.2";
        if (version_compare(PHP_VERSION, $required_php_version, '<')) {
            // @codeCoverageIgnoreStart
            $errors[] = sprintf(__("WP Synchro requires PHP version %s or higher - Please update your PHP", "wpsynchro"), $required_php_version);
            // @codeCoverageIgnoreEnd
        }

        // Check MySQL version
        global $wpdb;
        $required_mysql_version = "5.7";
        $mysqlversion = $wpdb->get_var("SELECT VERSION()");
        if (version_compare($mysqlversion, $required_mysql_version, '<')) {
            // @codeCoverageIgnoreStart
            $errors[] = sprintf(__("WP Synchro requires MySQL version %s or higher - Please update your MySQL", "wpsynchro"), $required_mysql_version);
            // @codeCoverageIgnoreEnd
        }

        // Check WP version
        global $wp_version;
        $required_wp_version = "5.8";
        if (version_compare($wp_version, $required_wp_version, '<')) {
            // @codeCoverageIgnoreStart
            $errors[] = sprintf(__("WP Synchro requires WordPress version %s or higher - Please update your WordPress", "wpsynchro"), $required_wp_version);
            // @codeCoverageIgnoreEnd
        }

        return $errors;
    }

    /**
     *  Converts a php.ini settings like 500M to convert to bytes
     */
    public function convertPHPSizeToBytes($sSize)
    {

        $sSuffix = strtoupper(substr($sSize, -1));
        if (!in_array($sSuffix, ['P', 'T', 'G', 'M', 'K'])) {
            return (float) $sSize;
        }
        $iValue = substr($sSize, 0, -1);
        switch ($sSuffix) {
            case 'P':
                $iValue *= 1024;
                // Fallthrough intended
            case 'T':
                $iValue *= 1024;
                // Fallthrough intended
            case 'G':
                $iValue *= 1024;
                // Fallthrough intended
            case 'M':
                $iValue *= 1024;
                // Fallthrough intended
            case 'K':
                $iValue *= 1024;
                break;
        }
        return (float) $iValue;
    }

    /**
     *  Path fix with convert to forward slash
     */
    public function fixPath($path)
    {
        $path = str_replace("/\\", "/", $path);
        $path = str_replace("\\/", "/", $path);
        $path = str_replace("\\\\", "/", $path);
        $path = str_replace("\\", "/", $path);
        return $path;
    }

    /**
     *  Get asset full url
     */
    public function getAssetUrl($asset)
    {
        static $manifest = null;
        if ($manifest === null) {
            $manifest = json_decode(file_get_contents(WPSYNCHRO_PLUGIN_DIR . 'dist/manifest.json'));
        }

        if (isset($manifest->$asset)) {
            return untrailingslashit(WPSYNCHRO_PLUGIN_URL) . $manifest->$asset;
        } else {
            return "";
        }
    }

    /**
     *  Get and output template file
     */
    public function getTemplateFile($template_filename)
    {
        ob_start();
        include(WPSYNCHRO_PLUGIN_DIR . "/src/Templates/" . $template_filename . ".php");
        $output = ob_get_contents();
        ob_end_clean();
        return $output;
    }

    /**
     *  Get PHP max_execution_time
     */
    public function getPHPMaxExecutionTime()
    {
        $max_execution_time = intval(ini_get('max_execution_time'));
        if ($max_execution_time > 30) {
            $max_execution_time = 30;
        }
        if ($max_execution_time < 1) {
            $max_execution_time = 30;
        }
        return $max_execution_time;
    }

    /**
     *   Check if premium version
     */
    public static function isPremiumVersion()
    {
        static $is_premium = null;

        if ($is_premium === null) {
            // Check if premium version
            if (file_exists(WPSYNCHRO_PLUGIN_DIR . '/.premium')) {
                $is_premium = true;
            } else {
                $is_premium = false;
            }
        }

        return $is_premium;
    }

    /**
     * Update last running timestamp in db (to prevent multiple migrations running at the same time)
     */
    public function updateLastRunning(): void
    {
        update_option('wpsynchro_migration_last_run_timestamp', time(), false);
    }

    /**
     * Check if it is safe to start a new migration
     */
    public function isSafeToStartNewMigration(): bool
    {
        $last_running_timestamp = get_option('wpsynchro_migration_last_run_timestamp');
        if ($last_running_timestamp === false | empty($last_running_timestamp)) {
            return true;
        }
        $last_running_timestamp = intval($last_running_timestamp);

        // If no jobs have been run
        if ($last_running_timestamp == 0) {
            return true;
        }

        // If the last run was more than 35 seconds ago, as normal time limit is 30 seconds
        if (($last_running_timestamp + 35) < time()) {
            return true;
        }
        return false;
    }
}
