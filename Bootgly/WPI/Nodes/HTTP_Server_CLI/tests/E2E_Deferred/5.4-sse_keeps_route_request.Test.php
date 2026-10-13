<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


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
 * H-HSC-5 (regression guard): an event stream mounted in a SYNCHRONOUS route
 * takes no snapshot — its ticks run long after the response head was written
 * and read the route's live Request (`use ($Request)`). The encoder drops the
 * head of a written request (`Request::shed()`), but mounting SSE pins that
 * Request (`Request::pin()`), so every tick still reads its URI, query,
 * headers, cookies and bearer token.
 *
 * Green before H-HSC-5 too (nothing was shed then): this spec guards the pin,
 * and turns red when `Response::attach()` stops pinning or the encoder sheds
 * a pinned Request.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $sse = [];
   public string $stream = '';
   /** @var array<int,mixed> */
   public array $events = [];
   public bool $ended = false;
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         // ! An event stream has no Content-Length: `send()` returns at the
         //   head — keep reading the wire for the ticks and the terminal chunk
         $Probe->sse = Client::send($Socket, Client::request('/deferred/sse/live?q=sse', $testIndex, [
            'X-Echo' => 'sse',
            'Cookie' => 'who=sse',
            'Authorization' => 'Bearer sse-token'
         ]), 4.0);
         $Probe->stream = (string) ($Probe->sse['body'] ?? '');
         // @@ Two ticks one interval apart, then the stream closes the connection
         $deadline = microtime(true) + 6.0;
         while (microtime(true) < $deadline && str_contains($Probe->stream, "0\r\n\r\n") === false) {
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

         $Probe->ended = str_contains($Probe->stream, "0\r\n\r\n");
         $matches = [];
         preg_match_all('/^data: (.*)$/m', $Probe->stream, $matches);
         foreach ($matches[1] as $data) {
            $Probe->events[] = json_decode(rtrim($data, "\r"), true);
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'sse' => $Probe->sse,
         'stream' => $Probe->stream
      ]);

      yield new Assertion(
         description: 'The synchronous route opened an event stream that ticked twice and ended',
         fallback: "No complete event stream: {$evidence}"
      )
         ->expect([
            $Probe->sse['code'] ?? 0,
            stripos($Probe->sse['head'] ?? '', 'text/event-stream') !== false,
            count($Probe->events),
            $Probe->ended
         ])
         ->to->be([200, true, 2, true])
         ->assert();

      $values = [
         'URI' => '/deferred/sse/live?q=sse',
         'query' => 'sse',
         'echo' => 'sse',
         'cookie' => 'sse',
         'token' => 'sse-token'
      ];

      yield new Assertion(
         description: 'Every tick reads the route request\'s own URI, query, header, cookie and token',
         fallback: "A tick read a dropped head: {$evidence}"
      )
         ->expect($Probe->events)
         ->to->be([
            ['tick' => 1, ...$values],
            ['tick' => 2, ...$values]
         ])
         ->assert();
   })
);
