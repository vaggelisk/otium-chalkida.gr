<?php

/**
 * Class for providing data for Health check JS
 */

namespace WPSynchro\Utilities\JSData;

use WPSynchro\Utilities\CommonFunctions;

class HealthCheckData
{
    /**
     *  Load the JS data for Health Check Vue component
     */
    public function load()
    {
        $healthcheck_localize = [
            'healthcheck_url' => trailingslashit(get_home_url()) . '?action=wpsynchro_frontend_healthcheck',
            'introtext' => __('Health check for WP Synchro', 'wpsynchro'),
            'helptitle' => __('Check if this site will work with WP Synchro. It checks service access, php extensions, hosting setup and more.', 'wpsynchro'),
            'basic_check_desc' => __('Performing basic health check', 'wpsynchro'),
            'errorsfound' => __('Errors found', 'wpsynchro'),
            'warningsfound' => __('Warnings found', 'wpsynchro'),
            'rerunhelp' => __("Tip: These tests can be rerun in 'Support' menu.", 'wpsynchro'),
            'errorunknown' => __('Critical - Request to local WP Synchro health check service could not be sent or did not get no response.', 'wpsynchro'),
            'error_response_could_not_parse' => __('Critical - Could not parse the JSON response gotten from the healthcheck service. Should have returned valid JSON, but it did not and we were unable to fix it. This is very likely to cause problem with any attempts to use WP Synchro and many other plugins for that matter.', 'wpsynchro'),
            'errornoresponse' => __('Critical - Request to local WP Synchro health check service did not get a response at all.', 'wpsynchro'),
            'errorwithstatuscode' => __('Critical - Request to service did not respond properly - HTTP {0} - Maybe service is blocked or returns invalid content. Response JSON:', 'wpsynchro'),
            'errorwithoutstatuscode' => __('Critical - Request to service did not respond properly - Maybe service is blocked or returns invalid content. Response JSON:', 'wpsynchro'),
        ];
        wp_localize_script('wpsynchro_admin_js', 'wpsynchro_healthcheck', $healthcheck_localize);
    }
}
