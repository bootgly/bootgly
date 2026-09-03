<?php

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

$root = getenv('BOOTGLY_CONSOLE_ROOT');
$storage = getenv('BOOTGLY_CONSOLE_STORAGE');
$counter = getenv('BOOTGLY_CONSOLE_COUNTER');
$port = (int) getenv('BOOTGLY_CONSOLE_PORT');
if ($root === false || $storage === false || $counter === false || $port < 1) {
   exit(2);
}

define('BOOTGLY_STORAGE_BASE', $storage);
$_SERVER['SCRIPT_FILENAME'] = '';
require rtrim($root, '/') . '/autoboot.php';

final class ConsoleServer extends HTTP_Server_CLI
{
   protected function tick (): void
   {
      $counter = (string) getenv('BOOTGLY_CONSOLE_COUNTER');
      $current = (int) @file_get_contents($counter);
      file_put_contents($counter, (string) ($current + 1), LOCK_EX);

      parent::tick();
   }
}

$Server = new ConsoleServer(Modes::Interactive);
$Server->configure(new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: $port, workers: 2));
$Server->on(
   Events::RequestReceived,
   static function ($Request, Response $Response): Response {
      return $Response->send('console');
   }
);
$Server->start();
