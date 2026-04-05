<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      // return $Request->get('/');
      return "GET /specific HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/', function ($Request, $Response) {
         return $Response(body: 'Fail...');
      }, GET);

      yield $Router->route('/fail', function ($Request, $Response) {
         return $Response(body: 'Fail...');
      }, GET);

      yield $Router->route('/specific', function ($Request, $Response) {
         return $Response(body: 'Hello World!');
      }, GET);

      yield $Router->route('/*', function ($Request, $Response) {
         return $Response(body: 'Catch-All!');
      }, GET);
   },

   test: function ($response) {
      /*
      return $Response->status === '200 OK'
      && $Response->body === '127.0.0.1';
      */

      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 12\r
      \r
      Hello World!
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
