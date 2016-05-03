<?php

require_once(INSTALL_BASE.'/html/config/dblib-mysql.php');

global $school_info;
$centre = getSchoolInfo('moodle2');

if ($agora['server']['enviroment'] == 'FRM') {
    $currenthour = date('G');
    // Change id for usu1 for training environment if is an odd hour (usu1 regenerates every odd hour)
    if ($school_info['id_moodle2'] == 1 && $currenthour % 2 != 0) {
        $school_info['id_moodle2'] = 10000;
    }
}

$CFG->dbname    = $school_info['database_moodle2'];
$CFG->dbuser    = $agora['moodle2']['username'] . $school_info['id_moodle2'];
$CFG->wwwroot   = $agora['server']['server'] . $agora['server']['base'] . $centre . '/moodle';
//$CFG->dataroot  = INSTALL_BASE . '/'. $agora['moodle2']['datadir'] . $agora['moodle2']['username'] . $school_info['id_moodle2'];
$CFG->dataroot  = INSTALL_BASE . '/'. get_filepath_moodle();
$CFG->dnscentre = $centre;