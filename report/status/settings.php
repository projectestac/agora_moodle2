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
 * Plugin version info
 *
 * @package    report_status
 * @copyright  2020 Brendan Heywood (brendan@catalyst-au.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// XTEC ************ AFEGIT - Allow access only to xtecadmin user (report_status)
// 2021.07.14 @aginard
if (get_protected_agora()) {
// ************ FI

$ADMIN->add('reports', new admin_externalpage('reportstatus', get_string('pluginname', 'report_status'),
    "$CFG->wwwroot/report/status/index.php", 'report/status:view'));

$settings = null;

// XTEC ************ AFEGIT - Allow access only to xtecadmin user (report_status)
// 2021.07.14 @aginard
}
// ************ FI
