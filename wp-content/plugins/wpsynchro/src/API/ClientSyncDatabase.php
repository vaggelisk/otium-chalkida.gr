<?php

namespace WPSynchro\API;

use WPSynchro\Database\DatabaseHelperFunctions;
use WPSynchro\Transport\ReturnResult;
use WPSynchro\Transport\Transfer;
use WPSynchro\Transport\TransferAccessKey;

/**
 * Class for handling service to execute SQL from remote
 * Call should already be verified by permissions callback
 */
class ClientSyncDatabase extends WPSynchroService
{
    public function service()
    {
        $transfer = new Transfer();
        $transfer->setEncryptionKey(TransferAccessKey::getAccessKey());
        $transfer->populateFromString($this->getRequestBody());
        $body = $transfer->getDataObject();

        global $wpdb;
        $result = new \stdClass();
        $result->errors = [];
        $result->warnings = [];
        $result->debugs = [];
        $result->data = null;
        $result->has_more_rows_in_table = true;

        // Extract parameters
        if (isset($body->type)) {
            $type = $body->type;
        } else {
            $result->errors[] = __("Error from database sync service - Check the log file for further information.", "wpsynchro");
            $result->debugs[] = "Error body from service: " . json_encode($body);
            $returnresult = new ReturnResult();
            $returnresult->init();
            $returnresult->setHTTPStatus(400);
            $returnresult->setDataObject($result);
            return $returnresult->echoDataFromServiceAndExit();
        }

        $table = $body->table ?? '';
        $last_primary_key = $body->last_primary_key ?? '';
        $primary_key_column = $body->primary_key_column ?? '';
        $completed_rows = $body->completed_rows ?? 0;
        $max_response_size = $body->max_response_size ?? 1000000;
        $default_rows_per_request = $body->default_rows_per_request ?? 10000;
        $column_names = $body->column_names ?? [];
        $time_limit = $body->time_limit ?? -1;

        $sql_inserts = $body->sql_inserts ?? [];


        if ($type == "pull") {
            $database_helper_functions = new DatabaseHelperFunctions();
            $data_result_from_db = $database_helper_functions->getDataFromDB($table, $column_names, $primary_key_column, $last_primary_key, $completed_rows, $max_response_size, $default_rows_per_request, $time_limit);
            $result->data = $data_result_from_db->data;
            $result->has_more_rows_in_table = $data_result_from_db->has_more_rows_in_table;
            $result->errors = array_merge($result->errors, $data_result_from_db->errors);
        } elseif ($type == "push") {
            $wpdb->query("SET FOREIGN_KEY_CHECKS=0;");
            // If multiple sql inserts
            if (is_array($sql_inserts)) {
                foreach ($sql_inserts as $sql_insert) {
                    $result->data = $wpdb->query($sql_insert);
                    if (strlen($wpdb->last_error) > 0) {
                        $result->errors[] = $wpdb->last_error;
                        $wpdb->last_error = '';
                    }
                    // If it fails, break out and handle it
                    if ($result->data === false) {
                        break;
                    }
                }
            } else {
                $result->data = $wpdb->query($sql_inserts);
                if (strlen($wpdb->last_error) > 0) {
                    $result->errors[] = $wpdb->last_error;
                    $wpdb->last_error = '';
                }
            }
        } elseif ($type == "finalize") {
            $wpdb->query("SET FOREIGN_KEY_CHECKS=0;");
            foreach ($sql_inserts as $sql_insert) {
                $result->data = $wpdb->query($sql_insert);
                if (strlen($wpdb->last_error) > 0) {
                    $result->errors[] = $wpdb->last_error;
                    $wpdb->last_error = '';
                }
            }
        }

        if ($result->data === false) {
            $database_helper_functions = new DatabaseHelperFunctions();
            $logs = $database_helper_functions->getLastDBQueryErrors();
            foreach ($logs['user_errors'] as $user_error) {
                $result->errors[] = $user_error;
            }
            foreach ($logs['log_errors'] as $log_error) {
                $result->debugs[] = $log_error;
            }
        }

        // Prefix errors, warnings, debugs with site url
        $site_url = 'Database error on ' . parse_url(get_site_url(), PHP_URL_HOST) . ': ';
        foreach ($result->errors as &$error) {
            $error = $site_url . $error;
        }
        foreach ($result->warnings as &$warning) {
            $warning = $site_url . $warning;
        }
        foreach ($result->debugs as &$debug) {
            $debug = $site_url . $debug;
        }

        $returnresult = new ReturnResult();
        $returnresult->init();
        $returnresult->setDataObject($result);
        return $returnresult->echoDataFromServiceAndExit();
    }
}
