<?php

/**
 * Class for providing data for JS
 */

namespace WPSynchro\Utilities\JSData;

class LoadJSData
{
    /**
     *  Load the standard JS data that we use one multiple pages
     */
    public function load()
    {
        (new UsageReportingData())->load();
        (new HealthCheckData())->load();
        (new PageHeaderData())->load();
    }
}
