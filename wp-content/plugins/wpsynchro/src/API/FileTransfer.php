<?php

namespace WPSynchro\API;

use WPSynchro\Files\TransportHandler;
use WPSynchro\Transport\ReturnResult;
use WPSynchro\Transport\Transfer;
use WPSynchro\Transport\TransferAccessKey;
use WPSynchro\Utilities\SyncTimerList;

/**
 * Class for handling service "filetransfer" - Receiving files
 */
class FileTransfer extends WPSynchroService
{
    public function service()
    {
        // init
        $timer = SyncTimerList::getInstance();
        $timer->init();

        // Get transfer object, so we can get data
        $transfer = new Transfer();
        $transfer->setEncryptionKey(TransferAccessKey::getAccessKey());
        $transfer->populateFromString($this->getRequestBody());
        $data = $transfer->getDataObject();
        $files = $transfer->getFiles();

        // Handle the files and filedata, writing it to disk as needed
        $transporthandler = new TransportHandler();
        $result = $transporthandler->handleFileTransport($data, $files);

        // Return the result
        $returnresult = new ReturnResult();
        $returnresult->init();
        $returnresult->setDataObject($result);
        return $returnresult->echoDataFromServiceAndExit();
    }
}
