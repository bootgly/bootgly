<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


use Closure;

use Bootgly\API\Workables\Server\Middleware as Generic;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


/**
 * HTTP_Server_CLI-specific Middleware contract.
 *
 * Extends the generic API contract `Bootgly\API\Workables\Server\Middleware`
 * so any WPI middleware also satisfies the parent interface — Router and
 * Middlewares (API layer) keep typehinting the generic contract and accept
 * both kinds without a union.
 *
 * Concrete `Request` and `Response` types are expressed via PHPDoc (PHPStan
 * honors them) while the runtime parameter types remain `object` — PHP
 * forbids narrowing a parent's parameter types by contravariance.
 */
interface Middleware extends Generic
{
   /**
    * @param Request $Request
    * @param Response $Response
    */
   public function process (object $Request, object $Response, Closure $next): object;
}
