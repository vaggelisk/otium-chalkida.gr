<?php

namespace WPSynchro\Utilities\Actions;

/**
 * Interface for actions
 */
interface Action
{
    public function init();
    public function doAction($params);
}
