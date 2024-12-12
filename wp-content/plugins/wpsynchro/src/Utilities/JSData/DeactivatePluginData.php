<?php

/**
 * Class for providing data for deactivate modal
 */

namespace WPSynchro\Utilities\JSData;

use WPSynchro\Utilities\CommonFunctions;

class DeactivatePluginData
{
    /**
     *  Load the JS data for page headers
     */
    public function load()
    {
        global $wp_version;
        $jsdata = [
            'wp_version' => $wp_version,
            'wp_synchro_version' => WPSYNCHRO_VERSION,
            'wp_synchro_version_type' => CommonFunctions::isPremiumVersion() ? 'PRO' : 'FREE',
            'wp_language' => get_bloginfo("language"),
            'get_questions_url' => 'https://daev.tech/api/wpsynchro/v1/deactivate-feedback-questions',
            'post_feedback_url' => 'https://daev.tech/api/wpsynchro/v1/deactivate-feedback',
        ];

        wp_localize_script('wpsynchro_deactivate_js', 'wpsynchro_deactivation', $jsdata);
    }
}
