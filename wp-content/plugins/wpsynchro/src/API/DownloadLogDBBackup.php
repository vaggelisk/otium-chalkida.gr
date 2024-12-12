<?php

namespace WPSynchro\API;

use WPSynchro\Utilities\PluginDirs;

/**
 * Class for handling service to download database backups
 * Call should already be verified by permissions callback
 */
class DownloadLogDBBackup extends WPSynchroService
{
    public function service()
    {
        if (!isset($_REQUEST['job_id']) || strlen($_REQUEST['job_id']) == 0) {
            $result = new \StdClass();
            echo json_encode($result);
            http_response_code(400);
            return;
        }
        $job_id = sanitize_key($_REQUEST['job_id']);

        $filename = "database_backup_" . $job_id . ".sql";
        $plugins_dirs = new PluginDirs();
        $log_path = $plugins_dirs->getUploadsFilePath();

        if (!file_exists($log_path . $filename)) {
            http_response_code(400);
            return;
        }

        $log_contents = file_get_contents($log_path . $filename);

        $zipfilename = "wpsynchro_db_backup_" . $job_id . ".zip";

        http_response_code(200);    // IIS fails if this is not here
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header("Content-Description: File Transfer");
        header("Content-Type: application/zip");
        header("Content-Disposition: attachment; filename=" . $zipfilename);

        $zipfile = tempnam($log_path, "zip");
        $zip = new \ZipArchive();
        $zip->open($zipfile, \ZipArchive::OVERWRITE);
        $zip->addFromString($filename, $log_contents);
        $zip->close();

        readfile($zipfile);
        unlink($zipfile);

        exit();
    }
}
