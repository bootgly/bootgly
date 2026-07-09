<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

/**
 * Error handling examples.
 *
 * `/error` throws an uncaught exception on purpose: with
 * `BOOTGLY_ENVIRONMENT=development` the built-in debug page answers (or a
 * JSON payload under `Accept: application/json`); in production the clean
 * error page (or `views/errors/500.template.php`, when the project has one).
 */

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


return static function (Request $Request, Response $Response, Router $Router): Generator
{
   // * Errors
   yield $Router->route('/error', function (Request $Request, Response $Response) {
      throw new RuntimeException('Demo exception: this route always throws.');
   }, GET);
};
