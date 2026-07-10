<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// ! HEAD on an SSE route must never hijack the connection nor stream
//   content (RFC 9110 §9.3.2) — and it must NOT advertise a Content-Length
//   either: §8.6 requires a HEAD Content-Length to match the GET
//   representation, which is an unsized live stream. The response ends at
//   the header block. A follow-up request on the same connection proves
//   there was no hijack.
$head = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/event-stream\r\n"
   . "Cache-Control: no-cache\r\n"
   . "X-Accel-Buffering: no\r\n"
   . "\r\n";
$after = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/html; charset=UTF-8\r\n"
   . "Content-Length: 10\r\n"
   . "\r\n"
   . "after-head";

return new Specification(
   description: 'It should answer HEAD on an SSE route without hijacking the connection',

   requests: [
      function () {
         return "HEAD /sse-head HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /sse-head-after HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   responseLengths: [strlen($head), strlen($after)],
   response: function (Request $Request, Response $Response): Response {
      // ? Second request — only reachable if HEAD did NOT hijack
      if ($Request->method !== 'HEAD') {
         return $Response(body: 'after-head');
      }

      // @ Stale framing metadata from the application — neither may
      //   survive on a HEAD response (the GET representation is unsized)
      $Response(headers: ['Content-Length' => '999']);
      $Response->Header->set('transfer-encoding', 'gzip');

      $SSE = $Response->SSE;
      $SSE->heartbeat = 0;

      $SSE->open();
      // ! Must be inert: the stream never opened
      $SSE->send('never');
      $SSE->close();

      return $Response;
   },

   test: function (array $responses) use ($head, $after) {
      [$probe, $follow] = $responses;

      // @ Assert — content-free metadata head (no Content-Length and no
      //   Transfer-Encoding in any case variant), then a normal response
      if (
         $probe !== $head
         || stripos($probe, 'content-length') !== false
         || stripos($probe, 'transfer-encoding') !== false
         || $follow !== $after
      ) {
         Vars::$labels = ['HEAD:', 'Follow-up:', 'Expected head:', 'Expected follow-up:'];
         dump(json_encode($probe), json_encode($follow), json_encode($head), json_encode($after));
         return 'HEAD on the SSE route leaked content or hijacked the connection';
      }

      return true;
   }
);
