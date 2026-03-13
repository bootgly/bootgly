<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      // return $Request->get('/');
      return "GET /route5 HTTP/1.0\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router) {
      $Router->route('/route5', function ($Request, $Response) {
         return $Response(body: 'Multiple HTTP methods!');
      }, [GET, POST]);
   },

   test: function ($response) {
      /*
      return $Response->status === '200 OK'
      && $Response->body === '127.0.0.1';
      */

      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Length: 22\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      Multiple HTTP methods!
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
