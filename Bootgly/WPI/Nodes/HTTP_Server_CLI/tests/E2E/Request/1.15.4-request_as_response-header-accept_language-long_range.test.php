<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      // ! A language range longer than four subtags must survive as ONE
      //   preference (the old bounded pattern split it in two), and an
      //   out-of-range quality (q>1) must clamp instead of outranking q=1.
      return <<<HTTP
      GET / HTTP/1.1\r
      Host: localhost\r
      Accept-Language: de-Latn-DE-1996-x-private;q=3, en\r
      \r

      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $languages = implode(',', $Request->languages);

      return $Response(body: $languages);
   },

   test: function ($response) {
      // ! q=3 clamps to 1.0 — ties keep header order, so the long range
      //   stays first and intact, followed by `en`
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 28\r
      \r
      de-Latn-DE-1996-x-private,en
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
