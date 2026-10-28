<?php

use Bootgly\ACI\Process\State;
use Bootgly\API\Projects;

$root = getenv('BOOTGLY_MASTER_PROBE_ROOT');
$base = getenv('BOOTGLY_MASTER_PROBE_BASE');
$port = getenv('BOOTGLY_MASTER_PROBE_PORT') ?: '9001';
// ! Optional: the Darwin stamp proof forced on ('1') or off ('0') — unset
//   keeps the OS default —, a file recording SIGUSR2, a worker keeping the
//   inherited lock for N seconds, a master (and worker) deaf to SIGTERM, and
//   a worker that, once SIGTERM reaches it, spoils the lock's 0600 mode — no
//   proof passes from then on, the way a `ps` that cannot run fails it. A
//   `stop` signals workers only after it froze their master.
$stamped = getenv('BOOTGLY_MASTER_PROBE_STAMPED');
$marker = getenv('BOOTGLY_MASTER_PROBE_MARKER') ?: '';
$hold = (int) (getenv('BOOTGLY_MASTER_PROBE_WORKER') ?: 0);
$deaf = getenv('BOOTGLY_MASTER_PROBE_DEAF') === '1';
$spoil = getenv('BOOTGLY_MASTER_PROBE_SPOIL') === '1';
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

// ! The Darwin proof, forced: the lock this stand-in takes carries its stamp
//   (or, forced off, carries none)
if ($stamped === '1' || $stamped === '0') {
   State::$stamped = $stamped === '1';
}

// ! The stand-in must NOT stay a child of the test runner: an unreaped child
//   is a zombie, and a zombie still answers a signal-0 probe, so `stop`
//   would count it as a survivor. Detach it to init the way a daemon is.
$PID = pcntl_fork();
if ($PID === -1) {
   exit(3);
}
if ($PID > 0) {
   // @ Launcher: wait for the detached master to publish, hand its PID (and
   //   its worker's, 0 without one) over
   $State = new State(Projects::encode('Scratch'), $port);
   for ($i = 0; $i < 50; $i++) {
      $data = $State->read();
      if (is_array($data) && ($data['master'] ?? 0) === $PID) {
         $worker = (int) ($data['workers'][0] ?? 0);
         echo "ready {$PID} {$worker}\n";
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

// @ A worker: a direct child keeping the inherited lock descriptor — the way
//   a server's workers do — for a bounded time, then gone without running
//   any shutdown handler
$worker = 0;
if ($hold > 0) {
   $worker = pcntl_fork();
   if ($worker === 0) {
      if ($spoil) {
         pcntl_async_signals(true);
         pcntl_signal(SIGTERM, static function () use ($State): void {
            @chmod($State->pidLockFile, 0644);
         });
      }
      else if ($deaf) {
         pcntl_signal(SIGTERM, SIG_IGN);
      }
      // @@ Hold the lock — a handled signal never cuts the wait short
      $until = time() + $hold;
      while (time() < $until) {
         usleep(100000);
      }
      posix_kill(posix_getpid(), SIGKILL);
   }
}

$State->save([
   'master' => posix_getpid(),
   'workers' => $worker > 0 ? [$worker] : [],
   'host' => '0.0.0.0',
   'port' => (int) $port,
   'started' => time(),
   'status' => 'Running',
   'type' => 'WPI',
]);

// @ Signals: a reload is recorded; SIGTERM keeps its default disposition
//   unless the master is deaf, or must take its worker down first
if ($marker !== '') {
   pcntl_signal(SIGUSR2, static function () use ($marker): void {
      file_put_contents($marker, 'SIGUSR2');
   });
}
if ($deaf) {
   pcntl_signal(SIGTERM, SIG_IGN);
}
else if ($worker > 0) {
   pcntl_signal(SIGTERM, static function () use ($worker): void {
      posix_kill($worker, SIGTERM);
      pcntl_waitpid($worker, $status);
      posix_kill(posix_getpid(), SIGKILL);
   });
}

// @ Wait to be stopped — never outlive the case that spawned it by much
$until = time() + 15;
while (time() < $until) {
   usleep(100000);
   pcntl_signal_dispatch();
}
