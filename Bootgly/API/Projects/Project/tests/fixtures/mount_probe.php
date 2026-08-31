<?php

use Bootgly\ACI\Logs\Data\Record;
use Bootgly\API\Projects\Project;

$root = getenv('BOOTGLY_MOUNT_ROOT');
if ($root === false) {
   exit(2);
}

// This is an embedded fixture, not a Bootgly CLI command/script.
$_SERVER['SCRIPT_FILENAME'] = '';
require rtrim($root, '/') . '/autoboot.php';

// @ mount() prepares the environment WITHOUT running the entry closure
$Project = new Project(
   boot: static function (): void {
      echo 'entry-ran;';
   },
   exportable: false,
   name: 'Mount Sample',
   folder: 'Mount/Sample'
);
$Project->mount();

echo 'constant:' . (defined('BOOTGLY_PROJECT') && BOOTGLY_PROJECT === $Project ? 'yes' : 'no') . ';';
echo 'provenance:' . Record::$provenance . ';';

// @ A boot after mount must throw (once per process)
try {
   $Project->boot();
   echo 'reboot:allowed;';
}
catch (Error) {
   echo 'reboot:refused;';
}
