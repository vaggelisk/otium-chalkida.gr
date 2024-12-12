<?php

namespace WPSynchro\Transport;

/**
 * Transfer access key
 */
class TransferAccessKey
{
    /**
     * Return this migration access key
     */
    public static function getAccessKey()
    {
        return get_option('wpsynchro_accesskey', "");
    }

    /**
     * Generate access key
     */
    public static function generateAccesskey()
    {
        $token = bin2hex(openssl_random_pseudo_bytes(16));
        return $token;
    }
}
