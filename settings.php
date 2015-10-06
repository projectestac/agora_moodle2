<?php
require_once(dirname(__FILE__) . '/local/agora/lib.php');

get_debug();

// Force general preferences. Prevailes over database params.
$CFG->isagora = 1;
$CFG->iseoi = false;
$CFG->isportal = false;
$CFG->center = isset($school_info['clientCode']) ? $school_info['clientCode'] : $school_info['id_moodle2'];

// The following line calculates correctly the diskPercent (uploading files will be disabled when diskPercent >= 100)
$CFG->diskPercent = isset($school_info['diskPercent_moodle2']) ? $school_info['diskPercent_moodle2'] : 0;
$CFG->userquota = 0;  // To avoid the private files area

$CFG->legacyfilesinnewcourses = false;
$CFG->updateautocheck = false;
$CFG->disableupdatenotifications = true;
$CFG->disableupdateautodeploy = true;
$CFG->disableonclickaddoninstall = true;
$CFG->updateminmaturity = 0;
$CFG->updatenotifybuilds = false;

//Preconfiguration setting
$CFG->alternateloginurl='';
$CFG->mymoodleredirect = false;
$CFG->enablestats = false;
$CFG->themedesignermode = false;
$CFG->cachejs = true;
$CFG->slasharguments = true;
//$CFG->loginhttps=0;  /* Database param, to change if there is some problem */

//Authentication
$CFG->recaptchapublickey=$agora['recaptchapublickey'];
$CFG->recaptchaprivatekey=$agora['recaptchaprivatekey'];

//Mail
$CFG->smtphosts = "";
$CFG->smtpmaxbulk = 20;
$CFG->noreplyaddress = 'noreply@agora.xtec.cat';
$CFG->digestmailtime = 1;
if ($CFG->iseoi) {
    $CFG->mailheader = '[Àgora-EOI]';
} else {
    $CFG->mailheader = '[Àgora]';
}

//Cleanup
$CFG->disablegradehistory = 1;
$CFG->loglifetime = 365 * 2;

//Rules
$CFG->forceloginforprofiles = 1;
$CFG->opentogoogle = 0;

//Ajax & Javascript
$CFG->enableajax = 1;
$CFG->disablecourseajax = 0;

//Backups
$CFG->backup_auto_active = 0;

//Session information
$CFG->session_handler_class = '\core\session\file';
$CFG->session_file_save_path = ini_get('session.save_path');
$CFG->sessiontimeout=3600;
$CFG->sessioncookie = $CFG->dbuser;

//$CFG->enable_hour_restrictions = 1;   /* Set in database */
// This param (hour_restrictions) can be serialized. This is useful for setting it in database
// Values for days: 0 = sunday, 1 = monday, ..., 6 = saturday
if ($CFG->iseoi) {
    $CFG->hour_restrictions = array(array('start' => '16:00', 'end' => '23:59', 'days' => '1|2|3|4|5'),
                                array('start' => '00:00', 'end' => '23:59', 'days' => '0|6'));
} else {
    $CFG->hour_restrictions = array(array('start' => '9:00', 'end' => '13:59', 'days' => '1|2|3|4|5'),
                                array('start' => '15:00', 'end' => '16:59', 'days' => '1|2|3|4|5'));
}

// These variable define DEFAULT block variables for new courses
$CFG->defaultblocks_override = ':calendar_month,activity_modules';

//Mail information
//$CFG->apligestmail = 1;          /* Set in database */
//$CFG->apligestlog = 0;        /* Set in database */
//$CFG->apligestlogdebug = 0;        /* Set in database */
//$CFG->apligestlogpath = $CFG->dataroot.'/repository/files/mailsender.log';
$CFG->apligestenv = $agora['server']['enviroment'];
if ($CFG->iseoi) {
    $CFG->apligestaplic = 'AGORAEOI';
} else {
    $CFG->apligestaplic = 'AGORA';
}

$CFG->langotherroot = dirname(__FILE__) . '/langpacks/';
$CFG->langlocalroot = dirname(__FILE__) . '/langpacks/';
$CFG->skiplangupgrade  = true;

// Only allow some of the languages
if (!$CFG->iseoi) {
    $CFG->langlist = 'ca,en,es,fr,de';
}

if(isset($agora['moodle2']['airnotifier'])) {
    $CFG->airnotifieraccesskey = $agora['moodle2']['airnotifier'];
}

// Path of the cacheconfig.php file, to have only one MUC file for Àgora (instead of having one for each site in moodledata/usuX/muc/config.php).
// This folder has to exists and to be writable
$CFG->altcacheconfigpath = dirname(__FILE__) . '/local/agora/muc/';
$CFG->siteidentifier = $CFG->dbuser;
$CFG->memcache_prefix = $CFG->dbuser.'_';
if (isset($agora['moodle2']['memcache_servers'])) {
    $CFG->memcache_servers = $agora['moodle2']['memcache_servers'];
} else {
    $CFG->memcache_servers = '127.0.0.1';
}
if (isset($agora['server']['root']) && !empty($agora['server']['root'])) {
    $CFG->agora_muc_path = $agora['server']['root'].'cache_ins/'.$CFG->dbuser;
    $CFG->cachedir = $CFG->agora_muc_path.'/cache';
    $CFG->localcachedir = $CFG->agora_muc_path.'/localcache';
}
// Change locking from NFS to DB
$CFG->lock_factory = "\\core\\lock\\db_record_lock_factory";

$CFG->timezone = 99; // Changed by default to Server's local time
$CFG->cronremotepassword = '';  // changed to avoid schools change it
$CFG->cronclionly = 1; // changed to avoid schools change it

/*if (isset($agora['proxy']['host']) && !empty($agora['proxy']['host'])) {
    $CFG->proxyhost = $agora['proxy']['host'];
    $CFG->proxyport = $agora['proxy']['port'];
    $CFG->proxyuser = $agora['proxy']['user'];
    $CFG->proxypassword = $agora['proxy']['pass'];
}*/


$CFG->mobilecssurl = $CFG->wwwroot.'/theme/xtec2/mobile/style.php';


$CFG->forced_plugin_settings = array('logstore_standard' => array('loglifetime' => 365 * 2),
                                     'logstore_legacy' => array('loglegacy' => 1),
                                     'filter_wiris' => array('uninstall' => 1),
                                     'backup' => array('loglifetime' => 7));

// Here is where the cronlogs will be stored
//$CFG->savecronlog = 1;  // This parámeter is saved on database to save cronlogs
