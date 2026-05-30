<?php

use function str_contains;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Regression — a named constraint type (`:id<type>`) MUST be one of the known
 * PARAM_CONSTRAINTS (int|alpha|alphanum|slug|uuid). `Router::cache()` throws
 * `InvalidArgumentException` at warmup for an unknown type; the Encoder serves
 * 500. Guard PRESENT → 500. Guard ABSENT → the `<bogus>` would silently expand
 * to nothing / a broken pattern.
 */
return new Specification(
   request: function () {
      return "GET /user/123 HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // @ Illegal: `<bogus>` is not a known constraint type.
      yield $Router->route('/user/:id<bogus>', function ($Request, $Response) {
         return $Response(body: 'UNKNOWN-CONSTRAINT-REACHED');
      }, GET);

      yield $Router->route('/*', function ($Request, $Response) {
         return $Response(code: 404, body: 'Not Found');
      }, GET);
   },

   test: function ($response) {
      if (! str_contains($response, 'HTTP/1.1 500')) {
         Vars::$labels = ['HTTP Response:'];
         dump($response);
         return 'Unknown constraint type was not rejected — expected 500 from the '
              . '"Unknown route param constraint type" guard at warmup.';
      }

      if (str_contains($response, 'UNKNOWN-CONSTRAINT-REACHED')) {
         Vars::$labels = ['HTTP Response:'];
         dump($response);
         return 'Route with unknown constraint type was registered and executed.';
      }

      return true;
   }
);
