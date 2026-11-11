<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


// ! A peer close while a 100 ms Tick is running. The runner never closes the
//   socket it replaces, so the request closure opens the stream on a socket
//   of its own, reads three events and closes it. The request the runner
//   then sends takes ~400 ms to arrive (one byte every 10 ms): a supervisor
//   that survived the close would tick several times before the handler
//   reads the record the stream left in the worker. The record also names the
//   loop timers the stream registered, so "stopped" means cancelled on the
//   loop — not merely silenced by the stream's own closed guard.
$head = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/html; charset=UTF-8\r\n"
   . "Content-Length: 38\r\n"
   . "\r\n";
$length = strlen($head) + 38;

// ! Worker-side record: this closure state lives in the one server worker
$State = new stdClass;
$State->ticks = 0;
$State->closed = 0;
$State->at = -1;
// @ Ids of the loop timers the stream registered
$State->timers = [];
// @ The recurring timers this worker's loop holds right now
$Timers = static function (): array {
   $Loop = HTTP_Server_CLI::$Event;

   return $Loop instanceof Select
      ? array_keys(new ReflectionProperty(Select::class, 'Repeaters')->getValue($Loop))
      : [];
};

return new Test(
   description: 'It should stop the sub-second Tick when the peer closes the stream',

   request: function ($host, $index) {
      // @ Open the stream on an own socket, dispatched to this case's handler
      $Stream = stream_socket_client("tcp://{$host}", $code, $message, 2.0);
      if ($Stream === false) {
         throw new RuntimeException("Could not open the stream socket: {$code} {$message}");
      }
      stream_set_timeout($Stream, 2);
      fwrite($Stream, "GET /sse-tick-stream HTTP/1.1\r\nX-Bootgly-Test: {$index}\r\nHost: localhost\r\n\r\n");
      $received = '';
      $deadline = microtime(true) + 2.0;
      while (substr_count($received, "data: tick\n\n") < 3 && microtime(true) < $deadline) {
         $bytes = fread($Stream, 4096);
         if ($bytes === false || ($bytes === '' && feof($Stream))) {
            break;
         }
         $received .= $bytes;
      }
      // @ The peer goes away while the stream is live
      fclose($Stream);
      if (substr_count($received, "data: tick\n\n") < 3) {
         throw new RuntimeException('The stream did not deliver three events: ' . json_encode($received));
      }

      // @ ~400 ms later, ask the worker what the stream left
      yield "GET /sse-tick-state HTTP/1.1\r\n";
      yield "Host: localhost\r\n";
      yield 'X-Wait: ';
      for ($byte = 0; $byte < 40; $byte++) {
         yield 'a';
      }
      yield "\r\n\r\n";
   },
   response: function (Request $Request, Response $Response) use ($State, $Timers): Response {
      if ($Request->URL === '/sse-tick-stream') {
         $SSE = $Response->SSE;
         $SSE->heartbeat = 0;
         $Before = $Timers();

         $SSE->open(
            Tick: static function (SSE $SSE) use ($State): void {
               $State->ticks++;
               $SSE->send('tick');
            },
            interval: 0.1,
            Close: static function () use ($State): void {
               $State->closed++;
               $State->at = $State->ticks;
            }
         );
         $State->timers = array_values(array_diff($Timers(), $Before));

         return $Response;
      }

      return $Response(body: sprintf(
         'closed=%d;ticks=%03d;at=%03d;own=%d;left=%d',
         min(9, $State->closed), min(999, $State->ticks), min(999, max(0, $State->at)),
         min(9, count($State->timers)), min(9, count(array_intersect($State->timers, $Timers())))
      ));
   },
   responseLength: $length,

   test: function ($response) use ($head) {
      // @ Assert
      $matched = preg_match('/^closed=(\d);ticks=(\d{3});at=(\d{3});own=(\d);left=(\d)$/', substr($response, strlen($head)), $Matches);
      $closed = (int) ($Matches[1] ?? -1);
      $ticks = (int) ($Matches[2] ?? -1);
      $at = (int) ($Matches[3] ?? -1);
      $own = (int) ($Matches[4] ?? -1);
      $left = (int) ($Matches[5] ?? -1);

      if (
         str_starts_with($response, $head) === false
         || $matched !== 1
         // ? The Close hook ran exactly once
         || $closed !== 1
         // ? No Tick after the teardown, ~400 ms later
         || $ticks !== $at
         // ? ...and the stream ticked until the peer left, then promptly stopped
         || $ticks < 3 || $ticks > 5
         // ? The stream registered its Tick on the loop, and the teardown
         //   cancelled it there
         || $own !== 1 || $left !== 0
      ) {
         Vars::$labels = ['HTTP Response:'];
         dump(json_encode($response));
         return 'The sub-second Tick did not stop when the peer closed the stream — observed: ' . json_encode(substr($response, strlen($head)));
      }

      return true;
   }
);
