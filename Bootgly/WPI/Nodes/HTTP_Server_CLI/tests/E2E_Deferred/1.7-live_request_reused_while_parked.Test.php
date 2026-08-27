<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * BG-15 (why the snapshot exists): while a deferral parks, a second request
 * on the SAME keep-alive connection is decoded into the live per-connection
 * Request. A `use ($Request)` inside the deferred work then describes that
 * later request — not merely an emptied one — while the snapshot still
 * describes the request the work answers.
 */
$Probe = new class {
   public string $wire = '';
   /** @var array<int,array<string,mixed>> */
   public array $responses = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         // @ Park the first exchange, then pipeline a second one onto the
         //   same connection while the first is still parked
         @fwrite($Socket, Client::request('/deferred/reuse', $testIndex));
         usleep(150_000);
         @fwrite($Socket, Client::request('/deferred/ping?tag=second', $testIndex));

         $deadline = microtime(true) + 3.0;
         while (microtime(true) < $deadline) {
            $read = [$Socket];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 50_000) === 1) {
               $chunk = @fread($Socket, 65536);
               if ($chunk === false || $chunk === '') {
                  break;
               }
               $Probe->wire .= $chunk;
            }
            if (substr_count($Probe->wire, "\r\n\r\n") >= 2 && str_contains($Probe->wire, '"snapshot"')) {
               // ? Both heads arrived; give the bodies one more slice
               usleep(50_000);
               $chunk = @fread($Socket, 65536);
               if (is_string($chunk)) {
                  $Probe->wire .= $chunk;
               }
               break;
            }
         }
         Client::close($Socket);

         // @ Split the wire into its responses (Content-Length framed)
         $rest = $Probe->wire;
         while (($separator = strpos($rest, "\r\n\r\n")) !== false) {
            $head = substr($rest, 0, $separator);
            $matches = [];
            // ? Every fixture answer is Content-Length framed; anything else
            //   cannot be split safely — stop rather than mis-frame
            if (preg_match('/\r\nContent-Length:[ \t]*(\d+)/i', $head, $matches) !== 1) {
               break;
            }
            $length = (int) $matches[1];
            $body = substr($rest, $separator + 4, $length);
            $Probe->responses[] = ['head' => $head, 'body' => $body];
            $rest = substr($rest, $separator + 4 + $length);
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'responses' => $Probe->responses]);
      $reuse = [];
      $pong = false;
      foreach ($Probe->responses as $entry) {
         $decoded = json_decode($entry['body'], true);
         if (is_array($decoded) && isset($decoded['snapshot'])) {
            $reuse = $decoded;
         }
         if ($entry['body'] === 'pong') {
            $pong = true;
         }
      }

      yield new Assertion(
         description: 'Both exchanges answered on the one connection',
         fallback: "Missing a response: {$evidence}"
      )
         ->expect([$reuse !== [], $pong])
         ->to->be([true, true])
         ->assert();

      yield new Assertion(
         description: 'The snapshot still describes the request the work answers',
         fallback: "Snapshot drifted: {$evidence}"
      )
         ->expect($reuse['snapshot'] ?? null)
         ->to->be('/deferred/reuse')
         ->assert();

      yield new Assertion(
         description: 'The live per-connection Request already describes the LATER request',
         fallback: "The live Request was not reused while parked: {$evidence}"
      )
         ->expect([$reuse['live'] ?? null, $reuse['live_queries']['tag'] ?? null])
         ->to->be(['/deferred/ping?tag=second', 'second'])
         ->assert();
   })
);
