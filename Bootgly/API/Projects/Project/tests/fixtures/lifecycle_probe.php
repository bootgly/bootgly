<?php

use Bootgly\ABI\Events\Emitter;
use Bootgly\API\Projects\Project;
use Bootgly\API\Projects\Project\Events;

$root = getenv('BOOTGLY_LIFECYCLE_ROOT');
if ($root === false) {
   exit(2);
}

// This is an embedded fixture, not a Bootgly CLI command/script.
$_SERVER['SCRIPT_FILENAME'] = '';
require rtrim($root, '/') . '/autoboot.php';

$mode = (string) getenv('BOOTGLY_LIFECYCLE_MODE');

Emitter::$Instance->listen(Events::Boot, static function (): void {
   echo "boot-event\n";
});
Emitter::$Instance->listen(Events::Shutdown, static function (): void {
   echo "shutdown-event\n";
});

// @ A server-shaped closure never returns — it exits the process instead
$Project = new Project(
   boot: static function () use ($mode): void {
      echo "closure\n";
      if ($mode === 'exit') {
         exit(0);
      }
   },
   exportable: false,
   name: 'Lifecycle Sample'
);
$Project->boot();

// ? A handoff process suppresses the Shutdown announcement
if ($mode === 'detach') {
   $Project->detached = true;
}
