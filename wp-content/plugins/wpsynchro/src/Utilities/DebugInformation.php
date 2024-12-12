<?php

namespace WPSynchro\Utilities;

use WPSynchro\Utilities\CommonFunctions;
use WPSynchro\API\MasterData;
use WPSynchro\Utilities\Configuration\PluginConfiguration;
use WPSynchro\Utilities\Licensing\Licensing;

/**
 * Class for debug information
 */
class DebugInformation
{
    private $populated = false;
    private $data_to_show = [];
    private $data_to_json_only = [];
    private function populate()
    {
        if ($this->populated) {
            return;
        }

        $commonfunctions = new CommonFunctions();
        if (CommonFunctions::isPremiumVersion()) {
            $licensing = new Licensing();
        }
        $plugin_configuration = new PluginConfiguration();

        // WP synchro
        $this->data_to_show['WP Synchro plugin version'] = WPSYNCHRO_VERSION;
        $this->data_to_show['WP Synchro db version'] = get_option('wpsynchro_dbversion');
        $this->data_to_show['WP Synchro PRO'] = (CommonFunctions::isPremiumVersion() ? 'yes' : 'no');
        if (CommonFunctions::isPremiumVersion()) {
            $this->data_to_show['WP Synchro PRO validated'] = ($licensing->verifyLicense() ? 'yes' : 'no');
            $this->data_to_show['WP Synchro license data'] = $licensing->getLicenseState();
        }
        $this->data_to_show['WP Synchro MU plugin'] = ($plugin_configuration->getMUPluginEnabledState() ? 'yes' : 'no');
        $this->data_to_show['WP Synchro MU plugin version'] = (defined("WPSYNCHRO_MU_COMPATIBILITY_VERSION") ? WPSYNCHRO_MU_COMPATIBILITY_VERSION : "N/A");

        // WP
        global $wp_version;
        $this->data_to_show['WP Version'] = $wp_version;
        $this->data_to_show['WP SAVEQUERIES used'] = ((defined('SAVEQUERIES') && SAVEQUERIES == true) ? 'yes' : 'no');
        $this->data_to_show['WP Memory limit'] = WP_MEMORY_LIMIT;
        $this->data_to_show['WP Max memory limit'] = WP_MAX_MEMORY_LIMIT;
        $this->data_to_show['WP multisite enabled'] = (is_multisite() ? 'yes' : 'no');
        $this->data_to_show['WP option upload_path'] = get_option('upload_path', "");
        $this->data_to_show['WP option upload_url_path'] = get_option('upload_url_path', "");
        $this->data_to_show['WP debug enabled'] = (defined('WP_DEBUG') && WP_DEBUG === true ? "yes" : "no");
        global $wpdb;
        $this->data_to_show['MySQL Version'] = $wpdb->get_var("SELECT VERSION()");

        // Memory limits
        $this->data_to_show['Memory limit'] = number_format($commonfunctions->convertPHPSizeToBytes(ini_get('memory_limit')), 0, ",", ".") . " bytes";
        $this->data_to_show['Memory usage'] = number_format(memory_get_usage(), 0, ",", ".") . " bytes";

        // PHP
        $this->data_to_show['PHP Version'] = PHP_VERSION;
        $this->data_to_show['PHP max_execution_time'] = intval(ini_get('max_execution_time'));
        $this->data_to_show['PHP post_max_size'] = number_format($commonfunctions->convertPHPSizeToBytes(ini_get('post_max_size')), 0, ",", ".") . " bytes";
        $this->data_to_show['PHP open_basedir'] = ini_get('open_basedir');

        // MYSQL
        $this->data_to_show['MySQL max_allowed_packet'] = number_format($wpdb->get_row("SHOW VARIABLES LIKE 'max_allowed_packet'")->Value, 0, ",", ".") . " bytes";

        // Webserver
        $this->data_to_show['Webserver Version'] = (isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : "N/A");

        // JSON ONLY
        if (!function_exists('get_plugins')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        $plugins = get_plugins();
        $this->data_to_json_only['WP Active plugins'] = [];
        foreach ($plugins as $pluginfile => $plugin) {
            if (is_plugin_active($pluginfile)) {
                $this->data_to_json_only['WP Active plugins'][] = $plugin;
            }
        }

        $this->data_to_json_only['WP MU plugins'] = get_mu_plugins();
        $this->data_to_json_only['WP dropins'] = get_dropins();
        $this->data_to_json_only['PHP Extensions'] = get_loaded_extensions();
        $masterdata_obj = new Masterdata();
        $this->data_to_json_only['Masterdata Base'] = $masterdata_obj->getBaseSiteData();
        $this->data_to_json_only['Masterdata Files'] = $masterdata_obj->getFileDetailsData();
        $this->populated = true;
    }

    public function getJSONDebugInformation()
    {
        $this->populate();
        $tmp = array_merge($this->data_to_show, $this->data_to_json_only);
        return json_encode((object) $tmp);
    }

    public function getAllDebugInformation()
    {
        $this->populate();
        return array_merge($this->data_to_show, $this->data_to_json_only);
    }
}
