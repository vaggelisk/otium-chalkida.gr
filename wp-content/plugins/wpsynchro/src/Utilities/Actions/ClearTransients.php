<?php

/**
 * Action: Clear all transients
 */

namespace WPSynchro\Utilities\Actions;

use WPSynchro\Utilities\Actions\Action;

class ClearTransients implements Action
{
    /**
     * Initialize
     */
    public function init()
    {
    }

    /**
     * Execute action
     */
    public function doAction($params)
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE ('%\_transient\_%')");
    }
}
