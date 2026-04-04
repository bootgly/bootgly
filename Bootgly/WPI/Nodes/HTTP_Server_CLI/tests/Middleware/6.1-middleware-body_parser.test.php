<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\BodyParser;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should pass through when body is within size limit',

   request: function () {
      $body = '{"name":"Bootgly"}';
      $length = strlen($body);

      return <<<HTTP
      POST / HTTP/1.1\r
      Host: localhost\r
      Content-Type: application/json\r
      Content-Length: {$length}\r
      \r
      {$body}
      HTTP;
   },
   middlewares: [new BodyParser(maxSize: 1024)],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'Parsed OK');
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 9\r
      \r
      Parsed OK
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'BodyParser pass-through not matched';
      }

      return true;
   }
);
