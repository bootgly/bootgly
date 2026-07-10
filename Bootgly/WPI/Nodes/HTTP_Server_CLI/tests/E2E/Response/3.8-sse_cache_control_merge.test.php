<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// ! An application cache policy STRONGER than `no-cache` (no-store,
//   private) must survive open()'s canonicalization from EVERY structured
//   source — set() and preset() here — merged into ONE Cache-Control
//   field. (Each SSE stream closes its dedicated connection, so the
//   companion cases live in their own single-request specs: 3.9/3.10.)
$head = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/event-stream\r\n"
   . "Cache-Control: no-store, private, no-cache\r\n"
   . "X-Accel-Buffering: no\r\n"
   . "Transfer-Encoding: chunked\r\n"
   . "\r\n";
$event = "data: merged\n\n";
$expected = $head . dechex(strlen($event)) . "\r\n{$event}\r\n" . "0\r\n\r\n";

return new Specification(
   description: 'It should merge the application Cache-Control with no-cache, not replace it',

   request: function () {
      return "GET /events-cache HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   responseLength: strlen($expected),
   response: function (Request $Request, Response $Response): Response {
      // @ Stronger restrictions from set() AND preset()
      $Response->Header->set('Cache-Control', 'no-store');
      $Response->Header->preset('cache-control', 'private');

      $SSE = $Response->SSE;
      $SSE->heartbeat = 0;

      $SSE->open();
      $SSE->send('merged');
      $SSE->close();

      // ! Restore the worker-persistent preset (open() only masks it for
      //   this response; the entry itself would leak into later requests)
      $Response->Header->preset('cache-control', null);

      return $Response;
   },

   test: function ($response) use ($expected) {
      // @ Assert — byte-exact: one merged Cache-Control field
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Application Cache-Control directives were not merged';
      }

      return true;
   }
);
