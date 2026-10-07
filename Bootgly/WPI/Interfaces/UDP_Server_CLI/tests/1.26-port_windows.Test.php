<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;


// ! Embedded fixture: reload() replays this exact file through pcntl_exec.
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--udp-port-windows'
) {
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   $port = (int) ($_SERVER['argv'][3] ?? 0);
   $state = (string) ($_SERVER['argv'][4] ?? '');
   $leg = (string) ($_SERVER['argv'][5] ?? '');
   $low = (int) ($_SERVER['argv'][6] ?? 0);
   if (
      realpath("{$root}/autoboot.php") === false
      || $port < 1024 || $port > 65535
      || $state === ''
      || in_array($leg, ['serve', 'reload', 'deny'], true) === false
      || ($leg === 'deny' && ($low < 1 || $low > 1023))
   ) {
      exit(2);
   }
   // ? The first launch leads a new session; the replayed image already
   //   leads it, so a refused setsid() is how the replay knows itself.
   $session = @posix_setsid();
   $replayed = $session === false || $session === -1;

   // ? The reload gap, made deterministic: before start(), the replay has a
   //   plain PHP UDP bind (it carries SO_REUSEADDR) take the port its
   //   stopped workers left, and holds it for 2.5 s.
   if ($replayed && $leg === 'reload') {
      $squatter = pcntl_fork();
      if ($squatter === 0) {
         // ! Leave the master: the squatter is never one of its children
         if (pcntl_fork() !== 0) {
            exit(0);
         }
         $Socket = @stream_socket_server("udp://127.0.0.1:{$port}", $code, $message, STREAM_SERVER_BIND);
         if ($Socket === false) {
            file_put_contents("{$state}.refused", (string) $message);
            exit(1);
         }
         file_put_contents("{$state}.ready", (string) hrtime(true));
         usleep(2_500_000);
         fclose($Socket);
         file_put_contents("{$state}.released", (string) hrtime(true));
         exit(0);
      }
      if ($squatter > 0) {
         pcntl_waitpid($squatter, $status);
      }
      // @@ Never call start() before the squatter holds the port
      $deadline = hrtime(true) + 3_000_000_000;
      clearstatcache();
      while (is_file("{$state}.ready") === false && hrtime(true) < $deadline) {
         usleep(5_000);
         clearstatcache();
      }
   }

   $_SERVER['SCRIPT_FILENAME'] = '';
   require "{$root}/autoboot.php";

   // ! A spec-only class: its state inodes are this spec's alone to remove
   final class PortWindowsProbe extends UDP_Server_CLI
   {
      public static function boot (mixed $Environment): void
      {
      }
   }

   // ? The replay of the `deny` leg binds a privileged port: its exclusive
   //   probe fails with EACCES, never EADDRINUSE
   $bound = $replayed && $leg === 'deny' ? $low : $port;

   $Server = new PortWindowsProbe(Modes::Foreground);
   $Server->configure(new Configs(host: '127.0.0.1', port: $bound, workers: 1));
   $Server->on(Events::DatagramReceive, static function (string $input): string {
      if ($input !== 'env') {
         return (string) getmypid();
      }

      // @ The reload marker as this worker sees it, and as a process it
      //   starts inherits it (that process's exec-time environment)
      $Child = proc_open(
         [PHP_BINARY, '-n', '-r', 'echo file_get_contents("/proc/self/environ");'],
         [1 => ['pipe', 'w']],
         $Pipes,
      );
      $environ = '';
      if (is_resource($Child)) {
         $environ = (string) stream_get_contents($Pipes[1]);
         fclose($Pipes[1]);
         proc_close($Child);
      }

      return (string) json_encode([
         'pid' => getmypid(),
         'marker' => getenv('BOOTGLY_UDP_RELOAD'),
         'spawned' => str_contains($environ, '='),
         'inherited' => in_array('BOOTGLY_UDP_RELOAD', array_map(
            static fn (string $entry): string => strstr($entry, '=', true) ?: $entry,
            explode("\0", $environ)
         ), true),
      ]);
   });
   $Server->start();
   exit(0);
}


/**
 * The port is empty in two windows: a refork after the only worker died, and
 * the reload gap between the stopped workers and the fresh image. A socket
 * that takes the port there is refused to the workers — loudly, with the
 * revive backoff — and the server serves again once it leaves; the reloaded
 * master survives the refusal, and only its own PID-bound marker makes a
 * refused start survivable. The marker never outlives start(): no worker and
 * no process a worker starts inherits it. And only a taken port (EADDRINUSE)
 * is survivable on a reload: any other refusal exits like a fresh start.
 */
return new Test(
   description: 'UDP-22: a squatter in the refork window or the reload gap is refused loudly, and the server recovers once it leaves',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('pcntl_fork') === false
      || function_exists('proc_open') === false
      || function_exists('socket_create') === false
      || is_readable('/proc/self/stat') === false,
   test: new Assertions(Case: function (): Generator {
      // ! The fixture state files live in storage/, which a fresh checkout lacks
      if (is_dir(BOOTGLY_STORAGE_DIR) === false) {
         @mkdir(BOOTGLY_STORAGE_DIR, 0755, true);
      }

      /** A free loopback UDP port. */
      $Reserve = static function (): int {
         $Reservation = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }

         return (int) substr($name, (int) strrpos($name, ':') + 1);
      };
      /** Remove the state inodes of $port, so no earlier run's leftovers are read as this run's. */
      $Purge = static function (int $port): void {
         foreach (['log', 'ready', 'released', 'refused'] as $suffix) {
            @unlink(BOOTGLY_STORAGE_DIR . "port-windows-{$port}.{$suffix}");
         }
         foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
            if (str_starts_with((string) $file, "PortWindowsProbe.{$port}.")) {
               @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
            }
         }
      };
      /** Launch the embedded fixture on a clean state: [process, master PID]. */
      $Launch = static function (int $port, string $leg, string $marker = '', int $low = 0) use ($Purge): array {
         $state = BOOTGLY_STORAGE_DIR . "port-windows-{$port}";
         $Purge($port);
         $environment = (array) getenv();
         unset($environment['BOOTGLY_UDP_RELOAD']);
         if ($marker !== '') {
            $environment['BOOTGLY_UDP_RELOAD'] = $marker;
         }
         $Process = proc_open(
            [PHP_BINARY, __FILE__, '--udp-port-windows', BOOTGLY_ROOT_BASE, (string) $port, $state, $leg, (string) $low],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$state}.log", 'w'], 2 => ['redirect', 1]],
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $environment,
         );

         return [$Process, is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0];
      };
      /** The fixture output on $port, without colors. */
      $Read = static function (int $port): string {
         $output = (string) @file_get_contents(BOOTGLY_STORAGE_DIR . "port-windows-{$port}.log");

         return (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
      };
      /** Whether $needle shows up in the output on $port within $seconds. */
      $Await = static function (int $port, string $needle, float $seconds) use ($Read): bool {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            if (str_contains($Read($port), $needle)) {
               return true;
            }
            usleep(20_000);
         } while (hrtime(true) < $deadline);

         return false;
      };
      /** Whether the state file "{$suffix}" of $port exists within $seconds. */
      $Find = static function (int $port, string $suffix, float $seconds): bool {
         $file = BOOTGLY_STORAGE_DIR . "port-windows-{$port}.{$suffix}";
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            clearstatcache();
            if (is_file($file)) {
               return true;
            }
            usleep(10_000);
         } while (hrtime(true) < $deadline);

         return false;
      };

      $Client = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
      stream_set_blocking($Client, false);
      /** The PID of the worker answering on $port within $seconds, or 0. */
      $Serve = static function (int $port, float $seconds) use ($Client): int {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            while (@stream_socket_recvfrom($Client, 65_535) !== false) {
               // drain late replies
            }
            stream_socket_sendto($Client, 'pid', 0, "127.0.0.1:{$port}");
            $read = [$Client];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 100_000) === 1) {
               $PID = (int) @stream_socket_recvfrom($Client, 65_535);
               if ($PID > 0) {
                  return $PID;
               }
            }
         } while (hrtime(true) < $deadline);

         return 0;
      };
      /**
       * The environment report of the worker answering on $port within $seconds, or null.
       *
       * @return null|array<string,mixed>
       */
      $Query = static function (int $port, float $seconds) use ($Client): null|array {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            while (@stream_socket_recvfrom($Client, 65_535) !== false) {
               // drain late replies
            }
            stream_socket_sendto($Client, 'env', 0, "127.0.0.1:{$port}");
            $read = [$Client];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 1, 0) === 1) {
               $report = json_decode((string) @stream_socket_recvfrom($Client, 65_535), true);
               if (is_array($report) && isSet($report['pid'])) {
                  return $report;
               }
            }
         } while (hrtime(true) < $deadline);

         return null;
      };
      /** The exit code of the launched fixture within $seconds, or null while it runs. */
      $Wait = static function (mixed $Process, float $seconds): null|int {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            $status = is_resource($Process) ? proc_get_status($Process) : ['running' => false, 'exitcode' => -1];
            if ($status['running'] === false) {
               return (int) $status['exitcode'];
            }
            usleep(20_000);
         } while (hrtime(true) < $deadline);

         return null;
      };
      /** Whether $PID exited (a zombie the stopped master has not reaped yet counts). */
      $Dead = static function (int $PID, float $seconds): bool {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            $stat = (string) @file_get_contents("/proc/{$PID}/stat");
            $state = substr($stat, (int) strrpos($stat, ')') + 2, 1);
            if ($PID > 0 && ($stat === '' || $state === 'Z')) {
               return true;
            }
            usleep(10_000);
         } while (hrtime(true) < $deadline);

         return false;
      };
      /** Tear one fixture down: its session group, its state inodes; true when nothing is left. */
      $Teardown = static function (mixed $Process, int $master, int $port) use ($Purge): bool {
         // ! Its own session group; never PID 0/-1 (this runner's group)
         if ($master > 0) {
            posix_kill(-$master, SIGCONT);
            posix_kill(-$master, SIGKILL);
         }
         if (is_resource($Process)) {
            proc_close($Process);
         }
         $cleanup = hrtime(true) + 1_000_000_000;
         while ($master > 0 && posix_kill(-$master, 0) && hrtime(true) < $cleanup) {
            usleep(10_000);
         }
         // @ This run's state inodes, by their literal prefix — a refused or
         //   killed master never reached its teardown
         $Purge($port);

         return $master > 0 && posix_kill(-$master, 0) === false;
      };

      // # W1 — refork window
      // @ Freeze the master, kill its only worker, take the empty port with a
      //   plain PHP bind (SO_REUSEADDR), thaw the master: the refork is refused
      $refork = [];
      $port = $Reserve();
      [$Process, $master] = $Launch($port, 'serve');
      $Squatter = false;
      try {
         $original = $Serve($port, 6.0);
         $refork['fixture serves'] = $original > 0;
         if ($original > 0 && $master > 0) {
            posix_kill($master, SIGSTOP);
            posix_kill($original, SIGKILL);
         }
         $refork['worker gone while the master is frozen'] = $original > 0 && $Dead($original, 2.0);
         $Squatter = @stream_socket_server("udp://127.0.0.1:{$port}", $code, $message, STREAM_SERVER_BIND);
         $refork['squatter bound in the empty port'] = is_resource($Squatter);
         if (is_resource($Squatter)) {
            stream_set_blocking($Squatter, false);
         }
         if ($master > 0) {
            posix_kill($master, SIGCONT);
         }
         $refork['refork refusal logged'] = $Await(
            $port,
            "Could not bind the UDP port {$port}: Address already in use — another socket holds it.",
            3.0
         );
         $refork['backoff logged'] = $Await($port, 'times in a row', 2.0);
         // @ While it holds: nothing of the server answers, the squatter gets it all
         $answered = $Serve($port, 1.0);
         $captured = 0;
         while (is_resource($Squatter) && @stream_socket_recvfrom($Squatter, 65_535) !== false) {
            $captured++;
         }
         $refork['server silent while the squatter holds'] = $answered === 0;
         $refork['squatter receives the datagrams'] = $captured > 0;
         // @ The squatter leaves: the backed-off slot serves within the cap
         if (is_resource($Squatter)) {
            fclose($Squatter);
         }
         $Squatter = false;
         $released = hrtime(true);
         $recovered = $Serve($port, 8.0);
         $refork['serves again within 8 s of the release'] = $recovered > 0
            && $recovered !== $original
            && hrtime(true) - $released <= 8_000_000_000;
         $refork['master alive'] = $master > 0 && is_resource($Process) && proc_get_status($Process)['running'];
      }
      finally {
         if (is_resource($Squatter)) {
            fclose($Squatter);
         }
         $refork['group clean'] = $Teardown($Process, $master, $port);
      }

      // # W2 — reload gap
      // @ The replayed image has a squatter take the port before start():
      //   the master warns, survives, and serves once the squatter leaves
      $reload = [];
      $marked = [];
      $port = $Reserve();
      [$Process, $master] = $Launch($port, 'reload');
      try {
         $original = $Serve($port, 6.0);
         $reload['fixture serves'] = $original > 0;
         if ($original > 0 && $master > 0) {
            posix_kill($master, SIGUSR2);
         }
         $reload['replay squatter holds the port'] = $original > 0 && $Find($port, 'ready', 6.0);
         $reload['reload warning logged'] = $Await(
            $port,
            "Reload: UDP port {$port} is held by another socket; the workers retry until they can bind it.",
            2.0
         );
         $reload['worker refusal logged'] = $Await(
            $port,
            "Could not bind the UDP port {$port}: Address already in use — another socket holds it.",
            2.0
         );
         $reload['master alive while the squatter holds'] = $master > 0
            && is_resource($Process)
            && proc_get_status($Process)['running'];
         $reload['squatter released'] = $Find($port, 'released', 5.0);
         $released = hrtime(true);
         $recovered = $Serve($port, 8.0);
         $reload['serves again within 8 s of the release'] = $recovered > 0
            && $recovered !== $original
            && hrtime(true) - $released <= 8_000_000_000;
         $reload['master alive'] = $master > 0 && is_resource($Process) && proc_get_status($Process)['running'];

         // # W4 — the reload marker is cleared at once
         // @ /proc/<pid>/environ cannot tell: unsetenv never rewrites the
         //   exec-time block, and the process title overwrites it. The
         //   reload warning proves the replayed image read its PID marker;
         //   the live environment is read where it matters — in the worker
         //   the reloaded master forked, and in a process that worker starts
         $marked['replayed image honored its PID marker'] = $reload['reload warning logged'];
         $report = $recovered > 0 ? $Query($port, 3.0) : null;
         $marked['worker of the reloaded master reports'] = is_array($report) && (int) $report['pid'] > 0;
         $marked['worker environment has no marker'] = is_array($report) && $report['marker'] === false;
         $marked['worker started a process'] = is_array($report) && $report['spawned'] === true;
         $marked['that process inherits no marker'] = is_array($report) && $report['inherited'] === false;
      }
      finally {
         $reload['group clean'] = $Teardown($Process, $master, $port);
      }

      // # W3 — a forged or stale reload marker
      // @ A fresh launch that carries a marker naming another PID is no
      //   reload: it still refuses the port a squatter holds
      $forged = [];
      $port = $Reserve();
      $Purge($port);
      $Squatter = @stream_socket_server("udp://127.0.0.1:{$port}", $code, $message, STREAM_SERVER_BIND);
      $forged['squatter bound first'] = is_resource($Squatter);
      [$Process, $master] = $Launch($port, 'serve', (string) posix_getpid());
      try {
         $forged['launch exits 1'] = $Wait($Process, 4.0) === 1;
         $output = $Read($port);
         $forged['refusal names the exclusive bind'] = str_contains(
            $output,
            "Could not bind to 127.0.0.1:{$port} exclusively"
         );
         $forged['no reload warning'] = $output !== '' && str_contains($output, 'Reload: UDP port') === false;
      }
      finally {
         if (is_resource($Squatter)) {
            fclose($Squatter);
         }
         $forged['group clean'] = $Teardown($Process, $master, $port);
      }

      // # W5 — a reload refused for another reason
      // @ The replayed image binds a privileged port: its exclusive probe
      //   fails with EACCES, and the master exits 1 like a fresh start — no
      //   reload warning, no worker ever forked into a crash loop
      // ! Precondition, computed from the host and asserted: an unprivileged
      //   account is refused a port below net.ipv4.ip_unprivileged_port_start
      //   (1024 when the kernel lacks the sysctl). Root, or a host that opens
      //   every port (a container's default), holds no such port.
      $first = trim((string) @file_get_contents('/proc/sys/net/ipv4/ip_unprivileged_port_start'));
      $first = $first === '' ? 1024 : (int) $first;
      $low = $first > 1 ? min(1023, $first - 1) : 1023;
      $privileged = posix_geteuid() !== 0 && $first > 1;
      $Gate = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
      $refused = false;
      if ($Gate !== false) {
         $refused = @socket_bind($Gate, '127.0.0.1', $low) === false
            && socket_last_error($Gate) === SOCKET_EACCES;
         socket_close($Gate);
      }
      $denied = ['privileged port refused to this account' => $refused];
      $expected = ['privileged port refused to this account' => $privileged];
      if ($refused) {
         $port = $Reserve();
         [$Process, $master] = $Launch($port, 'deny', '', $low);
         try {
            $original = $Serve($port, 6.0);
            $denied['fixture serves'] = $original > 0;
            if ($original > 0 && $master > 0) {
               posix_kill($master, SIGUSR2);
            }
            $denied['reloaded master exits 1'] = $original > 0 && $Wait($Process, 6.0) === 1;
            $output = $Read($port);
            $denied['refusal names the denied bind'] = str_contains(
               $output,
               "Could not bind to 127.0.0.1:{$low} exclusively: Permission denied."
            );
            $denied['privilege hint'] = str_contains($output, 'Ports below 1024 require elevated privileges.');
            $denied['no reload warning'] = $output !== '' && str_contains($output, 'Reload: UDP port') === false;
            $denied['no worker refusal'] = $output !== ''
               && str_contains($output, "Could not bind the UDP port {$low}:") === false;
         }
         finally {
            $denied['group clean'] = $Teardown($Process, $master, $port);
            // @ The replay qualified its state with the privileged port
            $Purge($low);
         }
         $expected += [
            'fixture serves' => true,
            'reloaded master exits 1' => true,
            'refusal names the denied bind' => true,
            'privilege hint' => true,
            'no reload warning' => true,
            'no worker refusal' => true,
            'group clean' => true,
         ];
      }
      fclose($Client);

      yield new Assertion(description: 'W1: a refork beside a squatter is refused loudly and serves after it leaves: ' . json_encode($refork))
         ->expect($refork, Op::Identical, [
            'fixture serves' => true,
            'worker gone while the master is frozen' => true,
            'squatter bound in the empty port' => true,
            'refork refusal logged' => true,
            'backoff logged' => true,
            'server silent while the squatter holds' => true,
            'squatter receives the datagrams' => true,
            'serves again within 8 s of the release' => true,
            'master alive' => true,
            'group clean' => true,
         ])
         ->assert();

      yield new Assertion(description: 'W2: a reload that finds the port taken warns, survives and serves after it leaves: ' . json_encode($reload))
         ->expect($reload, Op::Identical, [
            'fixture serves' => true,
            'replay squatter holds the port' => true,
            'reload warning logged' => true,
            'worker refusal logged' => true,
            'master alive while the squatter holds' => true,
            'squatter released' => true,
            'serves again within 8 s of the release' => true,
            'master alive' => true,
            'group clean' => true,
         ])
         ->assert();

      yield new Assertion(description: 'W3: a forged reload marker never excuses a squatter on a fresh launch: ' . json_encode($forged))
         ->expect($forged, Op::Identical, [
            'squatter bound first' => true,
            'launch exits 1' => true,
            'refusal names the exclusive bind' => true,
            'no reload warning' => true,
            'group clean' => true,
         ])
         ->assert();

      yield new Assertion(description: 'W4: the reload marker is cleared at once — no worker and no process it starts inherits it: ' . json_encode($marked))
         ->expect($marked, Op::Identical, [
            'replayed image honored its PID marker' => true,
            'worker of the reloaded master reports' => true,
            'worker environment has no marker' => true,
            'worker started a process' => true,
            'that process inherits no marker' => true,
         ])
         ->assert();

      yield new Assertion(description: "W5: a reload refused with an errno other than EADDRINUSE exits 1 (port {$low}, unprivileged from {$first}): " . json_encode($denied))
         ->expect($denied, Op::Identical, $expected)
         ->assert();
   }),
);
