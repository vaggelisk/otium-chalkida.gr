<?php

namespace WPSynchro\Database\Exception;

class SerializedStringException extends \Exception
{
    public $data = '';

    public function __construct(string $message, int $code = 0, string $data = '', \Throwable $previous = null)
    {
        $this->data = $data;

        // make sure everything is assigned properly
        parent::__construct($message, $code, $previous);
    }
}
