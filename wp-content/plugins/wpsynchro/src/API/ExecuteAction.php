<?php

namespace WPSynchro\API;

use WPSynchro\Transport\ReturnResult;
use WPSynchro\Transport\Transfer;
use WPSynchro\Transport\TransferAccessKey;
use WPSynchro\Utilities\Actions\ClearCachesOnSuccess;
use WPSynchro\Utilities\Actions\ClearCurrentTransfer;
use WPSynchro\Utilities\Actions\ClearTransients;

/**
 * Class for handling service "executeaction"
 * Call should already be verified by permissions callback
 */
class ExecuteAction extends WPSynchroService
{
    public function service()
    {
        $result = new \stdClass();

        // Get transfer object, so we can get data
        $transfer = new Transfer();
        $transfer->setEncryptionKey(TransferAccessKey::getAccessKey());
        $transfer->populateFromString($this->getRequestBody());
        $data = $transfer->getDataObject();
        if (in_array("clearcaches", $data)) {
            (new ClearCachesOnSuccess())->doAction([]);
        }

        if (in_array("cleartransfertoken", $data)) {
            (new ClearCurrentTransfer())->doAction([]);
        }

        // Clear site transients, always, to prevent wrong data in transients after transfer
        (new ClearTransients())->doAction([]);

        // Return
        $returnresult = new ReturnResult();
        $returnresult->init();
        $returnresult->setDataObject($result);
        return $returnresult->echoDataFromServiceAndExit();
    }
}
