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
 * Import backup file or select existing backup file from moodle
 * @package   moodlecore
 * @copyright 2010 Dongsheng Cai <dongsheng@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../config.php');
require_once(__DIR__ . '/restorefile_form.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

// current context
$contextid = required_param('contextid', PARAM_INT);
$filecontextid = optional_param('filecontextid', 0, PARAM_INT);
// action
$action = optional_param('action', '', PARAM_ALPHA);
// file parameters
// non js interface may require these parameters
$component  = optional_param('component', null, PARAM_COMPONENT);
$filearea   = optional_param('filearea', null, PARAM_AREA);
$itemid     = optional_param('itemid', null, PARAM_INT);
$filepath   = optional_param('filepath', null, PARAM_PATH);
$filename   = optional_param('filename', null, PARAM_FILE);

list($context, $course, $cm) = get_context_info_array($contextid);

// will be used when restore
if (!empty($filecontextid)) {
    $filecontext = context::instance_by_id($filecontextid);
}

$url = new moodle_url('/backup/restorefile.php', array('contextid'=>$contextid));

$PAGE->set_url($url);
$PAGE->set_context($context);

switch ($context->contextlevel) {
    case CONTEXT_COURSECAT:
        core_course_category::page_setup();
        break;
    case CONTEXT_MODULE:
        $PAGE->set_heading(get_string('restoreactivity', 'backup'));
        break;
    case CONTEXT_COURSE:
        $course = get_course($context->instanceid);
        $PAGE->set_heading($course->fullname);
        $PAGE->set_secondary_active_tab('coursereuse');
        break;
    default:
        $PAGE->set_heading($SITE->fullname);
}


require_login($course, false, $cm);
require_capability('moodle/restore:restorecourse', $context);

if (is_null($course)) {
    $courseid = 0;
    $coursefullname = $SITE->fullname;
} else {
    $courseid = $course->id;
    $coursefullname = $course->fullname;
}

$browser = get_file_browser();

// check if tmp dir exists
$tmpdir = make_backup_temp_directory('', false);
if (!check_dir_exists($tmpdir, true, true)) {
    throw new restore_controller_exception('cannot_create_backup_temp_dir');
}

// choose the backup file from backup files tree
if ($action == 'choosebackupfile') {
    if ($filearea == 'automated') {
        require_capability('moodle/restore:viewautomatedfilearea', $context);
    }
    if ($fileinfo = $browser->get_file_info($filecontext, $component, $filearea, $itemid, $filepath, $filename)) {
        if (is_a($fileinfo, 'file_info_stored')) {
            // Use the contenthash rather than copying the file where possible,
            // to improve performance and avoid timeouts with large files.
            $fs = get_file_storage();
            $params = $fileinfo->get_params();
            $file = $fs->get_file($params['contextid'], $params['component'], $params['filearea'],
                    $params['itemid'], $params['filepath'], $params['filename']);
            $restore_url = new moodle_url('/backup/restore.php', array('contextid' => $contextid,
                    'pathnamehash' => $file->get_pathnamehash(), 'contenthash' => $file->get_contenthash()));
        } else {
            // If it's some weird other kind of file then use old code.
            $filename = restore_controller::get_tempdir_name($courseid, $USER->id);
            $pathname = $tmpdir . '/' . $filename;
            if (!$fileinfo->copy_to_pathname($pathname)) {
                throw new restore_ui_exception('errorcopyingbackupfile', null, $pathname);
            }
            $restore_url = new moodle_url('/backup/restore.php', array(
                    'contextid' => $contextid, 'filename' => $filename));
        }
        redirect($restore_url);
    } else {
        redirect($url, get_string('filenotfound', 'error'));
    }
    die;
}

$PAGE->set_title(get_string('course') . ': ' . $coursefullname);
$PAGE->set_pagelayout('admin');
$PAGE->activityheader->disable();
$PAGE->requires->js_call_amd('core_backup/async_backup', 'asyncBackupAllStatus', array($context->id));

// XTEC ************ AFEGIT - Control backup hours
// 2013.04.24 @aginard
if (!get_protected_agora() && is_rush_hour()) {
    print_error('rush_hour', 'local_agora', $CFG->wwwroot . '/course/view.php?id=' . $course->id);
}
// ************ FI

$form = new course_restore_form(null, array('contextid'=>$contextid));
$data = $form->get_data();
if ($data && has_capability('moodle/restore:uploadfile', $context)) {
    $filename = restore_controller::get_tempdir_name($courseid, $USER->id);
    $pathname = $tmpdir . '/' . $filename;
    if (!$form->save_file('backupfile', $pathname)) {
        throw new restore_ui_exception('errorcopyingbackupfile', null, $pathname);
    }
    $restore_url = new moodle_url('/backup/restore.php', array('contextid'=>$contextid, 'filename'=>$filename));
    redirect($restore_url);
    die;
}

echo $OUTPUT->header();
\backup_helper::print_coursereuse_selector('restore');
echo html_writer::tag('div', get_string('restoreinfo'), ['class' => 'pb-3']);

// require uploadfile cap to use file picker
if (has_capability('moodle/restore:uploadfile', $context)) {
    echo $OUTPUT->heading(get_string('importfile', 'backup'));
    echo $OUTPUT->container_start();
    $form->display();
    echo $OUTPUT->container_end();
}

// Activity backup area.
if ($context->contextlevel == CONTEXT_MODULE) {
    $treeviewoptions = [
        'filecontext' => $context,
        'currentcontext' => $context,
        'component' => 'backup',
        'context' => $context,
        'filearea' => 'activity',
    ];
    $renderer = $PAGE->get_renderer('core', 'backup');
    echo $renderer->backup_files_viewer($treeviewoptions);
    // Update the course context with the proper value, because $context contains the module context.
    $coursecontext = \context_course::instance($course->id);
} else {
    $coursecontext = $context;
}

// Course backup area.
$treeviewoptions = [
    'filecontext' => $coursecontext,
    'currentcontext' => $context,
    'component' => 'backup',
    'context' => $context,
    'filearea' => 'course',
];
$renderer = $PAGE->get_renderer('core', 'backup');
echo $renderer->backup_files_viewer($treeviewoptions);

// Private backup area.
$usercontext = context_user::instance($USER->id);
$treeviewoptions = [
    'filecontext' => $usercontext,
    'currentcontext' => $context,
    'component' => 'user',
    'context' => 'backup',
    'filearea' => 'backup',
];
$renderer = $PAGE->get_renderer('core', 'backup');
echo $renderer->backup_files_viewer($treeviewoptions);

// Automated backup area.
$automatedbackups = get_config('backup', 'backup_auto_active');
if (!empty($automatedbackups)) {
    $treeviewoptions = [
        'filecontext' => $context,
        'currentcontext' => $context,
        'component' => 'backup',
        'context' => $context,
        'filearea' => 'automated',
    ];
    $renderer = $PAGE->get_renderer('core', 'backup');
    echo $renderer->backup_files_viewer($treeviewoptions);
}

// In progress course restores.
if (async_helper::is_async_enabled()) {
    echo $OUTPUT->heading_with_help(
        get_string('asyncrestoreinprogress', 'backup'),
        'asyncrestoreinprogress',
        'backup',
        classnames: ['mt-6']
    );
    echo $OUTPUT->container_start();
    $renderer = $PAGE->get_renderer('core', 'backup');
    echo $renderer->restore_progress_viewer($USER->id, $context);
    echo $OUTPUT->container_end();
}

echo $OUTPUT->footer();
