<?php

/**
 * Class for providing data for usage reporting component
 */

namespace WPSynchro\Utilities\JSData;

class UsageReportingData
{
    /**
     *  Load the JS data for Usage reporting component
     */
    public function load()
    {
        $usage_reporting_localize = [
            'introtext' => __('Help us make WP Synchro even better', 'wpsynchro'),
            'text1' => __('Will you accept that we send 100% anonymized data to our server about your usage of WP Synchro.<br>We will <b>not</b> send any personal data at all, but only send which features you are using when doing a migration. The content we are sending to our server, can always be seen in the log file from the migration, for full transparency.', 'wpsynchro'),
            'text2' => __('We do this to improve the plugin in the right places and to understand what is actually being used and what is not.', 'wpsynchro'),
            'text3' => __('It can later be changed in Setup menu.', 'wpsynchro'),
            'accept' => __('I accept', 'wpsynchro'),
            'decline' => __('No thanks', 'wpsynchro'),

        ];
        wp_localize_script('wpsynchro_admin_js', 'wpsynchro_usage_reporting', $usage_reporting_localize);
    }
}
