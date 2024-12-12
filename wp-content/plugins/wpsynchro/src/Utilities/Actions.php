<?php

namespace WPSynchro\Utilities;

use WPSynchro\Utilities\Actions\EmailOnSyncSuccess;
use WPSynchro\Utilities\Actions\EmailOnSyncFailure;
use WPSynchro\Utilities\Actions\ClearCachesOnSuccess;

/**
 * Class for handling actions for WP Synchro
 */
class Actions
{
    /**
     *  Load all the actions
     */
    public function loadActions()
    {

        // Load internal actions
        $this->loadInternalWPSynchroActions();
    }

    /**
     *  Load all the internal actions used by WP Synchro
     */
    private function loadInternalWPSynchroActions()
    {

        // Load success email action
        (new EmailOnSyncSuccess())->init();

        // Load failure email action
        (new EmailOnSyncFailure())->init();
    }
}
