<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Boundary;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * BG-14: a boundary may settle the generation from inside `recover()` — an
 * SSE handoff, or a nested `defer()` whose child answers later. The handoff's
 * wire is the only wire: nothing is serialized for the parent once its token
 * settled, and the Session written before the throw is persisted at the
 * handoff.
 */
$Sse = new Boundary('sse', mode: 'handoff');
$Child = new Boundary('child', mode: 'nested');
$Probe = new class {
   public string $cookie = '';
   /** @var array<string,mixed> */
   public array $seed = [];
   /** @var array<string,mixed> */
   public array $handoff = [];
   public string $stream = '';
   /** @var array<string,mixed> */
   public array $read = [];
   /** @var array<string,mixed> */
   public array $nested = [];
   public string $trailing = '';
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
         //   head — keep reading for the event, then a while longer for any
         //   wire a second answer would append
         $Probe->handoff = Client::send($Socket, Client::request('/deferred/recover/handoff', $testIndex, $headers), 4.0);
         $Probe->stream = (string) ($Probe->handoff['body'] ?? '');
         $deadline = microtime(true) + 1.0;
         $settled = null;
         while (microtime(true) < $deadline) {
            if ($settled === null && str_contains($Probe->stream, "\n\n")) {
               $settled = microtime(true) + 0.5;
            }
            if ($settled !== null && microtime(true) >= $settled) {
               break;
            }
            $read = [$Socket];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 50_000) === 1) {
               $chunk = @fread($Socket, 65536);
               // ? A zero-length read is EOF only when the peer closed
               if ($chunk === false || ($chunk === '' && feof($Socket))) {
                  break;
               }
               $Probe->stream .= (string) $chunk;
            }
         }
         Client::close($Socket);

         $Socket = Client::open($hostPort);
         $Probe->read = Client::send($Socket, Client::request('/deferred/session/read', $testIndex, $headers), 4.0);
         Client::close($Socket);

         // @ A nested defer() from inside recover(): the child's answer is
         //   the first and only thing on the wire
         $Socket = Client::open($hostPort);
         $Probe->nested = Client::send($Socket, Client::request('/deferred/recover/nested', $testIndex), 4.0);
         // ! Drain the wire for a while — everything the peer appends after
         //   the child's answer is evidence; a close is recorded, not lost
         $deadline = microtime(true) + 0.5;
         while (microtime(true) < $deadline) {
            $read = [$Socket];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 50_000) === 1) {
               $chunk = @fread($Socket, 65536);
               if ($chunk === false || ($chunk === '' && feof($Socket))) {
                  $Probe->trailing .= 'closed';
                  break;
               }
               $Probe->trailing .= (string) $chunk;
            }
         }
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Sse, $Child): Generator {
      yield $Router->route('/deferred/recover/handoff', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response, Request $Snapshot): void {
            $Response->wait();
            $Session = $Snapshot->Session;
            if ($Session === null) {
               throw new RuntimeException('Deferred fixture lost the session snapshot.');
            }
            $Session->set('recovered', 'yes');
            throw new RuntimeException('deferred-throw');
         });
      }, GET, [$Sse]);
      yield $Router->route('/deferred/recover/nested', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            throw new RuntimeException('deferred-throw');
         });
      }, GET, [$Child]);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'cookie' => $Probe->cookie,
         'handoff' => $Probe->handoff,
         'stream' => $Probe->stream,
         'read' => $Probe->read,
         'nested' => $Probe->nested,
         'trailing' => $Probe->trailing
      ]);
      $read = json_decode((string) ($Probe->read['body'] ?? ''), true);
      $read = is_array($read) ? $read : [];
      $nested = json_decode((string) ($Probe->nested['body'] ?? ''), true);
      $nested = is_array($nested) ? $nested : [];

      yield new Assertion(
         description: 'The boundary handed the generation off to an event stream and the event arrived',
         fallback: "No event stream from the boundary: {$evidence}"
      )
         ->expect([
            $Probe->handoff['code'] ?? 0,
            stripos((string) ($Probe->handoff['head'] ?? ''), 'text/event-stream') !== false,
            str_contains($Probe->stream, '"recovered":"sse"')
         ])
         ->to->be([200, true, true])
         ->assert();

      yield new Assertion(
         description: 'Nothing is appended after the handoff — the stream is the only wire',
         fallback: "A second answer followed the handoff: {$evidence}"
      )
         ->expect(preg_match_all('#HTTP/1\.1 \d{3}#', $Probe->stream))
         ->to->be(0)
         ->assert();

      yield new Assertion(
         description: "A nested defer() from inside recover() answers through its child, and only through it",
         fallback: "The parent serialized something around the child's answer: {$evidence}"
      )
         ->expect([
            $Probe->nested['code'] ?? 0,
            $nested['recovered'] ?? null,
            $nested['nested'] ?? null,
            preg_match_all('#HTTP/1\.1 \d{3}#', $Probe->trailing)
         ])
         ->to->be([200, 'child', true, 0])
         ->assert();

      yield new Assertion(
         description: 'The Session write made before the throw is persisted at the handoff',
         fallback: "The write before the recovered handoff did not persist: {$evidence}"
      )
         ->expect([$read['sync'] ?? null, $read['recovered'] ?? null])
         ->to->be(['seed', 'yes'])
         ->assert();
   })
);
