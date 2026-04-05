<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   requests: [
      function () {
         return "GET /download HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /hello HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   responseLengths: [342, null],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/download', function ($Request, $Response) {
         return $Response->upload('statics/alphanumeric.txt', close: false);
      }, GET);

      yield $Router->route('/hello', function ($Request, $Response) {
         return $Response(body: 'Hello World!');
      }, GET);
   },

   test: function (array $responses) {
      // @ Assert Response 1 — file upload
      // Check that response contains file download headers
      if (strpos($responses[0], 'Content-Disposition: attachment; filename="alphanumeric.txt"') === false) {
         Vars::$labels = ['Response 1:'];
         dump(json_encode($responses[0]));
         return 'First request: missing Content-Disposition header for upload';
      }
      if (strpos($responses[0], 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789') === false) {
         Vars::$labels = ['Response 1:'];
         dump(json_encode($responses[0]));
         return 'First request: missing file content in upload response';
      }

      // @ Assert Response 2 — normal text
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 12\r
      \r
      Hello World!
      HTML_RAW;

      if ($responses[1] !== $expected) {
         Vars::$labels = ['Response 2:', 'Expected:'];
         dump(json_encode($responses[1]), json_encode($expected));
         return 'Upload state leaked: second request has stale upload headers or stream state';
      }

      return true;
   }
);
