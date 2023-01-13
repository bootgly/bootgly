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
   workers: round( ((int) shell_exec('nproc')) * 0.55 ), // Without JIT: * 0.6

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

// --- If your CPU have 24 CPU cores:

// # Without JIT enabled: ~40% of CPU
// wrk -t10 -c514 -d10s http://localhost:8080

// # With JIT enabled: ~45% of CPU
// wrk -t11 -c514 -d10s http://localhost:8080