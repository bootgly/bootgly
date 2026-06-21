<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

/**
 * Basic routes example.
 *
 * Minimal commons: an OPTIONS catch-all and a favicon upload.
 */

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


return static function (Request $Request, Response $Response, Router $Router): Generator
{
   // * Commons
   yield $Router->route('*', function (Request $Request, Response $Response) {
      return $Response(code: 404);
   }, OPTIONS);

   yield $Router->route('/favicon.ico', function (Request $Request, Response $Response) {
      return $Response->upload('statics/favicon.ico');
   }, GET);
};
