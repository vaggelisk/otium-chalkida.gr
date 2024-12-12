<?php

namespace WPSynchro\Transport;

use WPSynchro\Transport\TransferAccessKey;

/**
 * Class to return data from service (wrapper for Transfer object when returning with data)
 *
 */
class ReturnResult
{
    public $httpstatus = 200;
    public $transfer;
    public function init()
    {
        $this->transfer = new Transfer();
        $this->transfer->setShouldEncrypt(true);
        $this->transfer->setShouldDeflate(true);
        $this->transfer->setEncryptionKey(TransferAccessKey::getAccessKey());
    }

    public function setHTTPStatus($httpcode)
    {
        $this->httpstatus = $httpcode;
    }

    public function echoDataFromServiceAndExit()
    {
        // Normal scenario (we use exit to prevent WP from returning some default extra chars to stream)
        http_response_code($this->httpstatus);
        header("Content-Type: " . $this->transfer->getContentType());
        echo $this->transfer->getDataString();
        exit();
    }

    public function getData()
    {
        return $this->transfer->getDataString();
    }

    public function getHeaders()
    {
        $headers = [
            'Content-Type' => $this->transfer->getContentType(),
            'Content-Transfer-Encoding' => 'Binary',
        ];
        return $headers;
    }

    public function setDataObject($object)
    {
        $this->transfer->setDataObject($object);
    }

    public function setTransferObject($object)
    {
        $this->transfer = $object;
    }
}
