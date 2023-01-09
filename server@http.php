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


#use Bootgly\Web\TCP;
use Bootgly\Web\HTTP;
use Bootgly\Web\HTTP\Server\Request;
use Bootgly\Web\HTTP\Server\Response;
use Bootgly\Web\HTTP\Server\Router;


$HTTPServer = new HTTP\Server($Web);
$HTTPServer->configure(
   host: '0.0.0.0',
   port: 8080,
   workers: 14,

   handler: function (Request $Request, Response $Response, Router $Router) {
      #$Request->method;    // GET
      #$Request->uri;       // /path/to?query1=value2...
      #$Request->protocol;  // HTTP/1.1

      #return $Response(raw: 'Hello World!');

      return 'Hello World!';
   }
);
$HTTPServer->start();

// Benchmark test suggestion with 512 connections:
// wrk -t10 -c514 -d10s http://localhost:8080