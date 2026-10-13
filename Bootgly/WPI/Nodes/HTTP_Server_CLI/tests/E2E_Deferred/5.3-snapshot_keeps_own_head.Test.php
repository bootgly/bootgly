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
 * H-HSC-5: once a deferral parks, the server drops the head the live
 * per-connection Request held (`Request::shed()`) — the snapshot keeps its
 * own. The deferred work still reads the headers, cookies, URI and query of
 * the request it answers after its wait, and a second request pipelined onto
 * the same connection meanwhile reads its own. A live Request the deferral
 * captured itself (`use ($Request)`) reads no head at all, and a deferral that
 * answered leaves no snapshot alive in the pooled Fiber that ran it.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $live = [];
   /** @var array<string,mixed> */
   public array $weak = [];
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
         @fwrite($Socket, Client::request('/deferred/head?q=one', $testIndex, [
            'X-Echo' => 'one',
            'Cookie' => 'who=one'
         ]));
         usleep(150_000);
         @fwrite($Socket, Client::request('/deferred/echo?q=two', $testIndex, [
            'X-Echo' => 'two',
            'Cookie' => 'who=two'
         ]));

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
            if (substr_count($Probe->wire, '"echo"') >= 2) {
               break;
            }
         }
         Client::close($Socket);

         // @ A deferral alone on its connection: the live Request it captured
         //   no longer carries the head once the deferral returned
         $Live = Client::open($hostPort);
         $Probe->live = Client::send($Live, Client::request('/deferred/live?q=three', $testIndex, [
            'X-Echo' => 'three'
         ]), 3.0);
         Client::close($Live);

         // @ A deferral that answered, then a request on the same connection
         //   (same worker): its snapshot is gone — no pooled Fiber keeps it
         $Weak = Client::open($hostPort);
         Client::send($Weak, Client::request('/deferred/weak', $testIndex), 3.0);
         $Probe->weak = Client::send($Weak, Client::request('/deferred/weak/check', $testIndex), 3.0);
         Client::close($Weak);

         // @ Split the wire into its responses (Content-Length framed)
         $rest = $Probe->wire;
         while (($separator = strpos($rest, "\r\n\r\n")) !== false) {
            $head = substr($rest, 0, $separator);
            $matches = [];
            if (preg_match('/\r\nContent-Length:[ \t]*(\d+)/i', $head, $matches) !== 1) {
               break;
            }
            $length = (int) $matches[1];
            $Probe->responses[] = json_decode(substr($rest, $separator + 4, $length), true);
            $rest = substr($rest, $separator + 4 + $length);
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
      $evidence = json_encode(['error' => $Probe->error, 'wire' => $Probe->wire]);
      $answers = [];
      foreach ($Probe->responses as $decoded) {
         if (is_array($decoded) && isset($decoded['URI'])) {
            $answers[$decoded['URI']] = $decoded;
         }
      }

      yield new Assertion(
         description: 'The deferred work reads its own head after the live Request dropped its copy',
         fallback: "The snapshot lost or changed its head: {$evidence}"
      )
         ->expect($answers['/deferred/head?q=one'] ?? null)
         ->to->be(['echo' => 'one', 'cookie' => 'one', 'URI' => '/deferred/head?q=one', 'query' => 'one'])
         ->assert();

      $live = json_decode((string) ($Probe->live['body'] ?? ''), true);
      $liveEvidence = json_encode(['error' => $Probe->error, 'live' => $Probe->live]);

      yield new Assertion(
         description: 'The live Request a deferral captured carries no head once the deferral returned',
         fallback: "The live Request kept its head: {$liveEvidence}"
      )
         ->expect($live)
         ->to->be(['live' => true, 'echo' => null, 'URI' => ''])
         ->assert();

      $weak = json_decode((string) ($Probe->weak['body'] ?? ''), true);
      $weakEvidence = json_encode(['error' => $Probe->error, 'weak' => $Probe->weak]);

      yield new Assertion(
         description: 'A deferral that answered leaves no snapshot behind in the Fiber pool',
         fallback: "The answered snapshot is still alive: {$weakEvidence}"
      )
         ->expect($weak)
         ->to->be(['created' => true, 'alive' => false])
         ->assert();

      yield new Assertion(
         description: 'The request pipelined meanwhile reads its own head',
         fallback: "The pipelined request read another head: {$evidence}"
      )
         ->expect($answers['/deferred/echo?q=two'] ?? null)
         ->to->be(['echo' => 'two', 'cookie' => 'two', 'URI' => '/deferred/echo?q=two', 'query' => 'two'])
         ->assert();
   })
);
