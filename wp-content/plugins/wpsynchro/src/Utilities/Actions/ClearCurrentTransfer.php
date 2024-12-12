<?php

namespace WPSynchro\Utilities\Actions;

use WPSynchro\Utilities\Actions\Action;
use WPSynchro\Transport\TransferToken;

/**
 * Action: Clear current transfer - To block further requests
 */
class ClearCurrentTransfer implements Action
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
        TransferToken::deleteTransferToken();
    }
}
