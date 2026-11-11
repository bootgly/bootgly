<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


// ! A `Tick` at 100 ms: each event carries the milliseconds since `open()`,
//   in four digits, so the wire length is fixed and the client can read the
//   server's own clock. The producer ends the stream after the eighth event.
$head = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/event-stream\r\n"
   . "Cache-Control: no-cache\r\n"
   . "X-Accel-Buffering: no\r\n"
   . "Transfer-Encoding: chunked\r\n"
   . "\r\n";
$event = "data: 0000\n\n";
$chunk = dechex(strlen($event)) . "\r\n{$event}\r\n";
$events = 8;
$length = strlen($head) + $events * strlen($chunk) + strlen("0\r\n\r\n");

return new Test(
   description: 'It should run a 100 ms Tick: eight events within one second, one per period',

   request: function () {
      return "GET /sse-tick-subsecond HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response) use ($events): Response {
      $ticks = 0;
      $opened = hrtime(true);

      $SSE = $Response->SSE;
      $SSE->heartbeat = 0;

      $SSE->open(
         Tick: static function (SSE $SSE) use (&$ticks, $opened, $events): void {
            $SSE->send(sprintf('%04d', min(9999, intdiv(hrtime(true) - $opened, 1_000_000))));
            if (++$ticks === $events) {
               $SSE->close();
            }
         },
         interval: 0.1
      );

      return $Response;
   },
   responseLength: $length,

   test: function ($response) use ($head, $events) {
      // @ Assert
      $matched = preg_match_all('/[0-9a-f]+\r\ndata: (\d{4})\n\n\r\n/', substr($response, strlen($head)), $Matches);
      $Elapsed = array_map('intval', $Matches[1] ?? []);
      $Gaps = [];
      foreach ($Elapsed as $index => $elapsed) {
         if ($index > 0) {
            $Gaps[] = $elapsed - $Elapsed[$index - 1];
         }
      }

      if (
         str_starts_with($response, $head) === false
         || str_ends_with($response, "\n\n\r\n0\r\n\r\n") === false
         || $matched !== $events
         // ? Never before its interval, and eight within one second
         || $Elapsed[0] < 100 || $Elapsed[$events - 1] > 1000
         // ? One per period: no burst, no skipped period
         || min($Gaps) < 50 || max($Gaps) > 200
      ) {
         Vars::$labels = ['HTTP Response:', 'Elapsed (ms):'];
         dump(json_encode($response), json_encode($Elapsed));
         return 'SSE 100 ms Tick did not deliver eight events in one second, one per period';
      }

      return true;
   }
);
