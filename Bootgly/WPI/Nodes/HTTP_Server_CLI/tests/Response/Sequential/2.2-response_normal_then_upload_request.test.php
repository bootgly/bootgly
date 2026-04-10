<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   requests: [
      function () {
         return "GET /hello HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /download HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   responseLengths: [null, 342],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/hello', function ($Request, $Response) {
         return $Response(body: 'Hello World!');
      }, GET);

      yield $Router->route('/download', function ($Request, $Response) {
         return $Response->upload('HTTP_Server_CLI/statics/alphanumeric.txt', close: false);
      }, GET);
   },

   test: function (array $responses) {
      // @ Assert Response 1 — normal text
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 12\r
      \r
      Hello World!
      HTML_RAW;

      if ($responses[0] !== $expected) {
         Vars::$labels = ['Response 1:', 'Expected:'];
         dump(json_encode($responses[0]), json_encode($expected));
         return 'First request: normal text response not matched';
      }

      // @ Assert Response 2 — file upload
      if (strpos($responses[1], 'Content-Disposition: attachment; filename="alphanumeric.txt"') === false) {
         Vars::$labels = ['Response 2:'];
         dump(json_encode($responses[1]));
         return 'Second request: missing Content-Disposition header for upload';
      }
      if (strpos($responses[1], 'application/octet-stream') === false) {
         Vars::$labels = ['Response 2:'];
         dump(json_encode($responses[1]));
         return 'Second request: missing Content-Type for upload';
      }
      if (strpos($responses[1], 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789') === false) {
         Vars::$labels = ['Response 2:'];
         dump(json_encode($responses[1]));
         return 'Second request: file content missing from upload response';
      }

      return true;
   }
);
