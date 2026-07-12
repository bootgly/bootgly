<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

$root = getenv('BOOTGLY_CERTIFIER_ROOT');
$storage = getenv('BOOTGLY_CERTIFIER_STORAGE');
$port = (int) getenv('BOOTGLY_CERTIFIER_PORT');
$gate = (int) getenv('BOOTGLY_CERTIFIER_GATE');
$CA = (int) getenv('BOOTGLY_CERTIFIER_CA');
if ($root === false || $storage === false || $port < 1 || $gate < 1 || $CA < 1) {
   exit(2);
}

define('BOOTGLY_STORAGE_BASE', $storage);
$_SERVER['SCRIPT_FILENAME'] = '';
require rtrim($root, '/') . '/autoboot.php';

$Server = new HTTP_Server_CLI;
$Server->configure(
   host: '127.0.0.1',
   port: $port,
   workers: 1,
   secure: new AutoTLS(
      domains: ['localhost'],
      email: 'certifier@bootgly.test',
      directory: "https://127.0.0.1:{$CA}/directory",
      path: "{$storage}/autotls/",
      port: $gate,
      verify: false
   )
);
$Server->on(
   Events::RequestReceived,
   static function ($Request, Response $Response): Response {
      return $Response->send('certifier-watchdog');
   }
);
$Server->start();
