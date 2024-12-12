<?php

namespace WPSynchro\Transport;

use WPSynchro\Migration\MigrationController;

/**
 * Class for handling result of transport
 */
class RemoteTransportResult
{
    public $url;
    public $args;
    public $encryption_key;
    // Data
    public $response_object;
    public $body;
    public $files;
    public $body_length;
    // HTTP
    public $headers = [];
    public $statuscode;
    // Errors, warnings etc
    public $errors = [];
    public $warnings = [];
    public $infos = [];
    public $debugs = [];
    // Success or not
    public $success = false;
    // Dependencies
    private $logger;

    /**
     *  Constructor
     */
    public function __construct()
    {
        $this->logger = MigrationController::getInstance()->getLogger();
    }

    public function getBody()
    {
        return $this->body;
    }

    public function getFiles()
    {
        return $this->files;
    }

    public function getBodyLength()
    {
        return $this->body_length;
    }

    public function getStatuscode()
    {
        return $this->statuscode;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function getWarnings()
    {
        return $this->warnings;
    }

    public function getInfos()
    {
        return $this->infos;
    }

    public function getDebugs()
    {
        return $this->debugs;
    }

    public function isSuccess()
    {
        return $this->success;
    }

    public function getResponseObject()
    {
        return $this->response_object;
    }

    public function getHeader($header)
    {
        return $this->headers[$header] ?? false;
    }

    public function writeMessagesToLog()
    {
        foreach ($this->errors as $errortext) {
            $this->logger->log("ERROR", $errortext);
        }
        $this->errors = [];

        foreach ($this->warnings as $warningtext) {
            $this->logger->log("WARNING", $warningtext);
        }
        $this->warnings = [];

        foreach ($this->infos as $infolog) {
            $this->logger->log("INFO", $infolog);
        }
        $this->infos = [];

        foreach ($this->debugs as $debuglog) {
            $this->logger->log("DEBUG", $debuglog);
        }
        $this->debugs = [];
    }

    public function parseResponse(&$response, $url, $args, $encryption_key)
    {
        $this->url = $url;
        $this->args = $args;
        $this->encryption_key = $encryption_key;
        $this->response_object = $response;

        // Check if WP error
        if (is_wp_error($this->response_object)) {
            $errormsg = $this->response_object->get_error_message();
            if (strpos($errormsg, "cURL error 60") > -1) {
                $this->errors[] = __("Remote or local SSL certificate is not valid or self-signed. To allow non-valid SSL certificates, you need to edit the migration and change it.", "wpsynchro");
                $this->debugs[] = "Remote or local SSL certificate is not valid or self-signed.";
                $this->debugs[] = print_r($this->response_object, true);
            } else {
                $this->debugs[] = "Remote service '" . $this->url . "' failed with WP error: " . $errormsg;
                $this->debugs[] = print_r($this->response_object, true);
            }
        } else {
            // Check statuscode
            $this->statuscode = wp_remote_retrieve_response_code($this->response_object);
            $this->headers = wp_remote_retrieve_headers($this->response_object);

            // check if wpsynchrotransfer or json
            $body_data = wp_remote_retrieve_body($this->response_object);

            // Check if there is any PHP notices/warnings etc found in response
            $php_notices_index = strpos($body_data, "<b>Notice</b>:");
            $php_warning_index = strpos($body_data, "<b>Warning</b>:");
            $php_fatal_error_index = strpos($body_data, "<b>Fatal error</b>:");
            if ($php_notices_index !== false || $php_warning_index !== false || $php_fatal_error_index !== false) {
                $sitename = $url = strtok($this->url, '?');
                $error_msg = "";
                $error_index = 0;
                if ($php_notices_index !== false) {
                    $error_index = $php_notices_index;
                } elseif ($php_warning_index !== false) {
                    $error_index = $php_warning_index;
                } elseif ($php_fatal_error_index !== false) {
                    $error_index = $php_fatal_error_index;
                }
                $this->errors[] = sprintf("Found one or more PHP notices/warnings/errors in response on url: %s. These must be fixed or suppressed before WP Synchro can complete migration. The errors should also be in PHP error log on the site. Also make sure WP_DEBUG is set to false in wp-config.php.", $sitename);
                $this->errors[] = substr($body_data, $error_index, 500);
            }

            // Check for authentication on remote
            $www_authenticate_header = wp_remote_retrieve_header($this->response_object, "WWW-Authenticate");
            if (strlen($www_authenticate_header) > 0 && $this->statuscode == 401) {
                // Host is using autentication in some form
                $parsed_url = parse_url($url);
                if (strpos($www_authenticate_header, "Basic realm") !== false) {
                    // Using Basic authentication
                    $this->errors[] = sprintf(__("The site %s is protected by Basic Authentication, which requires a username and password.
                    If this is the remote site, the username/password should be added to the migration configuration.
                    If the site is the 'local' site, you can add the username/password in the 'Setup' menu.", "wpsynchro"), $parsed_url['host']);
                } else {
                    $this->errors[] = sprintf(__("The site %s is protected by authentication of some kind, that we do not support.
                    Contact support for further help and tell them this: Auth header was: %s", "wpsynchro"), $parsed_url['host'], $www_authenticate_header);
                }
            }

            $this->body_length = strlen($body_data);

            if (strpos($body_data, "WPSYNCHROTRANSFER") !== false) {
                // Remove any junk output before "WPSYNCHROTRANSFER", which can be wpdb error or the likes
                if (strpos($body_data, "WPSYNCHROTRANSFER") !== 0) {
                    $body_data = substr($body_data, strpos($body_data, "WPSYNCHROTRANSFER"));
                }
                $transfer = new Transfer();
                $transfer->setEncryptionKey($this->encryption_key);
                $transfer->populateFromString($body_data);
                $this->body = $transfer->getDataObject();
                $this->files = $transfer->getFiles();
            } else {
                // JSON
                $body_json = $this->cleanRemoteJSONData($body_data);
                $this->body = json_decode($body_json);
            }

            if ($this->statuscode == 200) {
                $this->success = true;
            } else {
                $this->debugs[] = "Error calling service - Got HTTP " . $this->statuscode . " on this url: " . $this->url;
                $this->debugs[] = htmlentities(substr(print_r($this->response_object, true), 0, 5000));
            }

            // Check for errors
            if (isset($this->body->errors)) {
                $this->errors = array_merge($this->errors, $this->body->errors);
                unset($this->body->errors);
            }

            // Check for warnings
            if (isset($this->body->warnings)) {
                $this->warnings = array_merge($this->warnings, $this->body->warnings);
                unset($this->body->warnings);
            }

            // Check for infos
            if (isset($this->body->infos)) {
                $this->infos = array_merge($this->infos, $this->body->infos);
                unset($this->body->infos);
            }

            // Check for debugs
            if (isset($this->body->debugs)) {
                $this->debugs = array_merge($this->debugs, $this->body->debugs);
                unset($this->body->debugs);
            }
        }
    }

    /**
     *  Cleanup response body data from posts/gets. Such as remove UTF8 which json_decode pukes over
     */
    public function cleanRemoteJSONData($response)
    {
        // Remove UTF8 BOM which json_decode does not like
        if (substr($response, 0, 3) == pack("CCC", 0xEF, 0xBB, 0xBF)) {
            $response = substr($response, 3);
        }
        return $response;
    }
}
