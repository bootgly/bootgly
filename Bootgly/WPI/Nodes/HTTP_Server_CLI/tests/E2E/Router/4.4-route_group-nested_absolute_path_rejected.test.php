<?php

use function str_contains;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Regression — nested routes inside a route group MUST be relative.
 *
 * A nested route registered with a leading '/' (absolute) would, without the
 * guard, silently register at the TOP level instead of under the group prefix
 * (e.g. '/dashboard' instead of '/admin/dashboard') — a hard-to-debug routing
 * mismatch. The guard in `Router::cache()` (fires only while flattening a
 * group, i.e. `groupPrefix !== null`) throws `InvalidArgumentException` at
 * registration/warmup time. The Encoder catches it and serves `500`.
 *
 * Guard PRESENT  → request triggers warmup → guard throws → 500.
 * Guard ABSENT (regression) → '/dashboard' registers top-level; '/admin/dashboard'
 *   never matches it and falls through to the catch-all → NOT a 500.
 *
 * Therefore asserting a 500 status uniquely proves the guard is active. The
 * nested handler body must never appear (warmup aborts before any dispatch).
 */
return new Specification(
   Separator: new Separator(left: 'Route group nested-absolute guard'),
   description: 'It should reject a nested route registered with an absolute path',

   request: function () {
      return "GET /admin/dashboard HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // @ Group with an ILLEGAL nested route: leading '/' (absolute).
      //   `cache()` must throw `InvalidArgumentException` while flattening.
      yield $Router->route('/admin/:*', function () use ($Router) {
         yield $Router->route('/dashboard', function ($Request, $Response) {
            return $Response(body: 'NESTED-ABSOLUTE-REACHED');
         });
      }, GET);

      yield $Router->route('/*', function ($Request, $Response) {
         return $Response(code: 404, body: 'Not Found');
      }, GET);
   },

   test: function ($response) {
      // @ Assert the request was rejected with 500 (guard fired at warmup).
      if (! str_contains($response, 'HTTP/1.1 500')) {
         Vars::$labels = ['HTTP Response:'];
         dump($response);
         return 'Nested absolute route was not rejected — expected 500 from the '
              . '"Nested route path must be relative!" guard at warmup.';
      }

      // @ The nested handler must never run (warmup aborts before dispatch).
      if (str_contains($response, 'NESTED-ABSOLUTE-REACHED')) {
         Vars::$labels = ['HTTP Response:'];
         dump($response);
         return 'Nested absolute route handler executed — guard did not fire and '
              . 'the route was silently (mis)registered.';
      }

      return true;
   }
);
