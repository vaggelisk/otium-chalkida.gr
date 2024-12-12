<?php

/**
 * Class for providing data for page headers
 */

namespace WPSynchro\Utilities\JSData;

use WPSynchro\Utilities\CommonFunctions;

class PageHeaderData
{
    /**
     *  Load the JS data for page headers
     */
    public function load($script_alias = 'wpsynchro_admin_js')
    {
        $commonfunctions = new CommonFunctions();

        $jsdata = [
            "isPro" => $commonfunctions::isPremiumVersion(),
            "pageTitleImg" => $commonfunctions->getAssetUrl("icon.png"),
            "version" => WPSYNCHRO_VERSION,
        ];
        wp_localize_script($script_alias, 'wpsynchro_page_header', $jsdata);
    }
}
