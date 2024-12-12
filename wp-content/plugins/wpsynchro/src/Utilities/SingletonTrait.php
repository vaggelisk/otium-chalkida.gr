<?php

namespace WPSynchro\Utilities;

/**
 * Singleton Trait
 */
trait SingletonTrait
{
    /**
     *  Constructor
     */
    private function __construct()
    {
    }

    /**
     *  Get instance
     *  @return static
     */
    public static function getInstance($force_new_instance = false)
    {
        static $instance = null;
        if ($instance == null || $force_new_instance) {
            $class = static::class;
            $instance = new $class();

            // Do any needed loads
            if (\method_exists($class, 'instanceInit')) {
                $instance->instanceInit();
            }
        }
        return $instance;
    }
}
