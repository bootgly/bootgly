<?php

use function str_contains;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Regression — a named catch-all param (`:name*`) MUST be the last path
 * segment. `Router::cache()` throws `InvalidArgumentException` at warmup when
 * it is not; the Encoder serves 500. Guard PRESENT → 500. Guard ABSENT →
 * the malformed pattern would compile a broken regex (silent wrong matching).
 */
return new Specification(
   Separator: new Separator(left: 'Route registration errors'),
   description: 'It should reject a catch-all param that is not the last segment',

   request: function () {
      return "GET /files/a/b/download HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // @ Illegal: `:path*` (catch-all) is NOT the last segment.
      yield $Router->route('/files/:path*/download', function ($Request, $Response) {
         return $Response(body: 'CATCH-ALL-NOT-LAST-REACHED');
      }, GET);

      yield $Router->route('/*', function ($Request, $Response) {
         return $Response(code: 404, body: 'Not Found');
      }, GET);
   },

   test: function ($response) {
      if (! str_contains($response, 'HTTP/1.1 500')) {
         Vars::$labels = ['HTTP Response:'];
         dump($response);
         return 'Catch-all-not-last route was not rejected — expected 500 from the '
              . '"Catch-all param must be the last path segment" guard at warmup.';
      }

      if (str_contains($response, 'CATCH-ALL-NOT-LAST-REACHED')) {
         Vars::$labels = ['HTTP Response:'];
         dump($response);
         return 'Malformed catch-all route was registered and executed.';
      }

      return true;
   }
);
