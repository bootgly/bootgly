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

const HOME_DIR = __DIR__.DIRECTORY_SEPARATOR;

require_once 'boot/..php';
require_once 'core/@loader.php';
require_once 'interfaces/@loader.php';
require_once 'nodes/@loader.php';


#use Bootgly\Web\TCP;
use Bootgly\Web\HTTP;
use Bootgly\Web\HTTP\Server\Request;
use Bootgly\Web\HTTP\Server\Response;
use Bootgly\Web\HTTP\Server\Router;

$Bootgly = new Bootgly;
$Web = new Web($Bootgly);

$HTTPServer = new HTTP\Server($Web);
$HTTPServer->configure(
   host: '0.0.0.0',
   port: 8080,
   workers: 6,

   handler: function (Request $Request, Response $Response, Router $Router) {
      // $Request->method;    // GET
      // $Request->uri;       // /path/to?query1=value2...
      // $Request->protocol;  // HTTP/1.1

      return <<<HTML
      Hello World!
      HTML;
   }
);
$HTTPServer->start();

// Benchmark test suggestion:
// wrk -t5 -c300 -d10s http://localhost:8080