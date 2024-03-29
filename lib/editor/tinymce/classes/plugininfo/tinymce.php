<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Subplugin info class.
 *
 * @package   editor_tinymce
 * @copyright 2012 Petr Skoda {@link http://skodak.org}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace editor_tinymce\plugininfo;

use core\plugininfo\base, moodle_url, part_of_admin_tree, admin_settingpage, core_component;

defined('MOODLE_INTERNAL') || die();


class tinymce extends base {
    /**
     * Finds all enabled plugins, the result may include missing plugins.
     * @return array|null of enabled plugins $pluginname=>$pluginname, null means unknown
     */
    public static function get_enabled_plugins() {
        $disabledsubplugins = array();
        $config = get_config('editor_tinymce', 'disabledsubplugins');
        if ($config) {
            $config = explode(',', $config);
            foreach ($config as $sp) {
                $sp = trim($sp);
                if ($sp !== '') {
                    $disabledsubplugins[$sp] = $sp;
                }
            }
        }

        $enabled = array();
        $installed = core_component::get_plugin_list('tinymce');
        foreach ($installed as $plugin => $fulldir) {
            if (isset($disabledsubplugins[$plugin])) {
                continue;
            }
            $enabled[$plugin] = $plugin;
        }

        return $enabled;
    }

    public static function enable_plugin(string $pluginname, int $enabled): bool {
        $haschanged = false;
        $plugins = [];
        $oldvalue = get_config('editor_tinymce', 'disabledsubplugins');
        if (!empty($oldvalue)) {
            $plugins = array_flip(explode(',', $oldvalue));
        }
        // Only set visibility if it's different from the current value.
        if ($enabled && array_key_exists($pluginname, $plugins)) {
            unset($plugins[$pluginname]);
            $haschanged = true;
        } else if (!$enabled && !array_key_exists($pluginname, $plugins)) {
            $plugins[$pluginname] = $pluginname;
            $haschanged = true;
        }

        if ($haschanged) {
            $new = implode(',', array_flip($plugins));
            add_to_config_log('disabledsubplugins', $oldvalue, $new, 'editor_tinymce');
            set_config('disabledsubplugins', $new, 'editor_tinymce');
            // Reset caches.
            \core_plugin_manager::reset_caches();
        }

        return $haschanged;
    }

    public function is_uninstall_allowed() {

        // XTEC ************ AFEGIT - Disable uninstalling
        // 2023.07.14 @aginard
        if (!get_protected_agora()) {
            return false;
        }
        // ************ FI

        return true;
    }

    /**
     * Return URL used for management of plugins of this type.
     * @return moodle_url
     */
    public static function get_manage_url() {
        return new moodle_url('/admin/settings.php', array('section'=>'editorsettingstinymce'));
    }

    public function get_settings_section_name() {
        return 'tinymce'.$this->name.'settings';
    }

    public function load_settings(part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig) {
        global $CFG, $USER, $DB, $OUTPUT, $PAGE; // In case settings.php wants to refer to them.
        $ADMIN = $adminroot; // May be used in settings.php.
        $plugininfo = $this; // Also can be used inside settings.php.

        if (!$this->is_installed_and_upgraded()) {
            return;
        }

        if (!$hassiteconfig or !file_exists($this->full_path('settings.php'))) {
            return;
        }

        $section = $this->get_settings_section_name();
        $settings = new admin_settingpage($section, $this->displayname, 'moodle/site:config', $this->is_enabled() === false);
        include($this->full_path('settings.php')); // This may also set $settings to null.

        if ($settings) {
            $ADMIN->add($parentnodename, $settings);
        }
    }
}
