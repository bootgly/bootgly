<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Modules\HTTP\Server\Router\Middlewares\BodyParser;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should return 413 when Content-Length exceeds max size',

   request: function () {
      $body = '{"data":"oversized!"}';
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
   middlewares: [new BodyParser(maxSize: 10)],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'Should not reach here');
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 413 Request Entity Too Large\r
      Server: Bootgly\r
      Content-Length: 17\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      Payload Too Large
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'BodyParser 413 response not matched';
      }

      return true;
   }
);
