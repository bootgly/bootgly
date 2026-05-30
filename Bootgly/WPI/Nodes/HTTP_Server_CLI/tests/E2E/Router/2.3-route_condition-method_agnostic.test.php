<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * A route registered with no method argument is method-agnostic: it is stored
 * under the empty-method key and must match ANY request method. Exercises the
 * `staticCache[''][$url]` lookup and the `hasAgnosticStatic` flag in
 * `resolve()`. Request uses DELETE to prove method-independence.
 */
return new Specification(
   request: function () {
      return "DELETE /ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // @ No method argument → method-agnostic route.
      yield $Router->route('/ping', function ($Request, $Response) {
         return $Response(body: 'pong');
      });

      yield $Router->route('/*', function ($Request, $Response) {
         return $Response(code: 404, body: 'Not Found');
      }, GET);
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 4\r
      \r
      pong
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response raw not matched';
      }

      return true;
   }
);
