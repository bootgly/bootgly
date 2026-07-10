<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// ! Expected wire bytes: ONLY the final 200 — empty hints are no-ops and a
//   hint after the final send() is refused
$expected = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/html; charset=UTF-8\r\n"
   . "Content-Length: 5\r\n"
   . "\r\n"
   . "quiet";

return new Specification(
   description: 'It should skip empty hints and hints after the final send',

   request: function () {
      return "GET /hints-noop HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      // @ All empty after normalization — no interim response may be written
      $Response->hint();
      $Response->hint([]);
      $Response->hint('');
      $Response->hint(["\r\n", '']);

      $Response->send('quiet');

      // @ Refused — the final response was already sent
      $Response->hint('</late.css>; rel=preload; as=style');

      return $Response;
   },
   responseLength: strlen($expected),

   test: function ($response) use ($expected) {
      // @ Assert
      if ($response !== $expected || str_contains($response, '103')) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Empty/late hint leaked interim bytes';
      }

      return true;
   }
);
