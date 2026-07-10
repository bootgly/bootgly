<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// ! The `no-cache` presence check is directive-exact: an extension
//   carrying it as a substring (`x-no-cache=1`) does not count — the
//   mandatory directive is still appended. queue()d lines are removed,
//   never merged (documented API contract: queue() is a raw-line escape
//   hatch, not a policy source).
$head = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/event-stream\r\n"
   . "Cache-Control: x-no-cache=1, no-cache\r\n"
   . "X-Accel-Buffering: no\r\n"
   . "Transfer-Encoding: chunked\r\n"
   . "\r\n";
$event = "data: exact\n\n";
$expected = $head . dechex(strlen($event)) . "\r\n{$event}\r\n" . "0\r\n\r\n";

return new Specification(
   description: 'It should match the no-cache directive exactly, never as a substring',

   request: function () {
      return "GET /events-cache-exact HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   responseLength: strlen($expected),
   response: function (Request $Request, Response $Response): Response {
      $Response->Header->set('Cache-Control', 'x-no-cache=1');
      $Response->Header->queue('Cache-Control', 'no-store');

      $SSE = $Response->SSE;
      $SSE->heartbeat = 0;

      $SSE->open();
      $SSE->send('exact');
      $SSE->close();

      return $Response;
   },

   test: function ($response) use ($expected) {
      // @ Assert — byte-exact: the extension does not satisfy `no-cache`
      //   and the queued line is gone
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Substring/queued Cache-Control was not canonicalized correctly';
      }

      return true;
   }
);
