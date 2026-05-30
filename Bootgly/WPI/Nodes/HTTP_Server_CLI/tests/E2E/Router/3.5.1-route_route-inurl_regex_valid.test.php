<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * In-URL inline regex constraint: `:id(\d+)` embeds the regex directly in the
 * route path (distinct from the named `:id<int>` constraint and from the
 * pre-set `$Route->Params->id = '...'` form). Exercises the `str_ends_with(')')`
 * branch in `Router::cache()` and the complex (regex) match path in `resolve()`.
 */
return new Specification(
   Separator: new Separator(left: 'In-URL regex'),

   request: function () {
      return "GET /product/42 HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/product/:id(\d+)', function ($Request, $Response) {
         return $Response(body: 'Product: ' . $this->Params->id);
      }, GET);

      yield $Router->route('/*', function ($Request, $Response) {
         return $Response(code: 404, body: 'Not Found');
      }, GET);
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 11\r
      \r
      Product: 42
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
