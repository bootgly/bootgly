<?php

use Bootgly\ACI\Logs\Data\Record;
use Bootgly\API\Projects\Project;

$root = getenv('BOOTGLY_PROVENANCE_ROOT');
if ($root === false) {
   exit(2);
}

// This is an embedded fixture, not a Bootgly CLI command/script.
$_SERVER['SCRIPT_FILENAME'] = '';
require rtrim($root, '/') . '/autoboot.php';

// ? Bare process: no project booted — echo the default provenance
if (getenv('BOOTGLY_PROVENANCE_BOOT') !== '1') {
   echo Record::$provenance;
   exit(0);
}

// @ Booted process: the boot closure runs after the provenance stamp — the
//   canonical folder id, exactly what `logs --project` addresses
$Project = new Project(
   boot: static function (): void {
      echo Record::$provenance;
   },
   exportable: false,
   name: 'Prov Sample',
   folder: 'Prov/Sample'
);
$Project->boot();
