<?php

/**
 * Simple data class for transfer file
 */

namespace WPSynchro\Transport;

class TransferFile
{
    public $key;
    public $section;
    public $filename;
    public $is_partial = false;
    public $is_dir = false;
    public $partial_start = -1;
    public $partial_end = -1;
    public $data = null;
    public $hash = "";
    public $size = 0;
    public $target_file = "";
    public $source_file = "";
    public $is_error = false;   // Such as cannot read anymore or permission error
    public $error_msg = "";
}
