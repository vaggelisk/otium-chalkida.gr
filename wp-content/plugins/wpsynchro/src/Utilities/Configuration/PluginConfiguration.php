<?php

namespace WPSynchro\Utilities\Configuration;

use WPSynchro\Utilities\Compatibility\MUPluginHandler;

/**
 * Load configuration parameters
 */
class PluginConfiguration
{
    /**
     *  Configuration data
     */
    public $data = [
        // Timers
        "overall_time_margin" => 0.9,       // Multiply with max php execution time
        // Requests and transport
        "request_timeout_margin" => 1.5,    // Additional single request timeout in seconds
    ];

    /**
     *  Constructor
     */
    public function __construct()
    {
    }

    /**
     *  Get slow hosting setting
     */
    public function getUsageReportingSetting()
    {
        $usage_reporting_selection = get_option('wpsynchro_usage_reporting_selection', null);
        if ($usage_reporting_selection !== null) {
            $usage_reporting = $usage_reporting_selection == 'true' ? true : false;
            return $usage_reporting;
        }
        return null;
    }

    /**
     *  Set slow hosting setting
     */
    public function setUsageReportingSetting($usage_reporting_enabled)
    {
        if ($usage_reporting_enabled) {
            update_option('wpsynchro_usage_reporting_selection', 'true', false);
        } else {
            update_option('wpsynchro_usage_reporting_selection', 'false', false);
        }
    }

    /**
     *  Get basic auth setting
     */
    public function getBasicAuthSetting()
    {
        $basic_auth = get_option('wpsynchro_setup_basic_auth');
        if ($basic_auth && count($basic_auth) > 0) {
            $basic_auth_username = $basic_auth[0];
            $basic_auth_password = $basic_auth[1];
        } else {
            $basic_auth_username = '';
            $basic_auth_password = '';
        }
        return [
            'username' => $basic_auth_username,
            'password' => $basic_auth_password,
        ];
    }

    /**
     *  Set basic auth setting
     */
    public function setBasicAuthSetting($username, $password)
    {
        if (strlen($username) > 0 && strlen($password) > 0) {
            $basic_auth_arr = [$username, $password];
            update_option('wpsynchro_setup_basic_auth', $basic_auth_arr, true);
        } else {
            delete_option('wpsynchro_setup_basic_auth');
        }
    }

    /**
     *  Get slow hosting setting
     */
    public function getSlowHostingSetting()
    {
        $enable_slow_hosting_optimize = get_option('wpsynchro_slow_hosting_optimize');
        if ($enable_slow_hosting_optimize && strlen($enable_slow_hosting_optimize) > 0) {
            $enable_slow_hosting_optimize = true;
        } else {
            $enable_slow_hosting_optimize = false;
        }
        return $enable_slow_hosting_optimize;
    }

    /**
     *  Get slow hosting setting
     */
    public function setSlowHostingSetting($slow_hosting)
    {
        if ($slow_hosting) {
            update_option('wpsynchro_slow_hosting_optimize', 'yes', true);
        } else {
            delete_option('wpsynchro_slow_hosting_optimize');
        }
    }

    /**
     *  Get mu-plugin enabled
     */
    public function getMUPluginEnabledState()
    {
        $enable_muplugin = get_option('wpsynchro_muplugin_enabled');
        if ($enable_muplugin && strlen($enable_muplugin) > 0) {
            $enable_muplugin = true;
        } else {
            $enable_muplugin = false;
        }
        return $enable_muplugin;
    }

    /**
     *  Set mu-plugin enabled
     */
    public function setMUPluginEnabledState($enable_muplugin)
    {
        $muplugin_handler = new MUPluginHandler();
        $errors = [];
        if ($enable_muplugin) {
            $enable_result = $muplugin_handler->enablePlugin();
            if (is_bool($enable_result) && $enable_result == true) {
                update_option('wpsynchro_muplugin_enabled', 'yes', true);
            } else {
                $errors[] = $enable_result;
            }
        } else {
            $delete_result = $muplugin_handler->disablePlugin();
            if (is_bool($delete_result) && $delete_result == true) {
                delete_option('wpsynchro_muplugin_enabled');
            } else {
                $errors[] = $delete_result;
            }
        }
        return $errors;
    }

    /**
     *  Get allowed methods for this site
     */
    public function getAllowedMigrationMethods()
    {
        $methodsallowed = get_option('wpsynchro_allowed_methods');
        if (!$methodsallowed) {
            $methodsallowed = new \stdClass();
            $methodsallowed->pull = false;
            $methodsallowed->push = false;
        }
        return $methodsallowed;
    }

    /**
     *  Set allowed methods for this site
     */
    public function setAllowedMigrationMethods($pull_allowed, $push_allowed)
    {
        $methodsallowed = new \stdClass();
        $methodsallowed->pull = $pull_allowed;
        $methodsallowed->push = $push_allowed;
        update_option('wpsynchro_allowed_methods', $methodsallowed, true);
    }

    /**
     *  Get access key for this site
     */
    public function getAccessKey()
    {
        $accesskey = get_option('wpsynchro_accesskey');
        return $accesskey;
    }

    /**
     *  set access key for this site
     */
    public function setAccessKey($accesskey)
    {
        // Save access key
        $accesskey = sanitize_key($accesskey);
        if (strlen($accesskey) > 30) {
            update_option('wpsynchro_accesskey', $accesskey, true);
        }
    }

    /**
     *  Get request timeout margin, for additional single request timeout in seconds
     */
    public function getRequestTimeoutMargin()
    {
        static $data_loaded = null;
        if ($data_loaded == null) {
            $this->loadSlowHostingModifications();
            $data_loaded = true;
        }
        return $this->data['request_timeout_margin'];
    }

    /**
     *  Get overall time margin, to be multiplied with php execution time
     */
    public function getOverallTimeMargin()
    {
        static $data_loaded = null;
        if ($data_loaded == null) {
            $this->loadSlowHostingModifications();
            $data_loaded = true;
        }
        return $this->data['overall_time_margin'];
    }

    /**
     *  Load slow hosting modifications to data
     */
    private function loadSlowHostingModifications()
    {
        // Load configurations that change default data
        $enable_slow_hosting_optimize = get_option('wpsynchro_slow_hosting_optimize');
        if ($enable_slow_hosting_optimize && strlen($enable_slow_hosting_optimize) > 0) {
            $this->data['overall_time_margin'] = 0.7;
            $this->data['request_timeout_margin'] = 4;
        }
    }

    /**
     *  Factory method
     */
    public static function factory()
    {
        static $instance = false;
        if (!$instance) {
            $instance = new self();
        }

        return $instance;
    }
}
