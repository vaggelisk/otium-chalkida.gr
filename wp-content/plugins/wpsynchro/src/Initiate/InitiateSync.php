<?php

namespace WPSynchro\Initiate;

use WPSynchro\Logger\SyncMetadataLog;
use WPSynchro\Migration\MigrationController;
use WPSynchro\Transport\TransferToken;
use WPSynchro\Transport\TransferAccessKey;
use WPSynchro\Transport\Destination;
use WPSynchro\Utilities\SyncTimerList;
use WPSynchro\Utilities\UsageReporting;

/**
 * Class for handling the initiate of the sync
 *
 */
class InitiateSync
{
    // Base data
    public $migration = null;
    public $job = null;
    // Dependencies
    public $logger = null;
    public $timer = null;
    /**
     *  Constructor
     */
    public function __construct()
    {
        $this->logger = MigrationController::getInstance()->getLogger();
        $this->timer = SyncTimerList::getInstance();
    }

    /**
     *  Initiate sync
     */
    public function initiateMigration(&$migration, &$job)
    {
        $this->migration = $migration;
        $this->job = $job;

        // Start timer
        $initiate_timer = $this->timer->startTimer("initiate", "overall", "timer");

        // Do usage reporting, if accepted by the user ofc
        $usage_reporting = new UsageReporting();
        $usage_reporting->sendUsageReporting($migration);
        $this->logger->log("INFO", "Initating with remote and local host with remaining time:" . $this->timer->getRemainingSyncTime());

        // Start migration in metadatalog
        $metadatalog = new SyncMetadataLog();
        $metadatalog->startMigration($this->job->id, $this->migration->id, $this->migration->getOverviewDescription());

        // Start by getting local transfertoken
        $local_token = $this->getInitiateTransferToken(new Destination(Destination::LOCAL));

        // Check token
        if (strlen($local_token) < 20) {
            $this->logger->log("CRITICAL", __("Failed initializing - Could not get a valid token from local server", "wpsynchro"));
        }

        // Get remote transfertoken
        $remote_token = $this->getInitiateTransferToken(new Destination(Destination::REMOTE));
        // Check token
        if (is_null($remote_token) || strlen($remote_token) < 20) {
            $this->logger->log("CRITICAL", __("Failed initializing - Could not get a valid token from remote server", "wpsynchro"));
        }

        // If no errors, set transfer tokens in job object
        if (count($this->job->errors) == 0) {
            // Set tokens in job
            $local_transfer_token = TransferToken::getTransferToken(TransferAccessKey::getAccessKey(), $local_token);
            $remote_transfer_token = TransferToken::getTransferToken($this->migration->access_key, $remote_token);
            if ($this->migration->type == 'pull') {
                $this->job->from_token = $remote_transfer_token;
                $this->job->from_accesskey = $this->migration->access_key;
                $this->job->to_token = $local_transfer_token;
                $this->job->to_accesskey = TransferAccessKey::getAccessKey();
            } else {
                $this->job->to_token = $remote_transfer_token;
                $this->job->to_accesskey = $this->migration->access_key;
                $this->job->from_token = $local_transfer_token;
                $this->job->from_accesskey = TransferAccessKey::getAccessKey();
            }

            $this->job->local_transfer_token = $local_transfer_token;
            $this->job->remote_transfer_token = $remote_transfer_token;
            // Final checks
            if (strlen($this->job->to_token) < 10) {
                $this->logger->log("CRITICAL", __("Failed initializing - No 'to' token could be found after initialize", "wpsynchro"));
            }
            if (strlen($this->job->from_token) < 10) {
                $this->logger->log("CRITICAL", __("Failed initializing - No 'from' token could be found after initialize", "wpsynchro"));
            }
        }

        $this->logger->log("INFO", "Initation completed on: " . $this->timer->endTimer($initiate_timer) . " seconds");
        if (count($this->job->errors) == 0) {
            $this->job->initiation_completed = true;
        }
    }

    /**
     *  Retrieve initate transfer token
     */
    public function getInitiateTransferToken(Destination $destination)
    {
        if (count($this->job->errors) > 0) {
            return;
        }

        $this->logger->log("DEBUG", "Calling initate service for destination: " . $destination->getDestination());
        $initiate_retrieval = new InitiateTokenRetrieval($this->logger, $destination, $this->migration->type);
        $result = $initiate_retrieval->getInitiateToken();
        $initiate_errors = $initiate_retrieval->getErrors();
        if (count($initiate_errors) > 0) {
            $this->job->errors = array_merge($this->job->errors, $initiate_errors);
        } elseif ($result && isset($initiate_retrieval->token) && strlen($initiate_retrieval->token) > 0) {
            return $initiate_retrieval->token;
        } else {
            $this->job->errors[] = sprintf(__("Could not initialize with %s - Check that WP Synchro is installed, connection to the site is not blocked, migration type (push/pull) is allowed in setup and that health check runs without errors on the site", "wpsynchro"), $this->migration->site_url);
        }
    }
}
