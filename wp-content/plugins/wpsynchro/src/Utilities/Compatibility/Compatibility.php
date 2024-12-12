<?php

namespace WPSynchro\Utilities\Compatibility;

/**
 * Class for handling compatibility
 *
 *
 * BEWARE: This is referenced from MU plugin, so handle that if moving it or changing filename etc.
 */
class Compatibility
{
    private $accepted_plugins_list = ["wpsynchro/wpsynchro.php"];

    public function __construct()
    {
        define('WPSYNCHRO_MU_COMPATIBILITY_LOADED', true);
        $this->init();
    }

    /**
     *  Hook into WP filters to change plugins and themes
     */
    public function init()
    {
        add_filter('option_active_plugins', [$this, 'handlePlugins']);
        add_filter('site_option_active_sitewide_plugins', [$this, 'handlePlugins']);
        add_filter('stylesheet_directory', [$this, 'handleTheme']);
        add_filter('template_directory', [$this, 'handleTheme']);
    }

    /**
     *  Make sure only WP Synchro is loaded
     */
    public function handlePlugins($plugins)
    {
        if (!is_array($plugins) || count($plugins) == 0) {
            return $plugins;
        }

        foreach ($plugins as $key => $plugin) {
            if (!in_array($plugin, $this->accepted_plugins_list)) {
                unset($plugins[$key]);
            }
        }
        return $plugins;
    }

    /**
     *  Make sure a empty theme is loaded
     */
    public function handleTheme()
    {
        $compat_theme_root = trailingslashit(dirname(__FILE__)) . "wpsynchro_compat_theme/";
        return $compat_theme_root;
    }
}
