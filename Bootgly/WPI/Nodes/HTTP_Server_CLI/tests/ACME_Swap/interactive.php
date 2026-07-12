<?php

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

$root = getenv('BOOTGLY_INTERACTIVE_ROOT');
$storage = getenv('BOOTGLY_INTERACTIVE_STORAGE');
$counter = getenv('BOOTGLY_INTERACTIVE_COUNTER');
$port = (int) getenv('BOOTGLY_INTERACTIVE_PORT');
if ($root === false || $storage === false || $counter === false || $port < 1) {
   exit(2);
}

define('BOOTGLY_STORAGE_BASE', $storage);
$_SERVER['SCRIPT_FILENAME'] = '';
require rtrim($root, '/') . '/autoboot.php';

final class InteractiveServer extends HTTP_Server_CLI
{
   protected function tick (): void
   {
      $counter = (string) getenv('BOOTGLY_INTERACTIVE_COUNTER');
      $current = (int) @file_get_contents($counter);
      file_put_contents($counter, (string) ($current + 1), LOCK_EX);

      parent::tick();
   }
}

$Server = new InteractiveServer(Modes::Interactive);
$Server->configure(host: '127.0.0.1', port: $port, workers: 1);
$Server->on(
   Events::RequestReceived,
   static function ($Request, Response $Response): Response {
      return $Response->send('interactive');
   }
);
$Server->start();
