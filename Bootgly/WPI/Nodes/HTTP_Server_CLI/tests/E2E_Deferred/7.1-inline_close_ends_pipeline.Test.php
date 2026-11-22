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
 * RFC 9112 §9.6: a deferral that completes inline — its work never
 * suspends — on a request that asked to close is the last exchange on its
 * connection, as a synchronous answer is. Over HTTP/1.1 the answer says
 * `Connection: close`; over HTTP/1.0 without keep-alive it closes silently.
 * Either way the connection closes after it, and the request pipelined
 * behind it in the same write is never answered.
 */
$Probe = new class {
   /** @var array<string,array{wire:string,eof:bool}> */
   public array $legs = [];
   public string $error = '';
};

// ! Write both requests at once, then read until the server closes
$Exchange = static function (string $hostPort, string $requests): array {
   $Socket = Client::open($hostPort);
   @fwrite($Socket, $requests);

   $wire = '';
   $eof = false;
   $deadline = microtime(true) + 3.0;
   // @@
   while (microtime(true) < $deadline) {
      $read = [$Socket];
      $write = null;
      $except = null;
      if (@stream_select($read, $write, $except, 0, 50_000) !== 1) {
         continue;
      }
      $chunk = @fread($Socket, 65536);
      if ($chunk === false || $chunk === '') {
         $eof = true;
         break;
      }
      $wire .= $chunk;
   }
   Client::close($Socket);

   // :
   return ['wire' => $wire, 'eof' => $eof];
};

// ! Split the wire into its complete Content-Length framed answers
$Split = static function (string $wire): array {
   $answers = [];
   $rest = $wire;
   // @@
   while (($separator = strpos($rest, "\r\n\r\n")) !== false) {
      $head = substr($rest, 0, $separator);
      $matches = [];
      // ? Every fixture answer is Content-Length framed; stop at a partial one
      if (preg_match('/\r\nContent-Length:[ \t]*(\d+)/i', $head, $matches) !== 1) {
         break;
      }
      $length = (int) $matches[1];
      if (strlen($rest) - $separator - 4 < $length) {
         break;
      }
      $decoded = json_decode(substr($rest, $separator + 4, $length), true);
      $answers[] = [
         'head' => $head,
         'tag' => is_array($decoded) ? (string) ($decoded['tag'] ?? '?') : '?'
      ];
      $rest = substr($rest, $separator + 4 + $length);
   }

   // :
   return $answers;
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $Exchange): string {
      try {
         // @ HTTP/1.1 asking to close, a request pipelined behind it
         $closing = Client::request('/deferred/inline?tag=A', $testIndex, ['Connection' => 'close']);
         $behind = Client::request('/deferred/tag?tag=B', $testIndex);
         $Probe->legs['HTTP/1.1'] = $Exchange($hostPort, "{$closing}{$behind}");

         // @ HTTP/1.0 without keep-alive, a request pipelined behind it
         $closing = "GET /deferred/inline?tag=C HTTP/1.0\r\nHost: localhost\r\nX-Bootgly-Test: {$testIndex}\r\n\r\n";
         $behind = "GET /deferred/tag?tag=D HTTP/1.0\r\nHost: localhost\r\nX-Bootgly-Test: {$testIndex}\r\n\r\n";
         $Probe->legs['HTTP/1.0'] = $Exchange($hostPort, "{$closing}{$behind}");
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe, $Split): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'legs' => $Probe->legs]);
      $HTTP11 = $Probe->legs['HTTP/1.1'] ?? ['wire' => '', 'eof' => false];
      $HTTP10 = $Probe->legs['HTTP/1.0'] ?? ['wire' => '', 'eof' => false];
      $Answers11 = $Split($HTTP11['wire']);
      $Answers10 = $Split($HTTP10['wire']);

      yield new Assertion(
         description: 'HTTP/1.1: only the inline deferral that asked to close is answered',
         fallback: "Wrong answers on a closing connection: {$evidence}"
      )
         ->expect(array_column($Answers11, 'tag'))
         ->to->be(['A'])
         ->assert();

      yield new Assertion(
         description: 'HTTP/1.1: that answer says `Connection: close`',
         fallback: "The answer did not announce the close: {$evidence}"
      )
         ->expect(preg_match('/\r\nConnection:[ \t]*close\r?$/im', $Answers11[0]['head'] ?? ''))
         ->to->be(1)
         ->assert();

      yield new Assertion(
         description: 'HTTP/1.1: the connection closes after it',
         fallback: "The connection stayed open: {$evidence}"
      )
         ->expect($HTTP11['eof'])
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'HTTP/1.0: only the inline deferral is answered',
         fallback: "Wrong answers on a closing HTTP/1.0 connection: {$evidence}"
      )
         ->expect(array_column($Answers10, 'tag'))
         ->to->be(['C'])
         ->assert();

      yield new Assertion(
         description: 'HTTP/1.0: the connection closes after it',
         fallback: "The HTTP/1.0 connection stayed open: {$evidence}"
      )
         ->expect($HTTP10['eof'])
         ->to->be(true)
         ->assert();
   })
);
