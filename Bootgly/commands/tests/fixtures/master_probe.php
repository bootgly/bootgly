<?php

use Bootgly\ACI\Process\State;
use Bootgly\API\Projects;

$root = getenv('BOOTGLY_MASTER_PROBE_ROOT');
$base = getenv('BOOTGLY_MASTER_PROBE_BASE');
$port = getenv('BOOTGLY_MASTER_PROBE_PORT') ?: '9001';
if ($root === false || $base === false) {
   exit(2);
}

// ! Point the working/consumer side at the scratch base BEFORE autoboot
define('BOOTGLY_WORKING_BASE', $base);
define('BOOTGLY_WORKING_DIR', "$base/");
define('BOOTGLY_STORAGE_BASE', "$base/storage");

// This is an embedded fixture, not a Bootgly CLI command/script.
$_SERVER['SCRIPT_FILENAME'] = '';
require rtrim($root, '/') . '/autoboot.php';

// ! The stand-in must NOT stay a child of the test runner: an unreaped child
//   is a zombie, and a zombie still answers a signal-0 probe, so `stop`
//   would count it as a survivor. Detach it to init the way a daemon is.
$PID = pcntl_fork();
if ($PID === -1) {
   exit(3);
}
if ($PID > 0) {
   // @ Launcher: wait for the detached master to publish, hand its PID over
   $State = new State(Projects::encode('Scratch'), $port);
   for ($i = 0; $i < 50; $i++) {
      $data = $State->read();
      if (is_array($data) && ($data['master'] ?? 0) === $PID) {
         echo "ready {$PID}\n";
         exit(0);
      }
      usleep(100000);
   }
   // ? Never leave a detached master behind on a failed handshake
   posix_kill($PID, 9);
   exit(4);
}

// @ Detached master: own a session, hold the qualified instance lock and
//   publish a state document naming THIS process, exactly as start() does
posix_setsid();
$State = new State(Projects::encode('Scratch'), $port);
if ($State->lock(LOCK_EX | LOCK_NB) === false) {
   exit(5);
}
$State->save([
   'master' => posix_getpid(),
   'workers' => [],
   'host' => '0.0.0.0',
   'port' => (int) $port,
   'started' => time(),
   'status' => 'Running',
   'type' => 'WPI',
]);

// @ Wait to be stopped — SIGTERM keeps its default disposition; never
//   outlive the case that spawned it by much
sleep(15);
