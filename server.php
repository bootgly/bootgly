<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


require_once 'boot/..php';
require_once 'core/@loader.php';
require_once 'interfaces/@loader.php';


use Bootgly\Web\TCP;
#use Bootgly\Web\HTTP;


$TCPServer = new TCP\Server;
$TCPServer->configure(
   host: '0.0.0.0',
   port: 8080,
   workers: 4,

   handler: function ($request) {
      $date = gmdate('D, d M Y H:i:s T');

      $response = <<<HTTP_RAW
      HTTP/1.1 200 OK
      Server: Test Server
      Content-Type: text/plain; charset=UTF-8
      Content-Length: 12
      Date: $date

      Hello World!
      HTTP_RAW;

      return $response;
   }
);
$TCPServer->start();