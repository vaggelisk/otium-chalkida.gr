<?php

namespace WPSynchro\Migration;

use WPSynchro\Utilities\SingletonTrait;

/**
 * Factory class for migration objects
 */

class MigrationFactory
{
    use SingletonTrait;

    // migrations
    public $migrations = [];
    // Is data loaded?
    private $loaded = false;

    /**
     * Function to return all migrations
     * @return Migration[] Array of migrations
     */
    public function getAllMigrations()
    {
        if (!$this->loaded) {
            $this->loadData();
        }
        return $this->migrations;
    }

    /**
     * Function to retrieve a single migration by id
     * @return Migration|false
     */
    public function retrieveMigration($id)
    {
        if (!$this->loaded) {
            $this->loadData();
        }

        foreach ($this->migrations as $migration) {
            if ($migration->id == $id) {
                $migration->checkAndUpdateToPreset();
                return $migration;
            }
        }

        return false;
    }

    /**
     * Function to delete a single migration by id
     */
    public function deleteMigration($id)
    {
        if (!$this->loaded) {
            $this->loadData();
        }

        // Find and delete it if exists
        foreach ($this->migrations as $key => $migration) {
            if ($migration->id == $id) {
                unset($this->migrations[$key]);
                $this->save();
                return true;
            }
        }
        return false;
    }

    /**
     * Function to duplicate a single migration by id
     */
    public function duplicateMigration($id)
    {
        if (!$this->loaded) {
            $this->loadData();
        }

        foreach ($this->migrations as $key => $migration) {
            if ($migration->id == $id) {
                $new_migration = unserialize(serialize($migration));
                $new_migration->id = uniqid();
                $new_migration->name = $new_migration->name . " copy";
                $this->addMigration($new_migration);
                $this->save();
                return true;
            }
        }
        return false;
    }

    /**
     * Function to save migrations
     */
    public function save()
    {
        if (!$this->loaded) {
            $this->loadData();
        }

        $savedata = [];
        foreach ($this->migrations as $migration) {
            $savedata[] = (array) $migration;
        }

        update_option('wpsynchro_migrations', $savedata, false);
    }

    /**
     * Function to load migration data from db
     */
    private function loadData()
    {
        // New plain migration
        $migration_current = new Migration();
        $migration_current_variables = array_keys(get_object_vars($migration_current));

        // Load data
        $migrations_option = get_option('wpsynchro_migrations', false);
        if ($migrations_option !== false) {
            foreach ($migrations_option as $migration) {
                $temp_migration = new Migration();
                foreach ($migration as $key => $value) {
                    $temp_migration->$key = $value;
                }
                // Make sure it contains values and variables as the current version of migration
                foreach ($migration_current_variables as $var) {
                    if (!isset($temp_migration->$var)) {
                        $temp_migration->$var = $migration_current->$var;
                    }
                    if (is_null($temp_migration->$var)) {
                        $temp_migration->$var = $migration_current->$var;
                    }
                }
                // Set generated data
                $temp_migration->prepareGeneratedData();
                $this->migrations[] = $temp_migration;
            }
        }
        $this->loaded = true;
    }

    /**
     * Function to add a migration
     */
    public function addMigration(migration $migration)
    {
        if (!$this->loaded) {
            $this->loadData();
        }

        // Check if it exist already
        foreach ($this->migrations as $key => $existing_migration) {
            if ($existing_migration->id == $migration->id) {
                $this->migrations[$key] = $migration;
                $this->save();
                return;
            }
        }
        $this->migrations[] = $migration;
        $this->save();
    }

    /**
     * Function to start a migration (if not started)
     */
    public function startMigrationSync($id, $job_id)
    {
        if (!$this->loaded) {
            $this->loadData();
        }

        // Check if exists
        $migration = null;
        foreach ($this->migrations as $migration) {
            if ($migration->id == $id) {
                $migration = $migration;
                break;
            }
        }

        if ($migration == null) {
            return null;
        }

        // Create specific job for processing in db
        $job_identifier = 'wpsynchro_' . $id . '_' . $job_id;
        $job = get_option($job_identifier, false);
        if (!$job) {
            $job_arr = [];
            update_option($job_identifier, $job_arr, false);
        }

        return $job_id;
    }
}
