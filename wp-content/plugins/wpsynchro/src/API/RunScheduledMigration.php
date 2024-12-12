<?php

namespace WPSynchro\API;

use WPSynchro\Schedule\ScheduleFactory;

/**
 * Run scheduled migration
 */
class RunScheduledMigration extends WPSynchroService
{
    public function service()
    {
        $key = sanitize_key($_REQUEST['key'] ?? '');
        $smi = sanitize_key($_REQUEST['smi'] ?? '');
        $schedule_factory = ScheduleFactory::getInstance();
        $schedule_factory->runScheduledMigration($smi, $key);
    }
}
