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


/**
 * Another account can neither take a running UDP server's port nor be
 * started on top of: the intruder runs under a different host uid with no
 * capability, while a fixture server runs as this runner.
 *
 *   A) steady: plain wildcard, plain loopback and `so_reuseport` binds on the
 *      server's port are all refused, the intruder captures nothing and the
 *      server answers 20/20 while it tries;
 *   B) squatter first: the other account binds the port, then a launch is
 *      refused with the exclusive message and exits 1, and the squatter still
 *      captures 5/5 datagrams sent to the port.
 *
 * As root (the CI image) the intruder drops to uid/gid 65534 itself. Otherwise
 * it runs through `unshare --user --map-auto --setuid 1000` (newuidmap and a
 * subordinate uid range): another host uid, CapEff 0. Without either the case
 * is skipped; a lane that must have the proof sets
 * `BOOTGLY_REQUIRE_ROOT_LEGS=1`, which turns the skip into a failure.
 */
$root = function_exists('posix_getuid') && posix_getuid() === 0;
$namespaced = $root === false
   && function_exists('proc_open')
   && function_exists('shell_exec')
   && trim((string) shell_exec('command -v unshare 2>/dev/null')) !== ''
   && trim((string) shell_exec('command -v newuidmap 2>/dev/null')) !== ''
   && (int) trim((string) shell_exec('grep -c "^$(id -un):" /etc/subuid 2>/dev/null')) > 0
   && trim((string) shell_exec('unshare --user --map-auto --setuid 1000 true >/dev/null 2>&1 && echo yes')) === 'yes';
$required = getenv('BOOTGLY_REQUIRE_ROOT_LEGS') === '1';

return new Test(
   description: 'UDP-22: another account can neither bind a running UDP server\'s port nor have a launch start on top of it',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false
      || ($root === false && $namespaced === false && $required === false),
   test: new Assertions(Case: function () use ($root, $namespaced): Generator {
      // ? Neither root nor a user namespace here, and the lane demands the legs
      if ($root === false && $namespaced === false) {
         yield new Assertion(
            description: 'cross-uid legs NOT run — neither root nor an unprivileged user namespace (unshare --map-auto, newuidmap, /etc/subuid) — and BOOTGLY_REQUIRE_ROOT_LEGS=1 demands them'
         )
            ->expect('unavailable', Op::Identical, 'available')
            ->assert();

         return;
      }

      // ! The fixture server: its own session, two SO_REUSEPORT siblings
      $Fixture = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('UDP_CROSS_AUTOBOOT');

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;

final class CrossProbe extends UDP_Server_CLI
{
   public static function boot (mixed $Environment): void
   {
   }
}

$Server = new CrossProbe(Modes::Foreground);
$Server->configure(new Configs(
   host: '127.0.0.1',
   port: (int) getenv('UDP_CROSS_PORT'),
   workers: 2,
));
$Server->on(Events::DatagramReceive, static function (string $input): string {
   return (string) getmypid();
});
$Server->start();
PHP;
      // ! The intruder: another account with no capability, holding every bind
      //   it gets until the runner closes its STDIN
      $Intruder = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}
// ? The root leg becomes another account itself
$uid = (int) getenv('UDP_CROSS_UID');
if ($uid > 0 && (posix_setgid($uid) === false || posix_setuid($uid) === false)) {
   exit(124);
}

$status = (string) @file_get_contents('/proc/self/status');
preg_match('/^Uid:\s+(\d+)/m', $status, $identity);
preg_match('/^CapEff:\s+([0-9a-f]+)/m', $status, $capabilities);
$port = (int) getenv('UDP_CROSS_PORT');
$attempts = getenv('UDP_CROSS_MODE') === 'squat'
   ? ['loopback' => ['127.0.0.1', []]]
   : [
      'wildcard' => ['0.0.0.0', []],
      'loopback' => ['127.0.0.1', []],
      'reuseport' => ['127.0.0.1', ['so_reuseport' => true]],
   ];

// @ Every bind kind on the server's port
$Sockets = [];
$binds = [];
foreach ($attempts as $kind => [$host, $options]) {
   $Socket = @stream_socket_server(
      "udp://{$host}:{$port}",
      $code,
      $message,
      STREAM_SERVER_BIND,
      stream_context_create(['socket' => $options])
   );
   $binds[$kind] = $Socket !== false;
   if ($Socket !== false) {
      stream_set_blocking($Socket, false);
      $Sockets[] = $Socket;
   }
}
echo json_encode([
   'uid' => (int) ($identity[1] ?? -1),
   'capabilities' => (string) ($capabilities[1] ?? '?'),
   'binds' => $binds,
]), "\n";

// @@ Hold the binds; count what they take until STDIN closes
$captured = 0;
$deadline = hrtime(true) + 20_000_000_000;
while (hrtime(true) < $deadline) {
   $Read = [...$Sockets, STDIN];
   $Write = null;
   $Except = null;
   if (@stream_select($Read, $Write, $Except, 0, 100_000) < 1) {
      continue;
   }
   foreach ($Read as $Ready) {
      if ($Ready === STDIN) {
         if ((string) fread(STDIN, 1) === '' && feof(STDIN)) {
            break 2;
         }
         continue;
      }
      while (@stream_socket_recvfrom($Ready, 65_535) !== false) {
         $captured++;
      }
   }
}
echo json_encode(['captured' => $captured]), "\n";
PHP;

      /** A free loopback UDP port. */
      $Reserve = static function (): int {
         $Reservation = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }

         return (int) substr($name, (int) strrpos($name, ':') + 1);
      };
      /** One JSON line from a nonblocking $Pipe within $seconds, or null. */
      $Read = static function (mixed $Pipe, float $seconds): null|array {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         $line = '';
         do {
            $chunk = is_resource($Pipe) ? fgets($Pipe) : false;
            if ($chunk === false) {
               usleep(10_000);
               continue;
            }
            $line .= $chunk;
            if (str_ends_with($line, "\n")) {
               $data = json_decode($line, true);

               return is_array($data) ? $data : null;
            }
         } while (hrtime(true) < $deadline);

         return null;
      };
      /** Launch the fixture on $port: [process, master PID, pipes]. */
      $Launch = static function (int $port) use ($Fixture): array {
         $Environment = (array) getenv();
         $Environment['UDP_CROSS_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $Environment['UDP_CROSS_PORT'] = (string) $port;
         $Process = proc_open(
            [PHP_BINARY, '-r', $Fixture],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $Environment,
         );
         foreach ($Pipes as $Pipe) {
            stream_set_blocking($Pipe, false);
         }

         return [$Process, is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0, $Pipes];
      };
      /** Launch the intruder in $mode on $port: [process, PID, pipes, report, host uid]. */
      $Intrude = static function (string $mode, int $port) use ($root, $Intruder, $Read): array {
         $command = $root
            ? [PHP_BINARY, '-r', $Intruder]
            : ['unshare', '--user', '--map-auto', '--setuid', '1000', PHP_BINARY, '-r', $Intruder];
         $Environment = (array) getenv();
         $Environment['UDP_CROSS_UID'] = $root ? '65534' : '0';
         $Environment['UDP_CROSS_MODE'] = $mode;
         $Environment['UDP_CROSS_PORT'] = (string) $port;
         $Process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $Pipes,
            '/',
            $Environment,
         );
         $PID = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
         if (isSet($Pipes[1])) {
            stream_set_blocking($Pipes[1], false);
         }
         // ! Its report: the binds it got, its own uid and CapEff
         $report = $Read($Pipes[1] ?? null, 5.0);
         // ! Its host uid, as this runner sees it
         $host = -1;
         $status = $PID > 0 ? (string) @file_get_contents("/proc/{$PID}/status") : '';
         if (preg_match('/^Uid:\s+(\d+)/m', $status, $Match) === 1) {
            $host = (int) $Match[1];
         }

         return [$Process, $PID, $Pipes, $report, $host];
      };
      /** Close the intruder's STDIN: its capture count, or null. */
      $Release = static function (array $Pipes) use ($Read): null|int {
         if (isSet($Pipes[0]) && is_resource($Pipes[0])) {
            fclose($Pipes[0]);
         }
         $report = $Read($Pipes[1] ?? null, 3.0);

         return is_array($report) && isSet($report['captured']) ? (int) $report['captured'] : null;
      };
      $Client = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
      stream_set_blocking($Client, false);
      /** Replies to $count datagrams sent one at a time to $port. */
      $Ask = static function (int $port, int $count, float $wait) use ($Client): int {
         $replies = 0;
         for ($sent = 0; $sent < $count; $sent++) {
            // @@ Drain late replies
            while (@stream_socket_recvfrom($Client, 65_535) !== false) {
               continue;
            }
            stream_socket_sendto($Client, 'pid', 0, "127.0.0.1:{$port}");
            $Ready = [$Client];
            $Write = null;
            $Except = null;
            if (
               @stream_select($Ready, $Write, $Except, 0, (int) ($wait * 1e6)) === 1
               && (int) @stream_socket_recvfrom($Client, 65_535) > 0
            ) {
               $replies++;
            }
         }

         return $replies;
      };
      /** Whether $port answers within $seconds. */
      $Serving = static function (int $port, float $seconds) use ($Ask): bool {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            if ($Ask($port, 1, 0.2) === 1) {
               return true;
            }
         } while (hrtime(true) < $deadline);

         return false;
      };
      /** Kill one process group and close its process: true when nothing is left. */
      $Teardown = static function (mixed $Process, int $group, array $Pipes): bool {
         // @ Its own session (never PID 0/-1: this runner's group), then the
         //   leader itself, in case it never reached posix_setsid()
         if ($group > 0) {
            @posix_kill(-$group, SIGKILL);
            @posix_kill($group, SIGKILL);
         }
         foreach ($Pipes as $Pipe) {
            if (is_resource($Pipe)) {
               @fclose($Pipe);
            }
         }
         if (is_resource($Process)) {
            proc_close($Process);
         }
         $cleanup = hrtime(true) + 1_000_000_000;
         while ($group > 0 && @posix_kill(-$group, 0) && hrtime(true) < $cleanup) {
            usleep(10_000);
         }

         return $group > 0 && @posix_kill(-$group, 0) === false;
      };
      /** This run's state inodes, by their literal prefix. */
      $Purge = static function (int $port): void {
         foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
            if (str_starts_with((string) $file, "CrossProbe.{$port}.")) {
               @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
            }
         }
      };

      $runner = posix_getuid();
      $Observed = [];
      $evidence = ['path' => $root ? 'root: setgid/setuid 65534' : 'unshare --user --map-auto --setuid 1000'];

      // @ A) steady: the other account tries every bind on a running server
      $port = $Reserve();
      [$Server, $master, $ServerPipes] = $Launch($port);
      $Process = null;
      $intruder = 0;
      $IntruderPipes = [];
      try {
         $Observed['A: fixture serves'] = $port > 0 && $master > 0 && $Serving($port, 6.0);
         [$Process, $intruder, $IntruderPipes, $report, $host] = $Intrude('steady', $port);
         $evidence['A'] = ['host uid' => $host, 'report' => $report];
         $Observed['A: intruder is another account'] = $host >= 0 && $host !== $runner;
         $Observed['A: intruder CapEff'] = $report['capabilities'] ?? null;
         $Observed['A: intruder binds'] = $report['binds'] ?? null;
         $Observed['A: server answers while the intruder tries'] = $Ask($port, 20, 0.3);
         $Observed['A: intruder captured'] = $Release($IntruderPipes);
      }
      finally {
         // ! Both groups, whatever the first one left
         $clean = $Teardown($Process, $intruder, $IntruderPipes);
         $Observed['A: groups clean'] = $Teardown($Server, $master, $ServerPipes) && $clean;
         $Purge($port);
      }

      // @ B) squatter first: the other account holds the port, then a launch
      $port = $Reserve();
      [$Process, $intruder, $IntruderPipes, $report, $host] = $Intrude('squat', $port);
      $Server = null;
      $master = 0;
      $ServerPipes = [];
      try {
         $evidence['B'] = ['host uid' => $host, 'report' => $report];
         $Observed['B: squatter is another account'] = $port > 0 && $host >= 0 && $host !== $runner;
         $Observed['B: squatter CapEff'] = $report['capabilities'] ?? null;
         $Observed['B: squatter binds'] = $report['binds'] ?? null;

         [$Server, $master, $ServerPipes] = $Launch($port);
         // @ The launch ends on its own within 5 s, or it is on top of the squatter
         $exit = null;
         $output = '';
         $deadline = hrtime(true) + 5_000_000_000;
         while ($exit === null && is_resource($Server) && hrtime(true) < $deadline) {
            $output .= (string) stream_get_contents($ServerPipes[1]) . (string) stream_get_contents($ServerPipes[2]);
            $state = proc_get_status($Server);
            if ($state['running'] === false) {
               $exit = (int) $state['exitcode'];
               break;
            }
            usleep(20_000);
         }
         $output .= (string) stream_get_contents($ServerPipes[1]) . (string) stream_get_contents($ServerPipes[2]);
         $evidence['B']['launch'] = trim((string) preg_replace('/\e\[[0-9;]*m/', '', $output));
         $Observed['B: launch exits'] = $exit;
         $Observed['B: exclusive refusal logged'] = str_contains($output, 'exclusively')
            && str_contains($output, 'holds this UDP port');
         // @ The squatter still owns the port after the refused launch
         for ($sent = 0; $sent < 5; $sent++) {
            stream_socket_sendto($Client, 'squat', 0, "127.0.0.1:{$port}");
         }
         $Observed['B: squatter captured'] = $Release($IntruderPipes);
      }
      finally {
         // ! Both groups, whatever the first one left
         $clean = $Teardown($Server, $master, $ServerPipes);
         $Observed['B: groups clean'] = $Teardown($Process, $intruder, $IntruderPipes) && $clean;
         $Purge($port);
         fclose($Client);
      }

      yield new Assertion(
         description: 'another account can neither take the running port nor have a launch start on top of it: ' . json_encode($evidence)
      )
         ->expect($Observed, Op::Identical, [
            'A: fixture serves' => true,
            'A: intruder is another account' => true,
            'A: intruder CapEff' => '0000000000000000',
            'A: intruder binds' => ['wildcard' => false, 'loopback' => false, 'reuseport' => false],
            'A: server answers while the intruder tries' => 20,
            'A: intruder captured' => 0,
            'A: groups clean' => true,
            'B: squatter is another account' => true,
            'B: squatter CapEff' => '0000000000000000',
            'B: squatter binds' => ['loopback' => true],
            'B: launch exits' => 1,
            'B: exclusive refusal logged' => true,
            'B: squatter captured' => 5,
            'B: groups clean' => true,
         ])
         ->assert();
   }),
);
