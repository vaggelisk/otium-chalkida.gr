<?php

namespace WPSynchro\API;

use WPSynchro\Utilities\CommonFunctions;
use WPSynchro\Migration\MigrationFactory;
use WPSynchro\Utilities\Licensing\Licensing;
use WPSynchro\Utilities\PluginDirs;

/**
 * Class for handling service to download logs
 * Call should already be verified by permissions callback
 *
 */
class DownloadLog extends WPSynchroService
{
    public function service()
    {
        if (!isset($_REQUEST['job_id']) || strlen($_REQUEST['job_id']) == 0) {
            $result = new \StdClass();
            echo json_encode($result);
            http_response_code(400);
            return;
        }
        $job_id = $_REQUEST['job_id'];

        if (!isset($_REQUEST['migration_id']) || strlen($_REQUEST['migration_id']) == 0) {
            $result = new \StdClass();
            echo json_encode($result);
            http_response_code(400);
            return;
        }
        $migration_id = $_REQUEST['migration_id'];

        $common = new CommonFunctions();
        $migration_factory = MigrationFactory::getInstance();

        $plugins_dirs = new PluginDirs();
        $logpath = $plugins_dirs->getUploadsFilePath();
        $filename = $common->getLogFilename($job_id);

        if (file_exists($logpath . $filename)) {
            $logcontents = "";

            // Intro
            $logcontents .= "Beware: Do not share this file with other people than WP Synchro support - It contains data that can compromise your site." . PHP_EOL . PHP_EOL;

            // Licensing
            if (CommonFunctions::isPremiumVersion()) {
                $licensing = new Licensing();
                $logcontents .= print_r($licensing->getLicenseState(), true);
                $logcontents .= PHP_EOL;
            } else {
                $logcontents .= PHP_EOL . "License key:  FREE version" . PHP_EOL;
                $logcontents .= PHP_EOL;
            }

            // Log data
            $logcontents .= file_get_contents($logpath . $filename);
            $job_obj = get_option("wpsynchro_" . $migration_id . "_" . $job_id, "");
            $migration_obj = $migration_factory->retrieveMigration($migration_id);

            // migration object
            $logcontents .= PHP_EOL . "Migration object:" . PHP_EOL;
            $logcontents .= print_r($migration_obj, true);

            // Job object
            $logcontents .= PHP_EOL . "Job object:" . PHP_EOL;
            $logcontents .= print_r($job_obj, true);

            $zipfilename = "wpsynchro_log_" . $job_id . ".zip";

            http_response_code(200);    // IIS fails if this is not here
            header("Pragma: public");
            header("Expires: 0");
            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            header("Cache-Control: public");
            header("Content-Description: File Transfer");
            header("Content-Type: application/zip");
            header("Content-Disposition: attachment; filename=" . $zipfilename);

            $zipfile = tempnam($logpath, "zip");
            $zip = new \ZipArchive();
            $zip->open($zipfile, \ZipArchive::OVERWRITE);
            $zip->addFromString($filename, $logcontents);
            $zip->close();

            readfile($zipfile);
            unlink($zipfile);

            exit();
        } else {
            http_response_code(400);
            return;
        }
    }
}
