<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      // ! q=0 ranges must survive parsing as exclusions (not silently
      //   dropped) and duplicate ranges must resolve to the LAST occurrence
      //   (`fr;q=0, fr;q=0.9` accepts fr; `en;q=0` refuses en).
      return <<<HTTP
      GET / HTTP/1.1\r
      Host: localhost\r
      Accept-Language: *;q=0.5, en;q=0, de;q=0.8, fr;q=0, fr;q=0.9\r
      \r

      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $accepted = implode(',', $Request->languages);
      $refused = implode(',', $Request->exclusions);

      return $Response(body: "{$accepted}|{$refused}");
   },

   test: function ($response) {
      // ! Accepted sorted by quality (fr 0.9 > de 0.8 > * 0.5); `en` kept
      //   apart as an exclusion; `fr` re-accepted by its last occurrence
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 10\r
      \r
      fr,de,*|en
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
