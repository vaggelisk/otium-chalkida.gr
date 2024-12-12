<?php

namespace WPSynchro;

use WPSynchro\API\LoadAPI;
use WPSynchro\Utilities\Upgrade\DatabaseUpgrade;
use WPSynchro\Updater\PluginUpdater;
use WPSynchro\CLI\WPCLICommand;
use WPSynchro\Schedule\ScheduleFactory;
use WPSynchro\Utilities\CommonFunctions;
use WPSynchro\Utilities\Compatibility\MUPluginHandler;
use WPSynchro\Utilities\JSData\DeactivatePluginData;
use WPSynchro\Utilities\JSData\LoadJSData;
use WPSynchro\Utilities\JSData\PageHeaderData;
use WPSynchro\Utilities\Licensing\Licensing;

/**
 * Primary plugin class
 * Loads all the needed stuff to get the plugin off the ground and make the user a happy panda
 *
 */
class WPSynchroBootstrap
{
    /**
     *  Initialize plugin, setting some defines for later use
     */
    public function __construct()
    {
        define('WPSYNCHRO_PLUGIN_DIR', WP_PLUGIN_DIR . '/wpsynchro/');
        define('WPSYNCHRO_PLUGIN_URL', trailingslashit(plugins_url('/wpsynchro')));
    }

    /**
     * Run method, that will kickstart all the needed initialization
     */
    public function run()
    {
        // Check database need update
        if (is_admin()) {
            DatabaseUpgrade::checkDBVersion();
        }

        // Load WP CLI command, if WP CLI request
        if (defined('WP_CLI') && WP_CLI && CommonFunctions::isPremiumVersion()) {
            \WP_CLI::add_command('wpsynchro', new WPCLICommand());
        }

        // Load API endpoints
        $this->loadAPI();

        // Load cron
        $this->loadCron();

        // Only load backend stuff when needed
        if (is_admin()) {
            if (CommonFunctions::isPremiumVersion()) {
                // Check licensing for wp-admin calls, and only if pro version
                $licensing = new Licensing();
                $licensing->verifyLicense();

                // Check for updates
                $pluginupdater = new PluginUpdater();
                $pluginupdater->checkForUpdate();
            }

            $this->loadBackendAdmin();
            $this->loadTextdomain();

            // Check if MU plugin needs update
            $muplugin_handler = new MUPluginHandler();
            $muplugin_handler->checkNeedsUpdate();
        }
    }

    /**
     *  Load admin related functions (menus,etc)
     */
    private function loadBackendAdmin()
    {
        $this->addMenusToBackend();
        $this->addStylesAndScripts();
        $this->loadActions();
    }

    /**
     * Load cron
     */
    private function loadCron()
    {
        if (CommonFunctions::isPremiumVersion()) {
            (ScheduleFactory::getInstance())->setupCron();
        }
    }

    /**
     *  Load new API services used by WP Synchro
     */
    private function loadAPI()
    {
        add_action(
            'plugins_loaded',
            function () {
                $load_api = new LoadAPI();
                $load_api->setup();
            },
            1
        );
    }

    /**
     *  Load other actions
     */
    private function loadActions()
    {
        add_action('admin_init', function () {
            if (isset($_GET['wpsynchro_dismiss_review_request'])) {
                update_site_option('wpsynchro_dismiss_review_request', true);
                wp_die();
            }
        });
    }

    /**
     *  Load text domain
     */
    private function loadTextdomain()
    {
        add_action(
            'init',
            function () {
                load_plugin_textdomain('wpsynchro', false, 'wpsynchro/languages');
            }
        );
    }

    /**
     *   Add menu to backend
     */
    private function addMenusToBackend()
    {
        add_action(
            'admin_menu',
            function () {
                add_menu_page('WP Synchro', 'WP Synchro', 'manage_options', 'wpsynchro_menu', [__NAMESPACE__ . '\\Pages\AdminOverview', 'render'], 'dashicons-update', 76);

                add_submenu_page('wpsynchro_menu', __('Overview', 'wpsynchro'), __('Overview', 'wpsynchro'), 'manage_options', 'wpsynchro_menu', [__NAMESPACE__ . '\\Pages\AdminOverview', 'render']);
                add_submenu_page('wpsynchro_menu', __('Logs', 'wpsynchro'), __('Logs', 'wpsynchro'), 'manage_options', 'wpsynchro_log', [new \WPSynchro\Pages\AdminLog(), 'render']);
                add_submenu_page('wpsynchro_menu', __('Setup', 'wpsynchro'), __('Setup', 'wpsynchro'), 'manage_options', 'wpsynchro_setup', [__NAMESPACE__ . '\\Pages\AdminSetup', 'render']);
                if (CommonFunctions::isPremiumVersion()) {
                    add_submenu_page('wpsynchro_menu', __('Scheduled', 'wpsynchro'), __('Scheduled', 'wpsynchro') . ' [BETA]', 'manage_options', 'wpsynchro_scheduled', [__NAMESPACE__ . '\\Pages\AdminScheduled', 'render']);
                    add_submenu_page('wpsynchro_menu', __('Licensing', 'wpsynchro'), __('Licensing', 'wpsynchro'), 'manage_options', 'wpsynchro_licensing', [__NAMESPACE__ . '\\Pages\AdminLicensing', 'render']);
                }
                add_submenu_page('wpsynchro_menu', __('Support', 'wpsynchro'), __('Support', 'wpsynchro'), 'manage_options', 'wpsynchro_support', [__NAMESPACE__ . '\\Pages\AdminSupport', 'render']);
                add_submenu_page('wpsynchro_menu', __('Changelog', 'wpsynchro'), __('Changelog', 'wpsynchro'), 'manage_options', 'wpsynchro_changelog', [__NAMESPACE__ . '\\Pages\AdminChangelog', 'render']);

                // Run migration page (not in menu)
                add_submenu_page('wpsynchro_menu', '', '', 'manage_options', 'wpsynchro_run', [__NAMESPACE__ . '\\Pages\AdminRunSync', 'render']);
                // Add migration page (not in menu)
                add_submenu_page('wpsynchro_menu', '', '', 'manage_options', 'wpsynchro_addedit', [__NAMESPACE__ . '\\Pages\AdminAddEdit', 'render']);
            }
        );
    }

    /**
     *   Add CSS and JS to backend
     */
    private function addStylesAndScripts()
    {
        // Admin scripts
        add_action(
            'admin_enqueue_scripts',
            function ($hook) {
                if (strpos($hook, 'wpsynchro') > -1) {
                    $commonfunctions = new CommonFunctions();
                    wp_enqueue_script('wpsynchro_admin_js', $commonfunctions->getAssetUrl('wpsynchro.js'), ['wp-i18n'], WPSYNCHRO_VERSION, true);
                    wp_set_script_translations('wpsynchro_admin_js', 'wpsynchro', WPSYNCHRO_PLUGIN_DIR . 'languages');

                    // Load standard data we need
                    (new LoadJSData())->load();
                }
            }
        );

        // Admin styles
        add_action('admin_enqueue_scripts', function ($hook) {
            if (strpos($hook, 'wpsynchro') > -1) {
                $commonfunctions = new CommonFunctions();
                wp_enqueue_style('wpsynchro_admin_css', $commonfunctions->getAssetUrl('wpsynchro.css'), [], WPSYNCHRO_VERSION);
            }
        });

        // Load deactivate modal, to give us feedback on deactivations
        add_action(
            'admin_enqueue_scripts',
            function ($hook) {
                if ($hook == 'plugins.php') {
                    $commonfunctions = new CommonFunctions();
                    wp_enqueue_script('wpsynchro_deactivate_js', $commonfunctions->getAssetUrl('deactivation.js'), [], WPSYNCHRO_VERSION, true);
                    wp_set_script_translations('wpsynchro_deactivate_js', 'wpsynchro', WPSYNCHRO_PLUGIN_DIR . 'languages');

                    (new DeactivatePluginData())->load();
                    (new PageHeaderData())->load('wpsynchro_deactivate_js');
                }
            }
        );
        add_action('admin_footer', function () {
            echo '<div id="wpsynchro-deactivate" v-cloak><deactivate-modal v-if="showModal" v-on:close="showModal = false" v-bind:deactivate-url="deactivateURL"></deactivate-modal></div>';
        });
        // Admin styles for deactivation
        add_action('admin_enqueue_scripts', function ($hook) {
            if ($hook == 'plugins.php') {
                $commonfunctions = new CommonFunctions();
                wp_enqueue_style('wpsynchro_admin_css', $commonfunctions->getAssetUrl('deactivation.css'), [], WPSYNCHRO_VERSION);
            }
        });
    }
}
