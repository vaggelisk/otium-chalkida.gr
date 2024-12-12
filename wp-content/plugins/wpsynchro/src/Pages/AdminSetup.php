<?php

/**
 * Class for handling what to show when clicking on setup in the menu in wp-admin
 */

namespace WPSynchro\Pages;

use WPSynchro\Utilities\CommonFunctions;
use WPSynchro\Utilities\Configuration\PluginConfiguration;

class AdminSetup
{
    private $show_update_settings_notice = false;
    private $notices = [];

    /**
     *  Called from WP menu to show setup
     */
    public static function render()
    {
        $instance = new self();
        // Handle post
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $instance->handlePOST();
        }
        $instance->handleGET();
    }

    /**
     *  Handle the update of data from setup screen
     */
    private function handlePOST()
    {
        $nonce = $_POST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'wpsynchro_save_setup')) {
            echo "<div class='notice wpsynchro-notice notice-error'><p>" . __('Security token is no longer valid - Try again.', 'wpsynchro') . '</p></div>';
            return false;
        }

        $this->show_update_settings_notice = true;

        // Plugin configuration
        $plugin_configuration = new PluginConfiguration();

        // Save access key
        if (isset($_POST['accesskey'])) {
            $plugin_configuration->setAccessKey($_POST['accesskey']);
        }

        // Save methods allowed
        $pull_allowed = (isset($_POST['allow_pull']) ? true : false);
        $push_allowed = (isset($_POST['allow_push']) ? true : false);
        $plugin_configuration->setAllowedMigrationMethods($pull_allowed, $push_allowed);

        // MU plugin enabled
        $mu_plugin_enable = (isset($_POST['enable_muplugin']) ? true : false);
        $mu_plugin_enable_errors = $plugin_configuration->setMUPluginEnabledState($mu_plugin_enable);
        $this->notices = array_merge($this->notices, $mu_plugin_enable_errors);

        // Slow hosting optimize
        $slow_hosting_optimize = (isset($_POST['slow_hosting_optimize']) ? true : false);
        $plugin_configuration->setSlowHostingSetting($slow_hosting_optimize);

        // Basic auth
        $basic_auth_username = (isset($_POST['basic_auth_username']) ? $_POST['basic_auth_username'] : '');
        $basic_auth_password = (isset($_POST['basic_auth_password']) ? $_POST['basic_auth_password'] : '');
        $plugin_configuration->setBasicAuthSetting($basic_auth_username, $basic_auth_password);

        // Usage reporting
        $usage_reporting = (isset($_POST['usage_reporting']) ? true : false);
        $plugin_configuration->setUsageReportingSetting($usage_reporting);
    }

    /**
     *  Show WP Synchro setup screen
     */
    private function handleGET()
    {
        // Plugin configuration
        $plugin_configuration = new PluginConfiguration();
        $commonfunctions = new CommonFunctions();

        $accesskey = $plugin_configuration->getAccessKey();
        $methodsallowed = $plugin_configuration->getAllowedMigrationMethods();
        $enable_muplugin = $plugin_configuration->getMUPluginEnabledState();
        $enable_slow_hosting_optimize = $plugin_configuration->getSlowHostingSetting();

        $basic_auth = $plugin_configuration->getBasicAuthSetting();
        $basic_auth_username = $basic_auth['username'];
        $basic_auth_password = $basic_auth['password'];

        // Usage reporting
        $usage_reporting = $plugin_configuration->getUsageReportingSetting();

        // Save nonce
        $nonce = wp_create_nonce('wpsynchro_save_setup');

        ?>

        <div class="wrap wpsynchro-setup wpsynchro">
            <h2 class="wpsynchro-page-header-title"><img src="<?= $commonfunctions->getAssetUrl("icon.png") ?>" width="35" height="35" /> WP Synchro <?= WPSYNCHRO_VERSION ?> <?php echo (\WPSynchro\Utilities\CommonFunctions::isPremiumVersion() ? 'PRO' : 'FREE'); ?> - <?php _e('Setup', 'wpsynchro'); ?></h2>

            <?php
            if ($this->show_update_settings_notice) {
                if (count($this->notices) > 0) {
                    ?>
                    <div class="notice notice-error wpsynchro-notice">
                        <?php
                        foreach ($this->notices as $notice) {
                            echo '<p>' . $notice . '</p>';
                        } ?>
                    </div>
                    <?php
                } else {
                    ?>
                    <div class="notice notice-success wpsynchro-notice">
                        <p><?php _e('WP Synchro settings are now updated', 'wpsynchro'); ?></p>
                    </div>
                    <?php
                }
            } ?>

            <form id="wpsynchro-setup-form" method="POST">
                <input type="hidden" name="nonce" value="<?= $nonce ?>" />
                <div class="sectionheader"><span class="dashicons dashicons-admin-tools"></span> <?php _e('Configure settings', 'wpsynchro'); ?></div>
                <table class="">
                    <tr>
                        <td><label for="wp-synchro-accesskey"><?php _e('Access key', 'wpsynchro'); ?></label> <span title="<?= __('Configure the access key used for accessing this migration from remote. Treat the access key like a password and keep it safe from others.', 'wpsynchro') ?>" class="dashicons dashicons-editor-help"></span></td>
                        <td>
                            <input type="text" name="accesskey" id="wp-synchro-accesskey" value="<?php echo $accesskey; ?>" class="regular-text ltr" readonly>
                            <button id="copy-access-key" class="wpsynchrobutton"><?php _e('Copy', 'wpsynchro'); ?></button>
                            <button id="generate-new-access-key" class="wpsynchrobutton"><?php _e('Generate new access key', 'wpsynchro'); ?></button>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Allowed methods', 'wpsynchro'); ?></td>
                        <td>
                            <label><input type="checkbox" name="allow_pull" id="allow_pull" <?php echo ($methodsallowed->pull ? ' checked ' : ''); ?> /> <?php _e('Allow pull - Allow this site to be downloaded', 'wpsynchro'); ?></label><br>
                            <label><input type="checkbox" name="allow_push" id="allow_push" <?php echo ($methodsallowed->push ? ' checked ' : ''); ?> /> <?php _e('Allow push - Allow this site to be overwritten', 'wpsynchro'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Optimize compatibility', 'wpsynchro'); ?></td>
                        <td>
                            <label><input type="checkbox" name="enable_muplugin" id="enable_muplugin" <?php echo ($enable_muplugin ? ' checked ' : ''); ?>> <?php _e('Enable MU Plugin to optimize WP Synchro requests (highly recommended)', 'wpsynchro'); ?> <?= (is_multisite() ? __('(Network wide)', 'wpsynchro') : '')  ?></label><br>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Slow hosting', 'wpsynchro'); ?> <span title="<?= __('Timeouts may happen if the two servers are geographically far apart or under heavy load. This can be enabled to better handle this and allow for large timeout margins.', 'wpsynchro') ?>" class="dashicons dashicons-editor-help"></span></td>
                        <td>
                            <label><input type="checkbox" name="slow_hosting_optimize" id="slow_hosting_optimize" <?php echo ($enable_slow_hosting_optimize ? ' checked ' : ''); ?> /> <?php _e('Optimize for slow hosting or slow connections between servers', 'wpsynchro'); ?></label>
                        </td>
                    </tr>

                    <tr>
                        <td><?php _e('Basic authentication', 'wpsynchro'); ?> <span title="<?= __('If this site is protected by Basic Authentication access protection, WP Synchro will try to auto-detect it, but it does not always work. In that case you need to fill out these fields. WP Synchro will use it to call local services for all migrations.', 'wpsynchro') ?>" class="dashicons dashicons-editor-help"></span></td>
                        <td>
                            <input type="text" name="basic_auth_username" value="<?= $basic_auth_username ?>" autocomplete="off" data-lpignore="true" placeholder="<?php _e('username', 'wpsynchro'); ?>">
                            <input type="password" name="basic_auth_password" value="<?= $basic_auth_password ?>" autocomplete="off" data-lpignore="true" placeholder="<?php _e('password', 'wpsynchro'); ?>">
                            <span><?php _e('WP Synchro will auto-detect these in most cases.', 'wpsynchro'); ?></span>
                        </td>
                    </tr>

                    <tr>
                        <td><?php _e('Usage reporting', 'wpsynchro'); ?> <span title="<?= __('Help WP Synchro become even better, by allowing us to know which features you are using when doing migrations. All reported data sent can be seen in migration log, to provide full transparency.', 'wpsynchro') ?>" class="dashicons dashicons-editor-help"></span></td>
                        <td>
                            <label><input type="checkbox" name="usage_reporting" id="usage_reporting" <?php echo ($usage_reporting ? ' checked ' : ''); ?> /> <?php _e('Allow plugin to report to developer which features are being used when doing migrations. No personal data of course.', 'wpsynchro'); ?></label>
                        </td>
                    </tr>

                </table>
                <p><input type="submit" value="<?php _e('Save settings', 'wpsynchro'); ?>" /></p>

            </form>

        </div>
        <?php
    }
}
