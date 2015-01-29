<?php

require_once(INSTALL_BASE.'/html/config/dblib-mysql.php');

global $school_info;
$centre = getSchoolInfo('moodle2');

// Get the correct domain for the school (it's different if the school uses marsupial modules)
$CFG->ismarsupial = isset($school_info['is_marsupial']) && $school_info['is_marsupial'];

if (isset($CFG->ismarsupial) && $CFG->ismarsupial) {
    $moodle_wwwserver = $agora['server']['marsupial'];
} else {
    $moodle_wwwserver = $agora['server']['server'];
}

 $moodle_wwwroot = $moodle_wwwserver . $agora['server']['base'];

// Check if the domain is not the correct one and move if it isn't
if (!defined('CLI_SCRIPT') && endsWith($moodle_wwwserver, $_SERVER['HTTP_HOST']) === false) {
    $location = $moodle_wwwserver.$_SERVER['REQUEST_URI'];
    header ('HTTP/1.1 301 Moved Permanently');
    header ('Location: '.$location);
    exit;
}

if ($agora['server']['enviroment'] == 'FRM') {
    $currenthour = date('G');
    // Change id for usu1 for training environment if is an odd hour (usu1 regenerates every odd hour)
    if ($school_info['id_moodle2'] == 1 && $currenthour % 2 != 0) {
        $school_info['id_moodle2'] = 10000;
    }
}

$CFG->dbname    = $school_info['database_moodle2'];
$CFG->dbuser    = $agora['moodle2']['username'] . $school_info['id_moodle2'];
$CFG->wwwroot   = $moodle_wwwroot.$centre . '/moodle';
$CFG->dataroot  = INSTALL_BASE . '/'. $agora['moodle2']['datadir'] . $agora['moodle2']['username'] . $school_info['id_moodle2'];
$CFG->dnscentre = $centre;
