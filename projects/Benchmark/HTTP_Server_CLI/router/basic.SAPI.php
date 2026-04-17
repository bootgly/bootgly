<?php

namespace projects\HTTP_Server_CLI\router;


use Generator;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return static function
(Request $Request, Response $Response, Router $Router): Generator
{
   // @ Static routes (10)
   yield $Router->route('/', function (Request $Request, Response $Response) {
      return $Response(body: 'Home');
   }, GET);
};
