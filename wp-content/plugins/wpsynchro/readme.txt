=== WP Synchro - WordPress Migration Plugin for Database & Files ===
Contributors: wpsynchro
Donate link: https://daev.tech/wpsynchro/?utm_source=wordpress.org&utm_medium=referral&utm_campaign=donate
Tags: migrate,clone,files,database,migration
Requires at least: 5.8
Tested up to: 6.6
Stable tag: 1.12.0
Requires PHP: 7.2
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0

WordPress migration plugin that migrates files, database, media, plugins, themes and whatever you want.

== Description ==

**Complete Migration Plugin for WP professionals**

The only migration tool you will ever need as a WP professional.
WP Synchro was created to be the preferred migration plugin, for user with a need to do customized migrations or just full migrations.
You need it done in a fast and easy way, that can be re-run very quickly without any further manual steps, like after a code update.
You can fully customize which database tables you want to move and in PRO version, which files/dirs you want to migrate.

A classic task that WP Synchro will handle for you, is keeping a local development site synchronized with a production site or a staging site in sync with a production site.
You can also push data from your staging or local development enviornment to your production site.

**WP Synchro FREE gives you:**

*   Pull/push database from one site to another site
*   Search/replace in database data (supports serialized data ofc)
*   Handles migration of database table prefixes between sites
*   Select the specific database tables you want to move or just move all
*   Clear cache after migration for popular cache plugins
*   High security - No other sites and servers are involved and all data is encrypted on transfer
*   Setup once - Run multiple times - Perfect for development/staging/production environments

**In addition to this, the PRO version gives you:**

*   File migration (such as media, plugins, themes or custom files/dirs)
*   Only migrate the difference in files, making it super fast
*   Serves a user confirmation on the added/changed/deleted files, before doing any changes
*   Customize the exact migration you need - Down to a single file
*   Support for basic authentication (.htaccess username/password)
*   Notification email on success or failure to a list of emails
*   Database backup before migration
*   WP CLI command to schedule migrations via cron or other trigger
*   Pretty much the ultimate tool for doing WordPress migrations
*   14 day trial is waiting for you to get started at [daev.tech](https://daev.tech/wpsynchro "WP Synchro PRO")

**Typical use for WP Synchro:**

 *  Developing websites on local server and wanting to push a website to a live server or staging server
 *  Get a copy of a working production site, with both database and files, to a staging or local site for debugging or development with real data
 *  Generally moving WordPress sites from one place to another, even on a firewalled local network

**WP Synchro PRO version:**

Pro version gives you more features, such as synchronizing files, database backup, notifications, support for basic authentication, WP CLI command and much faster support.
Check out how to get PRO version at [daev.tech](https://daev.tech/wpsynchro "WP Synchro PRO")
We have a 14 day trial waiting for you and 30 day money back guarantee. So why not try the PRO version?

== Installation ==

**Here is how you get started:**

1. Upload the plugin files to the `/wp-content/plugins/wpsynchro` directory, or install the plugin through the WordPress plugins screen directly
1. Make sure to install the plugin on all the WordPress migrations (it is needed on both ends of the synchronizing)
1. Activate the plugin through the 'Plugins' screen in WordPress
1. Choose if data can be overwritten or be downloaded from migration in menu WP Synchro->Setup
1. Add your first migration from WP Synchro overview page and configure it
1. Run the migration
1. Enjoy
1. Rerun the same migration again next time it is needed and enjoy how easy that was

== Frequently Asked Questions ==

= Do you offer support? =

Yes we do, for both free and PRO version. But PRO version users always get priority support, so support requests for the free version will normally take some time.
Check out how to get PRO version at [daev.tech](https://daev.tech/wpsynchro "WP Synchro site")

You can contact us at <support@daev.tech> for support. Also check out the "Support" menu in WP Synchro, that provides information needed for the support request.

= Does WP Synchro do database merge? =

No. We do not merge data in database. We only migrate the data and overwrite the current.

= Where can i contact you with new ideas and bugs? =

If you have an idea for improving WP Synchro or found a bug in WP Synchro, we would love to hear from you on:
<support@daev.tech>

= What is WP Synchro tested on? (WP Version, PHP, Databases)=

Currently we do automated testing on more than 300 different hosting environments with combinations of WordPress/PHP/Database versions.

WP Synchro is tested on :
 * MySQL 5.6 up to MySQL 8.0 and MariaDB from 10.0 to 10.7.
 * PHP 7.2 up to latest version
 * WordPress from 5.8 to latest version.

= Do you support multisite? =

No, not at the moment.
We have not done testing on multisite yet, so use it is at own risk.
It is currently planned for one of the next releases to support it.

== Screenshots ==

1. Shows the overview of plugin, where you start and delete the migration jobs
2. Shows the add/edit screen, where you setup a migration job
3. Shows the setup of the plugin
4. WP Synchro doing a database migration

== Changelog ==

= 1.12.0 =
 * Improvement: Extend cron scheduling system, so migrations can be run with intervals automatically without user intervention and without WP CLI
 * Improvement: Prevent unwanted background update from PRO version to FREE version for some users
 * Improvement: Make it possible to only delete a single log from the "Logs" menu, instead of all or nothing
 * Improvement: Make it possible to download the database backup from a pull migration in "Logs" menu
 * Bugfix: No longer use ini_restore() native php function, because some hosting does not allow it

= 1.11.5 =
 * Bugfix: Fix links for usage reporting dialog, leading to a non-existing page

= 1.11.4 =
 * Change: Bump minimum PHP requirement to 7.2 from 7.0
 * Change: Bump minimum WP requirement to 5.8 from 5.2
 * Change: Bump minimum MySQL requirement to 5.7 from 5.5
 * Change: Bump supported WP version to 6.5
 * Bugfix: Fix some issues causing menu to generate PHP deprecation issues, even though it just triggered it in WP core functions

= 1.11.3 =
 * Change: Change all service urls from wpsynchro.com to daev.tech, as we have moved the plugin there
 * Bugfix: Fixed a minor csrf issue reported by Patchstack - Not a risk to be worried about.

= 1.11.2 =
 * Bugfix: Fix PHP timeout issue caused by serialized data, kinda like 1.11.1 hotfix, but caused by another data.
 * Improvement: Added more safety against timeout issues in serialized data, so it wont happen again

= 1.11.1 =
 * Bugfix: Fix PHP timeout issue caused by serialized string search/replace handler, that goes into endless loop for defective serialized strings
 * Bugfix: Fix issue with some tables not being migrated when source database is MariaDB and when table does not have a primary key
 * Improvement: Improve the error reporting when database server gives errors

** Only showing the last few releases - See rest of changelog in changelog.txt or in menu "Changelog" **
