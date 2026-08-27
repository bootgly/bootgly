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
 * BG-14: the deferral budget is one-shot and already spent when its
 * `Timeout` is offered to the boundaries. A boundary that parks there is
 * still bounded — the walk re-arms one deadline of the same budget, a second
 * `Timeout` lands at the boundary's wait point and travels outward — so the
 * client gets the Catcher's 503 within two budgets instead of never.
 */
$Parker = new Boundary('parker', mode: 'park');
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
         $Probe->parked = Client::send($Socket, Client::request('/deferred/recover/timeout/parked', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Parker): Generator {
      yield $Router->route('/deferred/recover/timeout/parked', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            Routes::park($Response, 10.0);
            $Response->JSON->send(['parked' => true]);
         }, timeout: 0.4);
      }, GET, [$Parker]);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe, $Parker): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'parked' => $Probe->parked]);

      yield new Assertion(
         description: 'A boundary that parks on the offered Timeout is bounded: the Catcher answers 503',
         fallback: "The parked boundary was never bounded: {$evidence}"
      )
         ->expect([$Probe->parked['code'] ?? 0, $Probe->parked['body'] ?? null])
         ->to->be([503, ''])
         ->assert();

      yield new Assertion(
         description: 'The answer arrived within two budgets',
         fallback: "Unexpected timing: {$evidence}"
      )
         ->expect($Probe->parked['elapsed'] ?? 0.0)
         ->to->delimit(0.7, 2.5)
         ->assert();
   })
);
