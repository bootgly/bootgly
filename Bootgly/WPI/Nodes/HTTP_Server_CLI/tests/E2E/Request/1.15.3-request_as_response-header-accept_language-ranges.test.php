<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      // ! Full RFC 4647 language-ranges must survive parsing: a script+region
      //   tag (zh-Hant-TW) and the `*` wildcard — the old pattern truncated
      //   multi-subtag tags and dropped `*` entirely.
      return <<<HTTP
      GET / HTTP/1.1\r
      Host: localhost\r
      Accept-Language: zh-Hant-TW;q=0.9, *;q=0.1\r
      \r

      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $languages = implode(',', $Request->languages);

      return $Response(body: $languages);
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 12\r
      \r
      zh-Hant-TW,*
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
