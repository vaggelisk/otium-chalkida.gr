<?php

namespace WPSynchro\Logger;

interface LoggerInterface
{
    public function log($level, $message, $context = "");
}
