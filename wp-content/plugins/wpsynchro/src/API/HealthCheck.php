<?php

namespace WPSynchro\API;

use WPSynchro\Utilities\CommonFunctions;
use WPSynchro\Masterdata\MasterdataRetrieval;
use WPSynchro\Transport\TransferToken;
use WPSynchro\Transport\TransferAccessKey;
use WPSynchro\API\MasterData;
use WPSynchro\Logger\NullLogger;
use WPSynchro\Initiate\InitiateTokenRetrieval;
use WPSynchro\Transport\BasicAuth;
use WPSynchro\Transport\Destination;
use WPSynchro\Utilities\Configuration\PluginConfiguration;
use WPSynchro\Utilities\Licensing\Licensing;
use WPSynchro\Utilities\PluginDirs;

/**
 * Class for handling service to do healthcheck
 * Call should already be verified by permissions callback
 *
 */
class HealthCheck extends WPSynchroService
{
    public $healthcheck_errors;
    private $healthcheck;

    public function __construct()
    {
        $this->healthcheck = new \stdClass();
        $this->healthcheck->errors = [];
        $this->healthcheck->warnings = [];
    }

    public function service()
    {
        // Get methods and execute the tests
        $check_methods = $this->getTestFunctions();
        foreach ($check_methods as $method) {
            $this->$method();
            if (count($this->healthcheck->errors) > 0) {
                break;
            }
        }

        // If no errors or warnings, set timestamp in database
        if (count($this->healthcheck->errors) == 0) {
            update_site_option("wpsynchro_healthcheck_timestamp", time());
        }

        echo json_encode($this->healthcheck);
        return;
    }

    /**
     *  Get functions to test
     */
    public function getTestFunctions()
    {
        // Find test functions
        $class_methods = get_class_methods($this);
        $check_methods = [];
        foreach ($class_methods as $method) {
            if (strpos($method, 'check') === 0) {
                $check_methods[] = $method;
            }
        }
        return $check_methods;
    }

    /**
     *  Check MU plugin loaded
     */
    public function checkMUPluginLoaded()
    {
        global $wpdb;
        if (!defined('WPSYNCHRO_MU_COMPATIBILITY_LOADED')) {
            // It is NOT loaded. Check if it should be
            $plugin_configuration = new PluginConfiguration();
            $should_mu_plugin_loaded = $plugin_configuration->getMUPluginEnabledState();
            if ($should_mu_plugin_loaded) {
                // It is enabled, but not loaded. Bad!
                $this->healthcheck->errors[] = __("WP Synchro MU-plugin is enabled in Setup, but is not loading. That can cause problems and bad performance in migrations. Try to disable it and re-enable it in WP Synchro > Setup menu and see if this error persist.", "wpsynchro");
            } else {
                $this->healthcheck->warnings[] = __("WP Synchro MU-plugin is not currently loaded - You should really consider enabling it in WP Synchro > Setup menu, as it boosts performance and cause much less problems during migrations.", "wpsynchro");
            }
        }
    }

    /**
     *  Check table prefix
     */
    public function checkDatabaseTablePrefix()
    {
        global $wpdb;
        if (strlen($wpdb->prefix) == 0) {
            $this->healthcheck->errors[] = __("Empty database table prefix is not supported by WP Synchro (or by WordPress in newer versions) - To fix this, you must set a table prefix", "wpsynchro");
        }
    }

    /**
     *  Check environment, WP/PHP/SQL
     */
    public function checkEnvironment()
    {
        $commonfunctions = new CommonFunctions();
        $errors_from_env = $commonfunctions->checkEnvCompatability();
        if (count($errors_from_env) > 0) {
            $this->healthcheck->errors = array_merge($this->healthcheck->errors, $errors_from_env);
        }
    }

    /**
     *  Check that database is current, but not newer
     */
    public function checkDatabaseIsCurrent()
    {
        $dbversion = get_option('wpsynchro_dbversion');
        if (!$dbversion || $dbversion == "") {
            $dbversion = 0;
        }
        if ($dbversion > WPSYNCHRO_DB_VERSION) {
            $this->healthcheck->errors[] = __("WP Synchro database version is newer than the currently installed plugin version - Please upgrade plugin to newest version - Continue at own risk", "wpsynchro");
        }
    }

    /**
     *  Check that local migration has access key set
     */
    public function checkAccessKeyIsSet()
    {
        $accesskey = TransferAccessKey::getAccessKey();
        if (strlen(trim($accesskey)) < 20) {
            $this->healthcheck->errors[] = __("Access key for this site is not set - This needs to be configured for WP Synchro to work.", "wpsynchro");
        }
    }

    /**
     *  Check proper PHP extensions
     */
    public function checkPHPExtensions()
    {
        $required_php_extensions = ["curl", "mbstring", "openssl", "mysqli"];
        $php_extensions_loaded = get_loaded_extensions();
        $missing_extensions = [];
        foreach ($required_php_extensions as $required_php_extension) {
            if (!in_array($required_php_extension, $php_extensions_loaded)) {
                $missing_extensions[] = $required_php_extension;
            }
        }
        if (count($missing_extensions) > 0) {
            // translators: %s is replaced with comma separated list of PHP extensions
            $this->healthcheck->errors[] = sprintf(__("Missing PHP extensions for WP Synchro to work. Enable extension(s) '%s' to php.ini and reload.", "wpsynchro"), implode(", ", $missing_extensions));
        }
    }

    /**
     * Check that sql max_allowed_packet is set to something proper
     */
    public function checkSQLMaxAllowPacket()
    {
        global $wpdb;
        $max_allowed_packet = (int) $wpdb->get_row("SHOW VARIABLES LIKE 'max_allowed_packet'")->Value;
        if ($max_allowed_packet < 1024) {
            $this->healthcheck->errors[] = sprintf(
                // translators: %d is replaced with number
                __("Your database server is misconfigured - The setting 'max_allowed_packet' is too low. It is currently set to: %d. Check out the documentation for the SQL server you are using and correct this setting.", "wpsynchro"),
                $max_allowed_packet
            );
        }
    }

    /**
     *  Check that SAVEQUERIES are not active
     */
    public function checkSaveQueries()
    {
        if (defined("SAVEQUERIES") && SAVEQUERIES == true) {
            $this->healthcheck->errors[] = __("SAVEQUERIES constant is set. This is normally only for debugging. It will generate out of memory errors with WP Synchro migrations", "wpsynchro");
        }
    }

    /**
     *  Check license okay, if PRO
     */
    public function checkLicenseIfPRO()
    {
        if (CommonFunctions::isPremiumVersion()) {
            $licensing = new Licensing();
            if ($licensing->hasProblemWithLicensing()) {
                $this->healthcheck->errors[] = $licensing->getLicenseErrorMessage();
            }
        }
    }

    /**
     *  Check that multiple connections to local services can be done - LocalWP problems most of time or misconfigured hosting
     */
    public function checkMultipleConnections()
    {
        $multiple_connection_test_url = trailingslashit(get_home_url()) . '?action=wpsynchro_test';

        $args = [
            'method' => 'GET',
            'redirection' => 0,
            'timeout' => 5,
            'sslverify' => false,
            'headers' => [],
        ];

        // Check for basic auth setup
        $destination = new Destination(Destination::LOCAL);
        $destination_basic_auth = $destination->getBasicAuthentication();
        if ($destination_basic_auth !== false) {
            $args["headers"]["Authorization"] = "Basic " . base64_encode($destination_basic_auth[0] . ":" . $destination_basic_auth[1]);
        }

        $tests_per_http_type = 5;
        $error_runs = [];
        $expected_result_from_service = 'it-works';

        for ($i = 0; $i < $tests_per_http_type; $i++) {
            $response = wp_remote_get($multiple_connection_test_url, $args);
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code === 200) {
                // Check correct body
                $body = wp_remote_retrieve_body($response);
                if ($body != $expected_result_from_service) {
                    $error_runs[$i] = $response;
                }
            } else {
                $error_runs[$i] = $response;
            }
        }
        if (count($error_runs) > 0) {
            $this->healthcheck->errors[] = sprintf(
                // translators: 1%d is replaced with simple number, 2%s is replaced with HTTP return code, like 200, 3%d er replaced by simple number
                __("Service test error - Tried making %d consecutive requests (with HTTP %s) to a test service on this site - %d of them failed.", "wpsynchro"),
                $tests_per_http_type,
                'GET',
                count($error_runs)
            );

            // Get basic auth class, to check if we are hitting basic auth
            $basic_auth = new BasicAuth();
            $atleast_one_used_basic_auth = false;
            $problem_found = false;

            foreach ($error_runs as $error_run_num => $response) {
                if (is_wp_error($response)) {
                    $this->healthcheck->errors[] = sprintf(__("Error from request (number %d):", "wpsynchro"), $error_run_num + 1) . " " . $response->get_error_message();
                } else {
                    $body = wp_remote_retrieve_body($response);
                    // Check for authentication on remote
                    if ($basic_auth->checkResponseHeaderForBasicAuth($response)) {
                        $atleast_one_used_basic_auth = true;
                        $this->healthcheck->errors[] = __("This site is protected by Basic Authentication, which requires a username and password.
                        You can add the correct username/password in the 'Setup' menu.", "wpsynchro");
                        $problem_found = true;
                        break;
                    } elseif (preg_match('/\s/', substr($body, 0, 1)) || preg_match('/\s/', substr($body, -1, 1))) {
                        // Check first if the first or last character is a space, as this would indicate that something is echoing stuff it should not
                        $this->healthcheck->errors[] = __('Got spaces in the response from API, either before or after the expected content. This is an indication that there is a problem somewhere in your code. Can sometimes be fixed by reinstalling WordPress files. Otherwise looks for spaces after closing PHP tags. This can cause problems for WP Synchro and other plugins also, so you should get that fixed.', "wpsynchro");
                        $problem_found = true;
                        break;
                    } elseif (strlen($expected_result_from_service) !=  strlen($body)) {
                        $this->healthcheck->errors[] = sprintf(__("The response length is different from expected length. This is often because of invalid characters before or after the expected response. This often comes from errors in the code other places on the site - Expected '%s' - Got: '%s'", "wpsynchro"), $expected_result_from_service, $body);
                        $problem_found = true;
                        break;
                    } elseif ($body != $expected_result_from_service) {
                        // Check if the body contain what we expect
                        $this->healthcheck->errors[] = sprintf(__("Error from request (number %d) - Got wrong data in response from webservice - Expected '%s' - Got: '%s' - This means that somewhere in the code, extra characters are being sent, most likely as an error. Look for characters or spaces after closing PHP tags.", "wpsynchro"), $error_run_num + 1, $expected_result_from_service, $body);
                    }
                }
            }
            if ($atleast_one_used_basic_auth === false && $problem_found == false) {
                $this->healthcheck->errors[] = $problem_found;
                // Catch LocalWP bug
                if (isset($error_runs[1]) && isset($error_runs[3]) && count($error_runs) === 2) {
                    $this->healthcheck->errors[] = __("The pattern of errors suggest you are using LocalWP as development environment. It contains a bug where 50% of remote requests fail, when called from the code. That is why request 2 and 4 fails, but 1,3 and 5 succeed. Read more about it in our documentation.", "wpsynchro");
                } else {
                    $this->healthcheck->errors[] = __("This issue is most likely caused by a misconfiguration of the hosting environment. Most often because of too few available worker processes. See more documentation on this in our documentation.", "wpsynchro");
                }
            }
        }
    }

    /**
     *  Check local service urls for connectivity and proper response
     */
    public function checkInitiateAndMastedata()
    {
        $initiate_token = "";

        $initiate_server_okay = false;

        $logger = new NullLogger();
        $destination = new Destination(Destination::LOCAL);
        $retrieval = new InitiateTokenRetrieval($logger, $destination, "local");
        $result = $retrieval->getInitiateToken();

        if ($result && isset($retrieval->token) && strlen($retrieval->token) > 0) {
            $initiate_token = $retrieval->token;
            $initiate_server_okay = true;
        } else {
            $this->healthcheck->errors = array_merge($this->healthcheck->errors, $retrieval->getErrors());
            $this->healthcheck->warnings = array_merge($this->healthcheck->warnings, $retrieval->getWarnings());
            $this->healthcheck->errors[] = __("Service error - Can not reach 'initiate' service - Check that services is accessible and not being blocked", "wpsynchro");
        }

        if ($initiate_server_okay) {
            // Create a transfer token based on the token we just got
            $transfer_token = TransferToken::getTransferToken(TransferAccessKey::getAccessKey(), $initiate_token);

            // Get masterdata retrival object
            $retrieval = new MasterdataRetrieval($destination);
            $retrieval->setDataToRetrieve(['dbtables', 'filedetails']);
            $retrieval->setToken($transfer_token);
            $retrieval->setEncryptionKey(TransferAccessKey::getAccessKey());
            $result = $retrieval->getMasterdata();

            // Check for errors
            if ($result) {
                if (!$retrieval->data->dbtables) {
                    $this->healthcheck->errors[] = __("Service error - Masterdata service returns improper response - Data was not returned in usable way - Check PHP error log", "wpsynchro");
                }
            } else {
                $this->healthcheck->errors[] = __("Service error - Can not reach 'masterdata' service - Check that WP Synchro is activated and service accessible", "wpsynchro");
            }
        }
    }

    /**
     *  Check writable log directory
     */
    public function checkWritableLogDir()
    {
        $plugins_dirs = new PluginDirs();
        $log_location = $plugins_dirs->getUploadsFilePath();
        $log_dir = realpath($log_location);
        if (!is_writable($log_dir)) {
            $this->healthcheck->errors[] = sprintf(__("WP Synchro log dir is not writable for PHP - Path: %s ", "wpsynchro"), $log_dir);
        }
    }

    /**
     *  Check other relevant dir for writability (typically for files sync)
     */
    public function checkRelevantDirsForWritable()
    {
        if (!\WPSynchro\Utilities\CommonFunctions::isPremiumVersion()) {
            return;
        }
        $paths_check = [
            // Document root
            $_SERVER['DOCUMENT_ROOT'],
            // Absolut directory of WP_CONTENT folder, or whatever it is called
            WP_CONTENT_DIR,
            // One dir above webroot
            dirname(realpath($_SERVER['DOCUMENT_ROOT']))
        ];
        foreach ($paths_check as $path) {
            if (!MasterData::checkReadWriteOnDir($path)) {
                $this->healthcheck->warnings[] = sprintf(__("Path that WP Synchro might use for migration is not writable- Path: %s -  This can be caused by PHP's open_basedir setting or file permissions", "wpsynchro"), $path);
            }
        }
    }
}
