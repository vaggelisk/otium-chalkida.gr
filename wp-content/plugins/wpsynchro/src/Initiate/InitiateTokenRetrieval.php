<?php

namespace WPSynchro\Initiate;

use WPSynchro\Logger\LoggerInterface;
use WPSynchro\Transport\Destination;
use WPSynchro\Transport\RemoteTransport;

/**
 * Retrieves initiate token from url
 */
class InitiateTokenRetrieval
{
    public $sync_type;
    public $destination;
    // Data retrieved
    public $service_result;
    public $token = '';
    // Logger - if needed
    public $logger = null;

    /**
     *  Constructor
     */
    public function __construct(LoggerInterface $logger, Destination $destination, $sync_type)
    {
        $this->logger = $logger;
        $this->destination = $destination;
        $this->sync_type = $sync_type;
    }

    /**
     *  Set encryption key on request (optional)
     */
    public function setEncryptionKey($key)
    {
        $this->encryption_key = $key;
    }

    /**
     *  Get initiate token
     */
    public function getInitiateToken()
    {
        // Get url
        $url = $this->destination->getFullURL() . '?action=wpsynchro_initiate&type=';
        if ($this->destination->getDestination() == Destination::LOCAL) {
            $url .= 'local';
        } else {
            $url .= $this->sync_type;
        }

        // Get remote transfer object
        $remotetransport = new RemoteTransport();
        $remotetransport->setDestination($this->destination);
        $remotetransport->init();
        $remotetransport->setUrl($url);
        $remotetransport->setEncryptionKey($this->destination->getAccessKey());
        $remotetransport->setNoRetries();

        $this->service_result = $remotetransport->remotePOST();
        $body = $this->service_result->getBody();

        if ($this->service_result->isSuccess()) {
            if (isset($body->token)) {
                $this->logger->log('DEBUG', 'Got initiate token: ' . $body->token);
                $this->token = $body->token;
                return true;
            } elseif (count($this->getErrors()) > 0) {
                $this->logger->log('CRITICAL', 'Failed initializing - Got error response from remote initiate service -  Response: ', $body);
            } else {
                $this->service_result->errors [] = __("Could not initate with site: " . $this->destination->getDestination() . " - If it is the remote site, this is normally caused by using the wrong access key to this site or the type (pull/push) is not allowed on the remote site.", "wpsynchro");
                $this->logger->log('CRITICAL', 'Failed initializing - Could not fetch a initiation token from remote -  Response body: ', $body);
            }
        } else {
            if (count($this->getErrors()) > 0) {
                $this->logger->log('CRITICAL', 'Failed initializing - Got error response from remote initiate service -  Response: ', $this->getErrors());
            } else {
                $this->logger->log('CRITICAL', 'Failed during initialization, which means we can not continue the migration.');
            }
        }
        return false;
    }

    /**
     *  Get errors
     */
    public function getErrors()
    {
        return $this->service_result->getErrors();
    }

    /**
     *  Get warnings
     */
    public function getWarnings()
    {
        return $this->service_result->getWarnings();
    }
}
