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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Barrier;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Deferrer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * A synchronous static writer, then deferred work around it:
 *
 * - a static route a global middleware defers ahead of routing (the routing
 *   runs inside the deferral, on the generation's copy of the Route) reads
 *   no entry the synchronous writer left, and neither does the next
 *   synchronous static request;
 * - a static handler that writes from deferred work and parks keeps its own
 *   entry across the park while another client's synchronous writer and
 *   reader are served, and that client never reads it.
 */
$PersistentRouter = new Router;
$Barrier = new Barrier;
$Probe = new class {
   /** @var array<int,string> */
   public array $writes = [];
   /** @var array<string,mixed> */
   public array $deferred = [];
   /** @var array<string,mixed> */
   public array $synchronous = [];
   public bool $held = false;
   /** @var array<string,mixed> */
   public array $interleaved = [];
   /** @var array<string,mixed> */
   public array $parked = [];
   /** @var array<string,mixed> */
   public array $after = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),
   middlewares: [new Deferrer('/r19/g/', $PersistentRouter)],

   request: static function (string $hostPort, int $testIndex) use ($Barrier, $Probe): string {
      try {
         $send = static function (string $path, array $headers = []) use ($hostPort, $testIndex): array {
            $Socket = Client::open($hostPort);
            $answer = Client::send($Socket, Client::request($path, $testIndex, $headers), 4.0);
            Client::close($Socket);

            return $answer;
         };

         // @ A synchronous static writer, then routing inside a deferral
         $Probe->writes[] = $send('/r19/login', ['X-User' => 'alice'])['body'] ?? '';
         $Probe->deferred = $send('/r19/g/profile');
         $Probe->synchronous = $send('/r19/profile');

         // @ A deferred static writer parks after its write
         $Parked = Client::open($hostPort);
         @fwrite($Parked, Client::request('/r19/g/stash', $testIndex, ['X-User' => 'carol']));
         $Probe->held = $Barrier->expect();
         // @ Another client writes and reads synchronously meanwhile
         $Probe->writes[] = $send('/r19/login', ['X-User' => 'bob'])['body'] ?? '';
         $Probe->interleaved = $send('/r19/profile');
         // @ The parked work resumes and reads its own entry
         $Barrier->release();
         $Probe->parked = Client::send($Parked, '', 4.0);
         Client::close($Parked);
         $Probe->after = $send('/r19/profile');
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /r19/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Barrier, $PersistentRouter): Response {
      $Profile = function (Request $Request, Response $Response): Response {
         return $Response(body: (string) json_encode(iterator_to_array($this->Params))); // @phpstan-ignore variable.undefined
      };
      $PersistentRouter->route('/r19/login', function (Request $Request, Response $Response): Response {
         $this->Params->user = $Request->Header->get('X-User') ?? 'none'; // @phpstan-ignore variable.undefined
         return $Response(body: (string) json_encode(iterator_to_array($this->Params))); // @phpstan-ignore variable.undefined
      }, GET);
      $PersistentRouter->route('/r19/g/stash', function (Request $Request, Response $Response) use ($Barrier): Response {
         $this->Params->user = $Request->Header->get('X-User') ?? 'none'; // @phpstan-ignore variable.undefined
         $Barrier->hold($Response);

         return $Response(body: (string) json_encode(iterator_to_array($this->Params))); // @phpstan-ignore variable.undefined
      }, GET);
      $PersistentRouter->route('/r19/g/profile', $Profile, GET);
      $PersistentRouter->route('/r19/profile', $Profile, GET);
      $PersistentRouter->route('/r19/ping', static function (Request $Request, Response $Response): Response {
         return $Response(body: 'pong');
      }, GET);

      foreach ($PersistentRouter->routing() as $Routed) {
         if ($Routed instanceof Response) {
            return $Routed;
         }
      }

      return $Response(code: 404, body: 'not-routed');
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'writes' => $Probe->writes,
         'deferred' => $Probe->deferred,
         'synchronous' => $Probe->synchronous,
         'held' => $Probe->held,
         'interleaved' => $Probe->interleaved,
         'parked' => $Probe->parked,
         'after' => $Probe->after
      ]);

      yield new Assertion(
         description: 'Each synchronous writer stores its entry for its own request',
         fallback: "A writer did not store its entry: {$evidence}"
      )
         ->expect($Probe->writes)
         ->to->be(['{"user":"alice"}', '{"user":"bob"}'])
         ->assert();

      yield new Assertion(
         description: 'A static route routed inside a deferral reads no entry a synchronous writer left',
         fallback: "The deferred static route read a previous request's entry: {$evidence}"
      )
         ->expect($Probe->deferred['body'] ?? null)
         ->to->be('[]')
         ->assert();

      yield new Assertion(
         description: 'The next synchronous static request reads none either',
         fallback: "The synchronous static route read a previous request's entry: {$evidence}"
      )
         ->expect($Probe->synchronous['body'] ?? null)
         ->to->be('[]')
         ->assert();

      yield new Assertion(
         description: 'Another client served while deferred work parks reads no entry of it',
         fallback: "The interleaved request read a foreign entry: {$evidence}"
      )
         ->expect([$Probe->held, $Probe->interleaved['body'] ?? null])
         ->to->be([true, '[]'])
         ->assert();

      yield new Assertion(
         description: 'The parked work keeps its own entry, and nothing reads it after',
         fallback: "The parked work lost its entry or leaked it: {$evidence}"
      )
         ->expect([$Probe->parked['body'] ?? null, $Probe->after['body'] ?? null])
         ->to->be(['{"user":"carol"}', '[]'])
         ->assert();
   })
);
