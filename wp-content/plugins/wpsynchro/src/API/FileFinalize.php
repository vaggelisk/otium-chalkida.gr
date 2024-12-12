<?php

namespace WPSynchro\API;

use WPSynchro\Transport\TransferAccessKey;
use WPSynchro\Files\FileHelperFunctions;
use WPSynchro\Transport\ReturnResult;
use WPSynchro\Transport\Transfer;
use WPSynchro\Utilities\SyncTimerList;

/**
 * Class for handling service "FileFinalize"
 * Call should already be verified by permissions callback
 *
 */
class FileFinalize extends WPSynchroService
{
    public function service()
    {
        // Init timer
        $timer = SyncTimerList::getInstance();
        $timer->init();
        // Transfer object
        $transfer = new Transfer();
        $transfer->setEncryptionKey(TransferAccessKey::getAccessKey());
        $transfer->populateFromString($this->getRequestBody());
        $body = $transfer->getDataObject();
        // Extract parameters
        $delete = $body->delete;
        $allotted_time = $body->allotted_time;
        $timer->addOtherSyncTimeLimit($allotted_time);
        $result = new \stdClass();
        $result->success = false;
        $result->errors = [];
        $result->warnings = [];
        $result->debugs = [];
        $result->debugs[] = "Finalize service: Start finalize with max time: " . $timer->getRemainingSyncTime();
        // remove the old dirs/files
        foreach ($delete as $key => &$deletepath) {
            $filepath = $deletepath->target_file;
            if (!file_exists($filepath) || !is_writable($filepath)) {
                $result->debugs[] = "Finalize service: Could not find/change file/dir that is on delete array, so ignoring the file: " . $filepath;
                $deletepath->deleted = true;
                continue;
            }

            $deleted = false;
            if (is_file($filepath)) {
                unlink($filepath);
                $deleted = true;
            } else {
                $delete_result = FileHelperFunctions::removeDirectory($filepath, $timer);
                $result->debugs[] = "Finalize service: Starting deleting: " . $filepath;
                if ($delete_result === false) {
                // Delete did not complete within timeframe
                    $result->debugs[] = "Finalize service: Could not complete delete within max time for: " . $filepath;
                } else {
                    $deleted = true;
                }
            }
            if ($deleted) {
                $result->debugs[] = "Finalize service:  Deleted " . $filepath;
                $deletepath->deleted = true;
            }

            if (!$timer->shouldContinueWithLastrunTime(3)) {
                $result->debugs[] = "Finalize service: File/dir needs to abort due to max execution time";
                break;
            }
        }

        // When all is deleted, we have completed
        $result->debugs[] = "Finalize service: File/dir deleted completed";
        $result->delete = $delete;
        $returnresult = new ReturnResult();
        $returnresult->init();
        $returnresult->setDataObject($result);
        return $returnresult->echoDataFromServiceAndExit();
    }
}
