<?php

namespace WPSynchro\API;

use WPSynchro\Initiate\InitiateTokenRetrieval;
use WPSynchro\Masterdata\MasterdataRetrieval;
use WPSynchro\Logger\NullLogger;
use WPSynchro\Migration\Job;
use WPSynchro\Migration\Migration;
use WPSynchro\Migration\MigrationController;
use WPSynchro\Transport\Destination;
use WPSynchro\Transport\RemoteTransport;
use WPSynchro\Transport\TransferToken;
use WPSynchro\Transport\TransferAccessKey;

/**
 * Class for handling service "migration/verify" - Verifying and gathering masterdata from both source and target
 */
class VerifyMigration extends WPSynchroService
{
    // Service Response
    public $response;
    // Job object
    public $job;

    public function __construct()
    {
        // Generate response
        $this->response = new \stdClass();
        $this->response->errors = [];
        $this->response->warnings = [];
        $this->response->source_masterdata = [];
        $this->response->target_masterdata = [];

        // Set job
        $this->job = new Job();
    }

    public function service()
    {
        // Get data to check on
        $body = json_decode($this->getRequestBody());
        $migration = Migration::map($body);

        /**
         *  Step0: Validate
         */
        $this->validate($migration);
        if (count($this->response->errors) > 0) {
            http_response_code(400);
            echo json_encode($this->response);
            return;
        }

        /**
         *  Step1: Verify that we can connect to url and get a remote token
         */
        // Check the headers, to make sure redirects are not done
        $remote_destination = new Destination(Destination::REMOTE);
        $remote_destination->setMigration($migration);

        $remotetransport = new RemoteTransport();
        $remotetransport->setNoRetries();
        $remotetransport->setNoRedirection();
        $remotetransport->setDestination($remote_destination);
        $remotetransport->init();
        $remotetransport->setUrl($remote_destination->getFullURL());
        $remotetransport->setEncryptionKey($remote_destination->getAccessKey());

        $sync_request_result = $remotetransport->remotePOST();
        $http_status_code_first = substr($sync_request_result->statuscode, 0, 1);

        // Check if headers are 3xx
        if ($http_status_code_first == '3') {
            // Redirect
            $new_location = $sync_request_result->getHeader('location');
            if ($new_location !== false) {
                $this->response->warnings[] = sprintf(__("The remote URL is responding with a redirect to %s, which may or may not cause problems connecting with WP Synchro API services.", "wpsynchro"), $new_location);
            } else {
                $this->response->warnings[] = __("The remote URL is responding with a redirect http code (3xx) to another url, which may or may not cause problems connecting with WP Synchro API services.", "wpsynchro");
            }
        } elseif ($http_status_code_first != '2') {
            // Something not in the 2xx range, which is probably an error of some kind
            $this->response->warnings[] = sprintf(__("The remote URL is responding with a non-successful response (HTTP %d), which may or may not cause problems connecting with WP Synchro API services.", "wpsynchro"), $sync_request_result->statuscode);
        }

        // Try to fetch initiate token
        $remote_token = $this->doInitiateOnRemote($remote_destination);
        $this->job->remote_transfer_token = TransferToken::getTransferToken($migration->access_key, $remote_token);
        $this->response->remote_transfer_token = $this->job->remote_transfer_token;
        if (count($this->response->errors) > 0) {
            http_response_code(400);
            echo json_encode($this->response);
            return;
        }

        /**
         *  Step2: Get local transfer token
         */
        $local_destination = new Destination(Destination::LOCAL);
        $local_token = $this->doInitiateOnRemote($local_destination);
        $this->job->local_transfer_token = TransferToken::getTransferToken(TransferAccessKey::getAccessKey(), $local_token);

        if (count($this->response->errors) > 0) {
            http_response_code(400);
            echo json_encode($this->response);
            return;
        }

        /**
         *  Set data in job object
         */
        $sync_controller = MigrationController::getInstance();
        $sync_controller->job = $this->job;

        if ($migration->type === 'pull') {
            $this->job->from_token = $this->job->remote_transfer_token;
            $this->job->from_accesskey = $migration->access_key;
            $this->job->to_token = $this->job->local_transfer_token;
            $this->job->to_accesskey = TransferAccessKey::getAccessKey();
        } else {
            $this->job->to_token = $this->job->remote_transfer_token;
            $this->job->to_accesskey = $migration->access_key;
            $this->job->from_token = $this->job->local_transfer_token;
            $this->job->from_accesskey = TransferAccessKey::getAccessKey();
        }
        if (count($this->response->errors) > 0) {
            http_response_code(400);
            echo json_encode($this->response);
            return;
        }

        /**
         * Step3: Get masterdata from remote site
         */
        $remote_masterdata = $this->doMasterdataOnRemote($remote_destination, $this->job->remote_transfer_token, $migration->access_key);
        if (count($this->response->errors) > 0) {
            http_response_code(400);
            echo json_encode($this->response);
            return;
        }

        /**
         * Step4: Get masterdata from local site
         */
        $local_masterdata = $this->doMasterdataOnRemote($local_destination, $this->job->local_transfer_token, TransferAccessKey::getAccessKey());
        if (count($this->response->errors) > 0) {
            http_response_code(400);
            echo json_encode($this->response);
            return;
        }

        /**
         *  Set data in response
         */
        if ($migration->type === 'pull') {
            $this->response->source_masterdata = $remote_masterdata;
            $this->response->target_masterdata = $local_masterdata;
        } else {
            $this->response->source_masterdata = $local_masterdata;
            $this->response->target_masterdata = $remote_masterdata;
        }


        echo json_encode($this->response);
        return;
    }

    /**
     *  Validate input
     */
    public function validate(Migration $migration)
    {
        // Valdidate url
        if (!filter_var($migration->site_url, FILTER_VALIDATE_URL)) {
            $this->response->errors[] = __("The website url does not seem to be valid - Please enter a valid website url", "wpsynchro");
        }
        // Validate access key
        if (strlen($migration->access_key) < 10) {
            $this->response->errors[] = __("The access key does not seem to be valid - Please enter a valid access key", "wpsynchro");
        }
        // Validate type
        $allowed_types = ["push", "pull"];
        if (!in_array($migration->type, $allowed_types)) {
            $this->response->errors[] = __("The type of migration does not seem to be valid - Please choose a valid migration type", "wpsynchro");
        }
    }

    /**
     *  Get initiation token from url
     */
    public function doInitiateOnRemote(Destination $destination)
    {
        $logger = new NullLogger();

        $initiate_retrieval = new InitiateTokenRetrieval($logger, $destination, $destination->sync_type);
        $result = $initiate_retrieval->getInitiateToken();

        $initiate_errors = $initiate_retrieval->getErrors();

        $initialize_default_error = sprintf(__("Could not initialize with %s - Check that WP Synchro is installed, connection to the site is not blocked and that health check runs without errors on the site. If basic authentication is enabled on the remote site, make sure connection is set to basic authentication with correct username and password.", "wpsynchro"), $destination->getFullURL());

        if ($result && isset($initiate_retrieval->token) && strlen($initiate_retrieval->token) > 0) {
            return $initiate_retrieval->token;
        } elseif (count($initiate_errors) > 0) {
            $this->response->errors[] = $initialize_default_error;
            foreach ($initiate_errors as $error) {
                $this->response->errors[] = $error;
            }
        } else {
            $this->response->errors[] = $initialize_default_error;
        }

        return "";
    }

    /**
     * Get masterdata from remote
     */
    public function doMasterdataOnRemote(Destination $destination, $transfer_token, $encryption_key)
    {
        // Get masterdata retrival object
        $retrieval = new MasterdataRetrieval($destination);
        $retrieval->setDataToRetrieve(['dbtables', 'filedetails']);
        $retrieval->setToken($transfer_token);
        $retrieval->setEncryptionKey($encryption_key);
        $result = $retrieval->getMasterdata();

        if ($result) {
            if (is_object($retrieval->data) && isset($retrieval->data->base)) {
                return $retrieval->data;
            } else {
                $this->response->errors[] = sprintf(__("Tried to get masterdata from %s, but got wrong response. Make sure WP Synchro health check runs without error on both sites.", "wpsynchro"), $destination->getFullURL());
                return [];
            }
        } else {
            $this->response->errors[] = sprintf(__("Could not get masterdata from %s - Check that WP Synchro is installed, connection to the site is not blocked and that health check runs without errors on the site", "wpsynchro"), $destination->getFullURL());
            return [];
        }
    }
}
