<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Process\State;
use Bootgly\API\Projects;

$root = getenv('BOOTGLY_RETIREE_PROBE_ROOT');
$base = getenv('BOOTGLY_RETIREE_PROBE_BASE');
$port = getenv('BOOTGLY_RETIREE_PROBE_PORT') ?: '9011';
if ($root === false || $base === false) {
   exit(2);
}

// ! Point the working/consumer side at the scratch base BEFORE autoboot
define('BOOTGLY_WORKING_BASE', $base);
define('BOOTGLY_WORKING_DIR', "$base/");
define('BOOTGLY_STORAGE_BASE', "$base/storage");

// This is an embedded fixture, not a Bootgly CLI command/script.
$_SERVER['SCRIPT_FILENAME'] = '';
$root = rtrim($root, '/');
require "{$root}/autoboot.php";

// ! The stand-in must NOT stay a child of the test runner: an unreaped child
//   is a zombie, and a zombie still answers a signal-0 probe, so `stop`
//   would count it as a survivor. Detach it to init the way a daemon is.
$PID = pcntl_fork();
if ($PID === -1) {
   exit(3);
}
if ($PID > 0) {
   // @ Launcher: wait for the detached master to publish its retiree, hand
   //   both PIDs over
   $State = new State(Projects::encode('Scratch'), $port);
   for ($i = 0; $i < 50; $i++) {
      $data = $State->read();
      if (is_array($data) && ($data['master'] ?? 0) === $PID && ($data['retirees'] ?? []) !== []) {
         $retiree = (int) $data['retirees'][0];
         echo "ready {$PID} {$retiree}\n";
         exit(0);
      }
      usleep(100000);
   }
   // ? Never leave a detached master behind on a failed handshake
   posix_kill($PID, 9);
   exit(4);
}

// @ Detached master: own a session, hold the qualified instance lock
posix_setsid();
$State = new State(Projects::encode('Scratch'), $port);
if ($State->lock(LOCK_EX | LOCK_NB) === false) {
   exit(5);
}

// @ Its retiree — a worker still draining a recycle: a child that holds the
//   inherited lock, out of this process's shutdown functions
$Retiree = pcntl_fork();
if ($Retiree === -1) {
   exit(6);
}
if ($Retiree === 0) {
   pcntl_exec('/bin/sh', ['-c', 'exec sleep 30']);
   exit(1);
}

// ! A master that never answers SIGTERM: the stop has to freeze it and end
//   its children itself
pcntl_signal(SIGTERM, SIG_IGN);

// @ Publish the state document naming THIS process and its retiree, exactly
//   as start() does
$State->save([
   'master' => posix_getpid(),
   'workers' => [],
   'retirees' => [$Retiree],
   'host' => '0.0.0.0',
   'port' => (int) $port,
   'started' => time(),
   'status' => 'Running',
   'type' => 'WPI',
]);

// @ Wait to be killed — never outlive the case that spawned it by much
sleep(20);
posix_kill($Retiree, 9);
