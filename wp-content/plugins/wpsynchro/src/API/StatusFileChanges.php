<?php

/**
 * Class for handling to get file changes for current sync
 * Call should already be verified by permissions callback
 */

namespace WPSynchro\API;

use WPSynchro\Files\SyncList;
use WPSynchro\Migration\Job;

class StatusFileChanges extends WPSynchroService
{
    public function service()
    {
    }

    /**
     *  Get the file changes
     */
    private function getJobFromRequest()
    {
        if (!isset($_REQUEST['job_id']) || strlen($_REQUEST['job_id']) == 0) {
            http_response_code(400);
            return;
        }
        if (!isset($_REQUEST['migration_id']) || strlen($_REQUEST['migration_id']) == 0) {
            http_response_code(400);
            return;
        }
        $migration_id = $_REQUEST['migration_id'];
        $job_id = $_REQUEST['job_id'];

        $job = new Job();
        $job_loaded = $job->load($migration_id, $job_id);
        if (!$job_loaded) {
            return null;
        }
        return $job;
    }


    /**
     *  Get the file changes
     */
    public function getFileChanges()
    {
        $job = $this->getJobFromRequest();

        $sync_list = new SyncList();
        $sync_list->job = $job;

        $need_transfer = $sync_list->getFileChangesByType("add");
        $files_for_delete = $sync_list->getFileChangesByType("delete");

        $files_changed = [
            'will_be_deleted' => $files_for_delete,
            'will_be_added_changed' => $need_transfer,
            'basepath' => $job->to_files_above_webroot_dir,
        ];

        echo json_encode($files_changed);
    }

    /**
     *  Accept from user of file changes
     */
    public function acceptFileChanges()
    {
        $job = $this->getJobFromRequest();

        // Set it as confirmed
        $job->files_user_confirmed_actions = true;
        // As the worker is paused, we just update the run lock timer, giving the JS worker thread 20 seconds to start again
        $job->run_lock_problem_time = time() + 20;
        // Boyaa!
        $job->save();
    }
}
