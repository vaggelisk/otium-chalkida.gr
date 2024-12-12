<?php

namespace WPSynchro\Logger;

/**
 * NULL logger
 */
class NullLogger implements LoggerInterface
{
    public function log($level, $message, $context = "")
    {
    }
}
