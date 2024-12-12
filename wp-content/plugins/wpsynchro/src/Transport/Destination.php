<?php

namespace WPSynchro\Transport;

use WPSynchro\Migration\Migration;
use WPSynchro\Migration\Job;
use WPSynchro\Migration\MigrationController;
use WPSynchro\Utilities\Configuration\PluginConfiguration;

/**
 * Class for basic auth stuff
 *
 */
class Destination
{
    private $destination = "";
    public $sync_type = "";
    private $migration = null;
    private $job = null;
    const TARGET = 'target';
    const SOURCE = 'source';
    const LOCAL = 'local';
    const REMOTE = 'remote';
    const OTHER = 'other';

    /**
     *  Constructor
     */
    public function __construct($destination = "")
    {
        $this->destination = $destination;
        if ($this->destination !== self::OTHER) {
            // Get migration
            $sync_controller = MigrationController::getInstance();
            if ($sync_controller->migration instanceof Migration) {
                $this->setMigration($sync_controller->migration);
                $this->setJob($sync_controller->job);
            }
        }

        if ($destination == self::LOCAL) {
            $this->sync_type = self::LOCAL;
        }
    }

    /**
     * Set migration, to use it out of migration context
     */
    public function setMigration(Migration $migration)
    {
        $this->migration = $migration;
        if (!is_null($this->migration)) {
            $this->sync_type = $this->migration->type;
        }
    }

    /**
     * Set job
     */
    public function setJob(Job $job)
    {
        $this->job = $job;
    }

    /**
     * Get migration
     */
    public function getmigration()
    {
        return $this->getmigration();
    }

    /**
     * Get destination
     */
    public function getDestination()
    {
        return $this->destination;
    }

    /**
     * Get full url, given a url path without trailing slash
     */
    public function getFullURL($url_path = "")
    {
        if (isset($this->migration->site_url)) {
            $remote_site_url = $this->migration->site_url;
        } else {
            $remote_site_url = "";
        }
        $base_url = "";

        if ($this->destination == self::LOCAL) {
            $base_url = get_home_url();
        } elseif ($this->destination == self::REMOTE) {
            $base_url = $remote_site_url;
        } elseif ($this->destination == self::TARGET) {
            if ($this->sync_type == 'pull') {
                $base_url = get_home_url();
            } elseif ($this->sync_type == 'push') {
                $base_url = $remote_site_url;
            }
        } elseif ($this->destination == self::SOURCE) {
            if ($this->sync_type == 'pull') {
                $base_url = $remote_site_url;
            } elseif ($this->sync_type == 'push') {
                $base_url = get_home_url();
            }
        }

        $url_path = trim($url_path, ' /\\');
        $url = trailingslashit($base_url) . $url_path;
        return $url;
    }

    /**
     * Get accesskey for destination
     */
    public function getAccessKey()
    {
        if ($this->destination == self::LOCAL) {
            return TransferAccessKey::getAccessKey();
        } elseif ($this->destination == self::REMOTE) {
            return $this->migration->access_key;
        } elseif ($this->destination == self::TARGET) {
            if ($this->sync_type == 'pull') {
                return TransferAccessKey::getAccessKey();
            } elseif ($this->sync_type == 'push') {
                return $this->migration->access_key;
            }
        } elseif ($this->destination == self::SOURCE) {
            if ($this->sync_type == 'pull') {
                return $this->migration->access_key;
            } elseif ($this->sync_type == 'push') {
                return TransferAccessKey::getAccessKey();
            }
        }
        return null;
    }

    /**
     * Whether to verify SSL
     */
    public function shouldVerifySSL()
    {
        if ($this->destination == self::LOCAL) {
            return false;
        } elseif ($this->destination == self::REMOTE) {
            return $this->migration->verify_ssl;
        } elseif ($this->destination == self::TARGET) {
            if ($this->sync_type == 'pull') {
                return false;
            } elseif ($this->sync_type == 'push') {
                return $this->migration->verify_ssl;
            }
        } elseif ($this->destination == self::SOURCE) {
            if ($this->sync_type == 'pull') {
                return $this->migration->verify_ssl;
            } elseif ($this->sync_type == 'push') {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether to use basic auth
     */
    public function getBasicAuthentication()
    {
        if ($this->destination == self::LOCAL) {
            return $this->getLocalBasicAuth();
        } elseif ($this->destination == self::REMOTE) {
            return $this->getRemoteBasicAuth();
        } elseif ($this->destination == self::TARGET) {
            if ($this->sync_type == 'pull') {
                return $this->getLocalBasicAuth();
            } elseif ($this->sync_type == 'push') {
                return $this->getRemoteBasicAuth();
            }
        } elseif ($this->destination == self::SOURCE) {
            if ($this->sync_type == 'pull') {
                return $this->getRemoteBasicAuth();
            } elseif ($this->sync_type == 'push') {
                return $this->getLocalBasicAuth();
            }
        }
        return false;
    }


    /**
     * Determine if the destination is the source
     */
    public function isSource()
    {
        if ($this->destination == self::LOCAL) {
            if ($this->sync_type == 'pull') {
                return false;
            } elseif ($this->sync_type == 'push') {
                return true;
            }
        } elseif ($this->destination == self::REMOTE) {
            if ($this->sync_type == 'pull') {
                return true;
            } elseif ($this->sync_type == 'push') {
                return false;
            }
        } elseif ($this->destination == self::TARGET) {
            return false;
        } elseif ($this->destination == self::SOURCE) {
            return true;
        }
        return false;
    }

    /**
     * Get remote basic auth
     */
    private function getRemoteBasicAuth()
    {
        // Set basic authentication if needed
        if (isset($this->migration->connection_type) && $this->migration->connection_type === 'basicauth') {
            return [
                $this->migration->basic_auth_username,
                $this->migration->basic_auth_password
            ];
        }
        return false;
    }

    /**
     * Get local basic auth
     */
    private function getLocalBasicAuth()
    {
        // Check for basic auth, check in request first or alternativly, use the configuration
        if (isset($_SERVER['PHP_AUTH_USER']) && strlen($_SERVER['PHP_AUTH_USER']) > 0) {
            return [
                $_SERVER['PHP_AUTH_USER'],
                $_SERVER['PHP_AUTH_PW']
            ];
        } else {
            $plugin_configuration = new PluginConfiguration();
            $basic_auth_config = $plugin_configuration->getBasicAuthSetting();

            if (strlen($basic_auth_config['username']) > 0) {
                return [
                    $basic_auth_config['username'],
                    $basic_auth_config['password']
                ];
            }
        }
        return false;
    }
}
