<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Client_CLI;


use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use function assert;
use function escapeshellarg;
use function fclose;
use function is_array;
use function is_resource;
use function json_decode;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function strpos;
use function substr;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * A flow whose descriptor `stream_select()` cannot watch (at or above
 * `FD_SETSIZE` where the descriptor limit lets one exist) never turns the
 * wait into a busy loop: it ends `Rejected` at once, or — on a build that
 * watches it — waits asleep until its bound. Runs in a child process with a
 * soft descriptor limit of 4096, holding 1100 descriptors so the flow's
 * lands above 1024; a host whose hard limit is below 4096 cannot raise it,
 * and the case is skipped by name there.
 */
return new Test(
   description: 'UDP_Client_CLI: a datagram wait on a socket the selector cannot watch never spins',
   test: function () {
      $code = <<<'PHP'
require $argv[1];
use Bootgly\WPI\Interfaces\UDP_Client_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Configs;

// ! Every class the wait loads, before the held descriptors crowd the table
foreach ([
   UDP_Client_CLI::class, Configs::class, UDP_Client_CLI\Connections::class,
   UDP_Client_CLI\Connections\Connection::class, UDP_Client_CLI\Packages::class, UDP_Client_CLI\Outcomes::class,
   Bootgly\WPI\Events\Select::class, Bootgly\ACI\Events\Readiness::class,
] as $class) {
   class_exists($class) || interface_exists($class) || enum_exists($class);
}
$Peer = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
[$host, $port] = explode(':', (string) stream_socket_get_name($Peer, false));
$Held = [];
for ($i = 0; $i < 1100; $i++) {
   $Handle = @fopen('/dev/null', 'r');
   if ($Handle === false) {
      break;
   }
   $Held[] = $Handle;
}
$Client = new UDP_Client_CLI(UDP_Client_CLI::MODE_EMBEDDED);
$Client->configure(new Configs(host: $host, port: (int) $port));
$Socket = $Client->connect();
$Flow = $Client->Connections->Flows[(int) $Socket];
$Usage = getrusage();
$baseline = $Usage['ru_utime.tv_sec'] + $Usage['ru_utime.tv_usec'] / 1e6 + $Usage['ru_stime.tv_sec'] + $Usage['ru_stime.tv_usec'] / 1e6;
$started = hrtime(true);
$Outcome = $Flow->receive(0.5);
$elapsed = (hrtime(true) - $started) / 1e9;
$Usage = getrusage();
$spent = $Usage['ru_utime.tv_sec'] + $Usage['ru_utime.tv_usec'] / 1e6 + $Usage['ru_stime.tv_sec'] + $Usage['ru_stime.tv_usec'] / 1e6 - $baseline;
$Client->stop();
echo json_encode(['outcome' => $Outcome->name, 'elapsed' => $elapsed, 'cpu' => $spent, 'held' => count($Held)]);
PHP;
      // ! A soft descriptor limit that lets a descriptor reach FD_SETSIZE
      $binary = escapeshellarg(PHP_BINARY);
      $script = escapeshellarg($code);
      $autoload = escapeshellarg(BOOTGLY_ROOT_DIR . 'vendor/autoload.php');
      $command = "ulimit -n 4096 2>/dev/null || exit 77; exec {$binary} -r {$script} {$autoload}";
      $Process = proc_open(['sh', '-c', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
      $output = '';
      $errors = '';
      $status = null;
      if (is_resource($Process)) {
         $output = (string) stream_get_contents($pipes[1]);
         $errors = (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);
         $status = proc_close($Process);
      }
      // ? The hard descriptor limit forbids a soft one of 4096
      if ($status === 77) {
         yield (new Assertion(description: 'the descriptor limit cannot be raised to 4096 here'))->skip();

         return;
      }
      $report = strpos($output, '{"outcome"');
      $child = json_decode($report === false ? '' : substr($output, $report), true);

      yield assert(
         assertion: is_array($child) && $child['held'] === 1100,
         description: "the child holds 1100 descriptors, so the flow's lands above 1024 {$output} {$errors}"
      );
      yield assert(
         assertion: is_array($child)
            && $child['cpu'] < 0.05
            && (
               ($child['outcome'] === 'Rejected' && $child['elapsed'] < 0.05)
               || ($child['outcome'] === 'Expired' && $child['elapsed'] >= 0.49)
            ),
         description: "the wait ends Rejected at once or expires asleep, never spinning: {$output}"
      );
   }
);
