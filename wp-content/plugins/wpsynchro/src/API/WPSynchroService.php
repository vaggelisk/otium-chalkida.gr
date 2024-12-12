<?php

namespace WPSynchro\API;

abstract class WPSynchroService
{
    abstract public function service();

    /**
     *  Get the request body
     */
    public function getRequestBody()
    {
        return file_get_contents('php://input');
    }
}
