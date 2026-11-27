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
use function fclose;
use function is_array;
use function is_resource;
use function json_decode;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function strpos;
use function substr;

use Bootgly\ACI\Tests\Suite\Test;


/**
 * In a fresh process — where no legacy client ever initialized the statics —
 * a whole embedded lifecycle (hooks, dial, send, receive, a failed read,
 * close, stop) runs to the end, and every typed static of the legacy API is
 * still uninitialized afterwards: an embedded path that read one would have
 * thrown, one that wrote one would have initialized it.
 */
return new Test(
   description: 'UDP_Client_CLI: an embedded lifecycle in a fresh process never initializes a static',
   test: function () {
      $code = <<<'PHP'
require $argv[1];
use Bootgly\WPI\Interfaces\UDP_Client_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Events;

$Peer = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
[$host, $port] = explode(':', (string) stream_socket_get_name($Peer, false));
$Client = new UDP_Client_CLI(UDP_Client_CLI::MODE_EMBEDDED);
$Client->configure(new Configs(host: $host, port: (int) $port));
$hooks = [];
foreach ([Events::ClientConnect, Events::ClientDisconnect, Events::DatagramRead, Events::DatagramWrite] as $Event) {
   $Client->on($Event, static function () use ($Event, &$hooks): void { $hooks[] = $Event->value; });
}
$Socket = $Client->connect();
$Flow = $Client->Connections->Flows[(int) $Socket];
$Flow->output = 'ping';
$Flow->writing($Socket);
$read = [$Peer]; $write = null; $except = null;
stream_select($read, $write, $except, 1);
$ping = stream_socket_recvfrom($Peer, 100, 0, $address);
stream_socket_sendto($Peer, 'pong', 0, $address);
$Outcome = $Flow->receive(1.0);
$pong = $Flow->input;
$Flow->fail($Socket, 'read', false);
$Client->stop();

$initialized = [];
foreach ([
   [UDP_Client_CLI::class, 'Event'],
   [Connections::class, 'Connections'], [Connections::class, 'stats'], [Connections::class, 'errors'],
   [Connections::class, 'writes'], [Connections::class, 'reads'], [Connections::class, 'written'], [Connections::class, 'read'],
] as [$class, $name]) {
   if ((new ReflectionProperty($class, $name))->isInitialized()) {
      $initialized[] = "{$class}::\${$name}";
   }
}
$Reflection = new ReflectionClass(UDP_Client_CLI::class);
echo json_encode([
   'outcome' => $Outcome->name,
   'ping' => $ping,
   'pong' => $pong,
   'hooks' => $hooks,
   'initialized' => $initialized,
   'statics' => [
      $Reflection->getStaticPropertyValue('started'), $Reflection->getStaticPropertyValue('status'),
      UDP_Client_CLI::$onWorkerStarted, UDP_Client_CLI::$onClientConnect, UDP_Client_CLI::$onClientDisconnect,
      UDP_Client_CLI::$onDatagramRead, UDP_Client_CLI::$onDatagramWrite,
   ],
   'closed' => is_resource($Socket) === false && $Client->Connections->Flows === [],
]);
PHP;
      $Process = proc_open(
         [PHP_BINARY, '-d', 'zend.assertions=1', '-r', $code, BOOTGLY_ROOT_DIR . 'autoboot.php'],
         [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
         $pipes
      );
      $output = '';
      $errors = '';
      if (is_resource($Process)) {
         $output = (string) stream_get_contents($pipes[1]);
         $errors = (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);
         proc_close($Process);
      }
      // ! The flow's warnings (the failed read) print before the report
      $report = strpos($output, '{"outcome"');
      $child = json_decode($report === false ? '' : substr($output, $report), true);

      yield assert(
         assertion: is_array($child)
            && $child['outcome'] === 'Received'
            && $child['ping'] === 'ping'
            && $child['pong'] === 'pong'
            && $child['hooks'] === ['clientConnect', 'datagramWrite', 'datagramRead', 'clientDisconnect']
            && $child['closed'] === true,
         description: "the embedded lifecycle runs to the end in a fresh process {$output} {$errors}"
      );
      yield assert(
         assertion: is_array($child)
            && $child['initialized'] === []
            && $child['statics'] === [0, 0, null, null, null, null, null],
         description: "no typed static of the legacy API was initialized, and the defaulted ones kept their defaults {$output}"
      );
   }
);
