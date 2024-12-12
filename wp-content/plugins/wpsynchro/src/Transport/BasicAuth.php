<?php

namespace WPSynchro\Transport;

/**
 * Class for basic auth stuff
 *
 */
class BasicAuth
{
    /**
     * Check header for signs of basic auth
     */
    public function checkResponseHeaderForBasicAuth($response)
    {
        // Check for authentication on remote
        $www_authenticate_header = wp_remote_retrieve_header($response, "WWW-Authenticate");
        if (strlen($www_authenticate_header) > 0 && wp_remote_retrieve_response_code($response) == 401) {
            // Host is using autentication in some form
            if (strpos($www_authenticate_header, "Basic realm") !== false) {
                // Using Basic authentication
                return true;
            }
        }
        return false;
    }
}
