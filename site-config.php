<?php

require_once(INSTALL_BASE . '/html/config/dblib-mysql.php');

global $school_info;

$centre = getSchoolInfo('moodle2');

if (isset($school_info['state_moodle2']) && ($school_info['state_moodle2'] == '-5')) {
    setcookie($agora['server']['cookie'], '', time(), '/'); // Force cookie to expire
    header('Location: ' . WWWROOT . 'error.php?s=moodle&migrating=' . $centre);
    exit();
}

if (isset($school_info['state_moodle2']) && ($school_info['state_moodle2'] == '-6')) {
    setcookie($agora['server']['cookie'], '', time(), '/'); // Force cookie to expire
    header('Location: ' . WWWROOT . 'error.php?s=moodle&migrated=' . $centre);
    exit();
}

if (isset($school_info['state_moodle2']) && ($school_info['state_moodle2'] == '-7') && ($school_info['id_moodle2'] != '1')) {
    setcookie($agora['server']['cookie'], '', time(), '/'); // Force cookie to expire
    header('Location: ' . WWWROOT . 'error.php?s=moodle&saturated=' . $centre);
    exit();
}

$CFG->dbhost = $school_info['dbhost_moodle2'];
$CFG->dbname = $agora['moodle2']['userprefix'] . $school_info['id_moodle2'];
$CFG->dbuser = $agora['moodle2']['userprefix'] . $school_info['id_moodle2'];

// Check for subdomain
if (!empty($school_info['url_type']) && ($school_info['url_type'] == 'subdomain') && !empty($school_info['url_host'])) {
    $CFG->wwwroot = $agora['server']['html'] . 'moodle';
} else {
    $CFG->wwwroot = $agora['server']['html'] . $centre . '/moodle';
}

$CFG->dataroot = INSTALL_BASE . '/' . get_filepath_moodle();
if (!empty($agora['server']['temp'])) {
    $CFG->tempdir = $agora['server']['temp'] . '/' . get_filepath_moodle();
}

$CFG->dnscentre = $centre;
