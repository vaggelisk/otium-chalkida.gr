<?php

/**
 * Show changelog
 */

namespace WPSynchro\Pages;

class AdminChangelog
{
    /**
     *  Called from WP menu to show changelog
     */
    public static function render()
    {
        // Load changelog
        $changelog = \file_get_contents(WPSYNCHRO_PLUGIN_DIR . "changelog.txt");

        // Data for JS
        $data_for_js = [
            "changeLog" => $changelog,
        ];
        wp_localize_script('wpsynchro_admin_js', 'wpsynchro_changelog_data', $data_for_js);

        // Print content
        echo '<div id="wpsynchro-changelog" class="wpsynchro"></div>';
    }
}
