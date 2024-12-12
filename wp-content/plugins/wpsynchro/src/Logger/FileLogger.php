<?php

namespace WPSynchro\Logger;

use WPSynchro\Utilities\PluginDirs;

/**
 *  File Logger - Used to log migration runs
 */
class FileLogger implements LoggerInterface
{
    public $filename = "";
    public $filename_prefix = "";
    public $filepath = "";
    public $dateformat = 'Y-m-d H:i:s.u';
    public $log_levels = [
        "EMERGENCY" => 0,
        "ALERT" => 1,
        "CRITICAL" => 2,
        "ERROR" => 3,
        "WARNING" => 4,
        "NOTICE" => 5,
        "INFO" => 6,
        "DEBUG" => 7
    ];
    public $log_level_threshold = "DEBUG";

    public function __construct(string $filename = '')
    {
        $plugin_dirs = new PluginDirs();
        $this->setFilePath($plugin_dirs->getUploadsFilePath());

        if (!empty($filename)) {
            $this->setFileName($filename);
        }
    }

    public function setFilePath($path)
    {
        if ($path == $this->filepath) {
            return;
        }

        $this->filepath = trailingslashit($path);
    }

    public function setFileName($filename)
    {
        $this->filename = $filename;
    }

    public function log($level, $message, $context = "")
    {
        if ($this->log_levels[$this->log_level_threshold] < $this->log_levels[$level]) {
            return;
        }
        if ($this->filename == "" || $this->filepath == "") {
            throw new \LogicException('Filepath or filename for logger is not set');
        }

        // Format log msg
        $date = new \DateTime();

        $formatted_msg = "[{$date->format($this->dateformat)}] [{$level}] {$message}" . PHP_EOL;

        // If context, print that on newline
        if (is_array($context) || is_object($context)) {
            $formatted_msg .= PHP_EOL . esc_html(print_r($context, true)) . PHP_EOL;
        } elseif (is_string($context) && strlen($context) > 0) {
            $formatted_msg .= esc_html($context) . PHP_EOL;
        }
        $complete_path = $this->filepath . $this->filename_prefix . $this->filename;

        file_put_contents($complete_path, $formatted_msg, FILE_APPEND);
    }
}
