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
 * A client that leaves while its deferral is parked: the work is never resumed
 * (its catch never runs) and it is destroyed at the reactor's next safe point,
 * so its finally runs promptly — without waiting for the cycle collector.
 * The second leg reuses a pooled Fiber (the one /idle/quick just returned),
 * which is where a self-reference kept by the pooled loop would survive.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $first = [];
   /** @var array<string,mixed> */
   public array $second = [];
   /** @var array<string,mixed> */
   public array $quick = [];
   /** @var array<string,mixed> */
   public array $again = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      // ! Abandon a park, then watch the worker's evidence for the finally
      $leave = static function (int $expected) use ($hostPort, $testIndex): array {
         $Reporter = Client::open($hostPort);
         $Leaver = Client::open($hostPort);
         @fwrite($Leaver, Client::request('/idle/leave', $testIndex));
         // @ Let the deferral park before the socket goes away
         $deadline = microtime(true) + 1.0;
         $report = [];
         while (microtime(true) < $deadline) {
            usleep(25_000);
            $report = (array) json_decode(Client::send($Reporter, Client::request('/idle/report', $testIndex), 1.0)['body'] ?? '', true);
            if (($report['leave']['parked'] ?? 0) >= $expected) {
               break;
            }
         }
         $left = microtime(true);
         Client::close($Leaver);
         // @ Poll until the worker reports the finally — or give up loudly
         $polls = 0;
         $deadline = microtime(true) + 2.0;
         while (microtime(true) < $deadline) {
            $polls++;
            $report = (array) json_decode(Client::send($Reporter, Client::request('/idle/report', $testIndex), 1.0)['body'] ?? '', true);
            if (($report['leave']['finally'] ?? 0) >= $expected) {
               break;
            }
            usleep(25_000);
         }
         Client::close($Reporter);

         return ['report' => $report, 'polls' => $polls, 'gap' => microtime(true) - $left];
      };

      try {
         $Socket = Client::open($hostPort);
         Client::send($Socket, Client::request('/idle/reset', $testIndex), 2.0);
         Client::close($Socket);

         $Probe->first = $leave(1);

         $Socket = Client::open($hostPort);
         $Probe->quick = Client::send($Socket, Client::request('/idle/quick', $testIndex), 2.0);
         Client::close($Socket);
         $Probe->second = $leave(2);

         $Socket = Client::open($hostPort);
         $Probe->again = Client::send($Socket, Client::request('/idle/quick', $testIndex), 2.0);
         Client::close($Socket);
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
      $first = $Probe->first['report']['leave'] ?? [];
      $second = $Probe->second['report']['leave'] ?? [];
      $evidence = json_encode(['error' => $Probe->error, 'first' => $Probe->first, 'quick' => $Probe->quick, 'second' => $Probe->second, 'again' => $Probe->again]);

      yield new Assertion(
         description: 'An abandoned deferral runs its finally promptly, is never resumed and its catch never runs',
         fallback: "The abandoned deferral was not released at the safe point: {$evidence}"
      )
         ->expect([$first['parked'] ?? 0, $first['finally'] ?? 0, $first['resumed'] ?? 0, $first['caught'] ?? 0])
         ->to->be([1, 1, 0, 0])
         ->assert();

      yield new Assertion(
         description: 'The finally ran within the polling budget, not at some later GC',
         fallback: "The finally took too long after the client left: {$evidence}"
      )
         ->expect($Probe->first['gap'] ?? 9.9)
         ->to->delimit(0.0, 1.0)
         ->assert();

      yield new Assertion(
         description: 'A pooled Fiber (the one /idle/quick returned) is released the same way',
         fallback: "The pooled Fiber path did not release: {$evidence}"
      )
         ->expect([
            $second['parked'] ?? 0,
            $second['finally'] ?? 0,
            $second['resumed'] ?? 0,
            $second['caught'] ?? 0,
            ($second['fibers'][1] ?? -1) === ($Probe->second['report']['quick']['fibers'][0] ?? -2)
         ])
         ->to->be([2, 2, 0, 0, true])
         ->assert();

      yield new Assertion(
         description: 'Deferred work keeps working after the abandoned parks',
         fallback: "A later deferral failed: {$evidence}"
      )
         ->expect([$Probe->quick['code'] ?? 0, $Probe->again['code'] ?? 0])
         ->to->be([200, 200])
         ->assert();
   })
);
