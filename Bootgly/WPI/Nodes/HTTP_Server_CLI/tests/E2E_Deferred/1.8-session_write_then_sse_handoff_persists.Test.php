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
 * BG-15: a Session written after the first `wait()` is persisted even when
 * the deferral never reaches its own answer because it hands off to SSE —
 * the handoff settles the generation from inside the work, and the save must
 * still happen (the synchronous cycle persists before an inline SSE too).
 */
$Probe = new class {
   public string $cookie = '';
   /** @var array<string,mixed> */
   public array $seed = [];
   /** @var array<string,mixed> */
   public array $sse = [];
   public string $stream = '';
   /** @var array<string,mixed> */
   public array $read = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->seed = Client::send($Socket, Client::request('/deferred/session/seed', $testIndex), 4.0);
         $matches = [];
         if (preg_match('/Set-Cookie: PHPSID=([^;\r\n]+)/i', $Probe->seed['head'], $matches) === 1) {
            $Probe->cookie = $matches[1];
         }
         $headers = ['Cookie' => "PHPSID={$Probe->cookie}"];
         // ! An event stream has no Content-Length: `send()` returns at the
         //   head — keep reading the wire for the event itself
         $Probe->sse = Client::send($Socket, Client::request('/deferred/session/sse', $testIndex, $headers), 4.0);
         $Probe->stream = (string) ($Probe->sse['body'] ?? '');
         $deadline = microtime(true) + 1.0;
         while (microtime(true) < $deadline && str_contains($Probe->stream, "\n\n") === false) {
            $read = [$Socket];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 50_000) === 1) {
               $chunk = @fread($Socket, 65536);
               if ($chunk === false || $chunk === '') {
                  break;
               }
               $Probe->stream .= $chunk;
            }
         }
         Client::close($Socket);

         $Socket = Client::open($hostPort);
         $Probe->read = Client::send($Socket, Client::request('/deferred/session/read', $testIndex, $headers), 4.0);
         Client::close($Socket);
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
      $evidence = json_encode([
         'error' => $Probe->error,
         'cookie' => $Probe->cookie,
         'sse' => $Probe->sse,
         'stream' => $Probe->stream,
         'read' => $Probe->read
      ]);
      $read = json_decode((string) ($Probe->read['body'] ?? ''), true);
      $read = is_array($read) ? $read : [];

      yield new Assertion(
         description: 'The deferral handed off to an event stream and the event arrived',
         fallback: "No event stream: {$evidence}"
      )
         ->expect([
            $Probe->sse['code'] ?? 0,
            stripos($Probe->sse['head'] ?? '', 'text/event-stream') !== false,
            str_contains($Probe->stream, '"sse":true')
         ])
         ->to->be([200, true, true])
         ->assert();

      yield new Assertion(
         description: 'The Session write made before the SSE handoff is persisted',
         fallback: "The write before the handoff did not persist: {$evidence}"
      )
         ->expect([$read['sync'] ?? null, $read['sse'] ?? null])
         ->to->be(['seed', 'yes'])
         ->assert();
   })
);
