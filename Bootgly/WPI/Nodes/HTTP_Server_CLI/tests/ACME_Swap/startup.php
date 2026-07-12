<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

$root = getenv('BOOTGLY_STARTUP_ROOT');
$storage = getenv('BOOTGLY_STARTUP_STORAGE');
$port = (int) getenv('BOOTGLY_STARTUP_PORT');
$gate = (int) getenv('BOOTGLY_STARTUP_GATE');
if ($root === false || $storage === false || $port < 1 || $gate < 1) {
   exit(2);
}

define('BOOTGLY_STORAGE_BASE', $storage);
$_SERVER['SCRIPT_FILENAME'] = '';
require rtrim($root, '/') . '/autoboot.php';

final class FailingServer extends HTTP_Server_CLI
{
   /** @param array<string,mixed> $secure */
   public function swap (array $secure, null|array $hashes = null): bool
   {
      if ($this->Process->level === 'child') {
         return false;
      }

      return parent::swap($secure, $hashes);
   }
}

$Server = new FailingServer;
$Server->configure(
   host: '127.0.0.1',
   port: $port,
   workers: 2,
   secure: new AutoTLS(
      domains: ['localhost'],
      email: 'startup@bootgly.test',
      path: "{$storage}/autotls/",
      port: $gate,
      options: ['verify_peer' => false]
   )
);
$Server->on(
   Events::RequestReceived,
   static function ($Request, Response $Response): Response {
      return $Response->send('must-not-start');
   }
);
$Server->start();
