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
 * BG-14: the walk guard re-arms itself for as long as the walk stays parked.
 * Two boundaries that both park: the first is interrupted after one budget,
 * its Timeout travels outward, the second parks and is interrupted after one
 * more budget — the Catcher's 503 arrives within three budgets, not never.
 * Counters are never asserted: the fixture instances that run live in the
 * worker, the ones this file holds live in the runner.
 */
$Inner = new Boundary('inner-parker', mode: 'park');
$Outer = new Boundary('outer-parker', mode: 'park');
$Probe = new class {
   /** @var array<string,mixed> */
   public array $parked = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->parked = Client::send($Socket, Client::request('/deferred/recover/timeout/parked2', $testIndex), 5.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Outer, $Inner): Generator {
      yield $Router->route('/deferred/recover/timeout/parked2', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            Routes::park($Response, 10.0);
            $Response->JSON->send(['parked' => true]);
         }, timeout: 0.4);
      }, GET, [$Outer, $Inner]);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'parked' => $Probe->parked]);

      yield new Assertion(
         description: 'Two boundaries that park in turn are both bounded: the Catcher answers 503',
         fallback: "The second parked boundary was never bounded: {$evidence}"
      )
         ->expect([$Probe->parked['code'] ?? 0, $Probe->parked['body'] ?? null])
         ->to->be([503, ''])
         ->assert();

      yield new Assertion(
         description: 'The answer arrived within three budgets',
         fallback: "Unexpected timing: {$evidence}"
      )
         ->expect($Probe->parked['elapsed'] ?? 0.0)
         ->to->delimit(1.0, 3.0)
         ->assert();
   })
);
