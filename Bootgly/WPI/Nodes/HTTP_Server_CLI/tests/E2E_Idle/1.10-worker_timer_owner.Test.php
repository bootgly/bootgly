<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * One timer owner per worker, live: the worker's loop offers recurring
 * timers, so the worker's one-second ticker rides it — the master watchdog,
 * which also runs the connections' supervision sweep: ONE loop timer, whatever
 * the number of connections — and the static Timer holds no task: its SIGALRM
 * is never armed by the framework. The idle
 * expiration keeps its whole-second window: a silent connection goes N
 * seconds (2 on this suite's server) after it was established, on the next
 * one-second sweep.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $before = [];
   /** @var array<string,mixed> */
   public array $after = [];
   public null|float $reaped = null;
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         // @ Two silent connections beside the one that asks
         $Silent = Client::open($hostPort);
         $Other = Client::open($hostPort);
         $Asker = Client::open($hostPort);
         $Probe->before = (array) json_decode(
            Client::send($Asker, Client::request('/idle/owners', $testIndex), 3.0)['body'],
            true
         );
         $Probe->reaped = Client::wait($Silent, 6.0);
         Client::wait($Other, 2.0);
         Client::close($Silent);
         Client::close($Other);
         Client::close($Asker);

         // @ Every reaper left with its connection
         usleep(200_000);
         $Asker = Client::open($hostPort);
         $Probe->after = (array) json_decode(
            Client::send($Asker, Client::request('/idle/owners', $testIndex), 3.0)['body'],
            true
         );
         Client::close($Asker);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /idle/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'before' => $Probe->before,
         'after' => $Probe->after,
         'reaped' => $Probe->reaped
      ]);
      $before = $Probe->before;
      $after = $Probe->after;

      yield new Assertion(
         description: 'The worker loop owns the timers: no static Timer task exists',
         fallback: "The static Timer holds a task in a worker whose loop has recurring timers: {$evidence}"
      )
         ->expect([$before['repeating'] ?? null, $before['static'] ?? null, $before['timers'] ?? null])
         ->to->be([true, 0, 0])
         ->assert();

      yield new Assertion(
         description: 'Every connection is on the one supervision sweep, run by the worker ticker: the loop carries a single timer',
         fallback: "The loop does not carry exactly the worker ticker, sweeping every connection: {$evidence}"
      )
         ->expect(
            ($before['connections'] ?? 0) >= 3
            && ($before['watched'] ?? -1) === ($before['connections'] ?? 0)
            && ($before['supervised'] ?? -1) === ($before['connections'] ?? 0)
            && ($before['loop'] ?? -1) === 1
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'A silent connection is reaped on the sweep that follows its idle interval, counted in whole seconds',
         fallback: "The silent connection was not reaped within its whole-second window: {$evidence}"
      )
         ->expect($Probe->reaped ?? -1.0)
         ->to->delimit(0.9, 3.4)
         ->assert();

      yield new Assertion(
         description: 'Closed connections left the sweep, and no timer behind on either owner',
         fallback: "A closed connection stayed supervised or left a timer behind: {$evidence}"
      )
         ->expect(
            ($after['static'] ?? -1) === 0
            && ($after['timers'] ?? -1) === 0
            && ($after['connections'] ?? 0) >= 1
            && ($after['watched'] ?? -1) === ($after['connections'] ?? 0)
            && ($after['supervised'] ?? -1) === ($after['connections'] ?? 0)
            && ($after['loop'] ?? -1) === 1
         )
         ->to->be(true)
         ->assert();
   })
);
