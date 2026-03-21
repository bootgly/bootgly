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
 * Middlewares routes example.
 *
 * Demonstrates 3 levels of middleware in Bootgly:
 *
 * Level 1 — Global (SAPI)   : $Middlewares->pipe()  applied to ALL routes via SAPI
 * Level 2 — Group (Router)  : $Router->intercept()  applied to all routes AFTER the call
 * Level 3 — Per-route       : middlewares: [...]     applied ONLY to the matched route
 *
 * Middleware execution order (onion):
 *   Global before → Group before → Per-route before → Handler → Per-route after → Group after → Global after
 *
 * NOTE: This file uses the generator (yield) handler pattern.
 *       To use it, register it in WPI.project.php:
 *         $Server->handle(require __DIR__ . '/router/routes/Middlewares.routes.php');
 */

use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
// Middlewares
use Bootgly\WPI\Modules\HTTP\Server\Router\Middlewares\BodyParser;
use Bootgly\WPI\Modules\HTTP\Server\Router\Middlewares\CORS;
use Bootgly\WPI\Modules\HTTP\Server\Router\Middlewares\ETag;
use Bootgly\WPI\Modules\HTTP\Server\Router\Middlewares\RateLimit;
use Bootgly\WPI\Modules\HTTP\Server\Router\Middlewares\RequestId;
use Bootgly\WPI\Modules\HTTP\Server\Router\Middlewares\SecureHeaders;


return static function (Request $Request, Response $Response, Router $Router)
{
   // ---
   // # Level 2 — Group: applies to ALL routes defined AFTER this call until next intercept/reset
   $Router->intercept(new SecureHeaders, new RequestId);

   // # Home — inherits group middleware (SecureHeaders + RequestId)
   yield $Router->route('/', function (Request $Request, Response $Response) {
      return $Response(body: 'Hello World!');
   }, GET);

   // ---
   // # Level 3 — Per-route: only this route gets ETag
   yield $Router->route('/articles', function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         ['id' => 1, 'title' => 'Bootgly v1'],
         ['id' => 2, 'title' => 'Middleware Pipeline'],
      ]);
   }, GET, middlewares: [new ETag]);

   // ---
   // # Level 2 — Group: append RateLimit + CORS to ALL subsequent routes
   $Router->intercept(new RateLimit(limit: 100, window: 60), new CORS);

   // # GET /api/users — inherits SecureHeaders + RequestId + RateLimit + CORS + per-route ETag
   yield $Router->route('/api/users', function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         ['id' => 1, 'name' => 'Rodrigo'],
         ['id' => 2, 'name' => 'Bootgly'],
      ]);
   }, GET, middlewares: [new ETag]);

   // # POST /api/users — inherits group middlewares + per-route BodyParser
   yield $Router->route('/api/users', function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         'created' => true,
         'input'   => $Request->input,
      ]);
   }, POST, middlewares: [new BodyParser]);

   // ---
   // # Level 2 — Nested group with intercept
   // @ Group middlewares set INSIDE the nested closure apply to all routes inside that group.
   yield $Router->route('/admin/:*', function () use ($Router) {
      // @ Group intercept scoped to /admin/* routes only
      $Router->intercept(new RateLimit(limit: 10, window: 60));

      // # GET /admin/dashboard — inherits outer SecureHeaders + RequestId + inner RateLimit(10/min)
      yield $Router->route('dashboard', function (Request $Request, Response $Response) {
         return $Response->JSON->send(['section' => 'admin', 'page' => 'dashboard']);
      }, GET);

      // # GET /admin/users — inherits outer + inner group + per-route ETag
      yield $Router->route('users', function (Request $Request, Response $Response) {
         return $Response->JSON->send([
            ['id' => 1, 'role' => 'admin'],
            ['id' => 2, 'role' => 'editor'],
         ]);
      }, GET, middlewares: [new ETag]);

      // # POST /admin/users — inherits outer + inner group + per-route BodyParser
      yield $Router->route('users', function (Request $Request, Response $Response) {
         return $Response->JSON->send([
            'created' => true,
            'input'   => $Request->input,
         ]);
      }, POST, middlewares: [new BodyParser]);
   }, GET);

   // ---
   // # Catch-all — also gets SecureHeaders + RequestId + RateLimit + CORS
   yield $Router->route('/*', function (Request $Request, Response $Response) {
      return $Response(code: 404, body: 'Not Found');
   });
};
