<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

$root = getenv('BOOTGLY_DAEMON_ROOT');
$storage = getenv('BOOTGLY_DAEMON_STORAGE');
$port = (int) getenv('BOOTGLY_DAEMON_PORT');
if ($root === false || $storage === false || $port < 1) {
   exit(2);
}

define('BOOTGLY_STORAGE_BASE', $storage);
// This is an embedded fixture, not a Bootgly CLI command/script.
$_SERVER['SCRIPT_FILENAME'] = '';
require rtrim($root, '/') . '/autoboot.php';

// The public default is deliberately used: this fixture verifies the final
// daemon master, not the test/foreground orchestration path.
$Server = new HTTP_Server_CLI;
$Server->configure(host: '127.0.0.1', port: $port, workers: 2);
$Server->on(
   Events::RequestReceived,
   static function ($Request, Response $Response): Response {
      return $Response->send('daemon');
   }
);
if (getenv('BOOTGLY_DAEMON_FAIL') === '1') {
   $Server->on(
      Events::ServerStarted,
      static function (): void {
         throw new RuntimeException('intentional daemon startup failure');
      }
   );
}
$Server->start();
