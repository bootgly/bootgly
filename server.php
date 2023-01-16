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


require_once '@/autoload.php';


use Bootgly\Web\TCP;
#use Bootgly\Web\HTTP;


$TCPServer = new TCP\Server;
$TCPServer->configure(
   host: '0.0.0.0',
   port: 8080,
   workers: 13
);
$TCPServer->on('data', function ($request) {
   $response = <<<HTTP_RAW
   HTTP/1.1 200 OK
   Server: Test Server
   Content-Type: text/plain; charset=UTF-8
   Content-Length: 12

   Hello World!
   HTTP_RAW;

   return $response;
});
$TCPServer->start();
