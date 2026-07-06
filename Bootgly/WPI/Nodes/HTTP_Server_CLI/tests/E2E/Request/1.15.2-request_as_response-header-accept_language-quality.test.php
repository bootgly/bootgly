<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      // ! pt-BR (implicit q=1.0) must beat de (q=0.5) despite arriving later —
      //   regression: the old pattern captured `-BR` as the quality (0.0) and
      //   region-subtagged languages sorted last (or were dropped as refused).
      return <<<HTTP
      GET / HTTP/1.1\r
      Host: localhost\r
      Accept-Language: de;q=0.5, pt-BR\r
      \r

      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $language = $Request->language;

      return $Response(body: $language);
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 5\r
      \r
      pt-BR
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
