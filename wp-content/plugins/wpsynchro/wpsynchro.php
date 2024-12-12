<?php
/*
  Plugin Name: WP Synchro FREE
  Plugin URI: https://daev.tech/wpsynchro
  Description: Professional migration plugin for WordPress - Migration of database and files made easy
  Version: 1.12.0
  Author: DAEV.tech
  Author URI: https://daev.tech
  Domain Path: /languages
  Text Domain: wpsynchro
  License: GPLv3
  License URI: http://www.gnu.org/licenses/gpl-3.0
  Update URI: https://wordpress.org/plugins/wpsynchro/
 */

/**
 * 	Copyright (C) 2018 DAEV (email: support@daev.tech)
 *
 * 	This program is free software; you can redistribute it and/or
 * 	modify it under the terms of the GNU General Public License
 * 	as published by the Free Software Foundation; either version 2
 * 	of the License, or (at your option) any later version.
 *
 * 	This program is distributed in the hope that it will be useful,
 * 	but WITHOUT ANY WARRANTY; without even the implied warranty of
 * 	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * 	GNU General Public License for more details.
 *
 * 	You should have received a copy of the GNU General Public License
 * 	along with this program; if not, write to the Free Software
 * 	Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */
if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

define('WPSYNCHRO_VERSION', '1.12.0');
define('WPSYNCHRO_DB_VERSION', '10');
define('WPSYNCHRO_NEWEST_MU_COMPATIBILITY_VERSION', '1.0.5');

// Load composer autoloader
require_once dirname(__FILE__) . '/vendor/autoload.php';

/**
 *  On plugin activation
 */
function wpsynchroActivation($networkwide)
{
    \WPSynchro\Utilities\Activation::activate($networkwide);
}
register_activation_hook(__FILE__, 'wpsynchroActivation');

/**
 *  On plugin deactivation
 */
function wpsynchroDeactivation()
{
    \WPSynchro\Utilities\Activation::deactivate();
}
register_deactivation_hook(__FILE__, 'wpsynchroDeactivation');

/**
 *  On plugin uninstall
 */
function wpsynchroUninstall()
{
    \WPSynchro\Utilities\Activation::uninstall();
}
register_uninstall_hook(__FILE__, 'wpsynchroUninstall');

/**
 *  Run WP Synchro
 */
(new \WPSynchro\WPSynchroBootstrap())->run();
