<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\HTTP_Server_CLI\router;


use const GET;
use function getenv;
use Generator;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use projects\HTTP_Server_CLI\Profiler;


return static function
(Request $Request, Response $Response, Router $Router): Generator
{
   // @ Per-worker profiler bootstrap (env-gated; idempotent via internal PID guard)
   if (getenv('BOOTGLY_PROFILE') === '1') {
      require_once __DIR__ . '/../Profiler.php';
      Profiler::start();
   }

   yield $Router->route('/', function (Request $Request, Response $Response) {
      return $Response(body: 'Home');
   }, GET);
};
