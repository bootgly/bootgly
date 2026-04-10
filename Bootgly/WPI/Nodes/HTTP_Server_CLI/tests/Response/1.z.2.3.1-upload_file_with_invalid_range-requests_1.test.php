<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should return 416 status: negative range start `-5-10`',
   Separator: new Separator(left: '.2.3 - Requests Range - Client - Single Part (Invalid)'),

   request: function ($host) {
      $raw = <<<HTTP_RAW
      GET /test/download/file_with_range/one_range/5 HTTP/1.1\r
      Host: {$host}\r
      User-Agent: Bootgly\r
      Range: bytes=-5-10\r
      \r\n
      HTTP_RAW;

      return $raw;
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response->upload('HTTP_Server_CLI/statics/alphanumeric.txt', close: false);
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 416 Range Not Satisfiable\r
      Server: Bootgly\r
      Content-Range: bytes */62\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 1\r
      \r
       
      HTML_RAW;

      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response Status did not return 416 HTTP Status?';
      }

      return true;
   }
);
