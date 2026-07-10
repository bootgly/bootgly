<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


/**
 * Built-in health-check responder (K8s liveness/readiness probes).
 *
 * Dispatched by the encoders BEFORE the middleware pipeline when
 * `Server::$health` matches the request path — RateLimit/Authentication or
 * any user middleware can never break a probe. A reachable worker that
 * accepts and serves the request IS the health signal.
 *
 * The body is deliberately minimal: the endpoint is middleware-proof (auth
 * cannot gate it), so it must not leak process internals (pid, connection
 * counts, capacity) on public deployments. Rich vitals belong to the
 * opt-in Observability route set, where middleware protection applies.
 */
abstract class Check
{
   /**
    * Respond one health probe.
    */
   public static function respond (Request $Request, Response $Response): Response
   {
      // @ Head — probe responses must never be cached
      $Response->Header->type = 'application/json';
      $Response->Header->set('Cache-Control', 'no-store');

      // :
      return $Response->send('{"status":"ok"}');
   }
}
