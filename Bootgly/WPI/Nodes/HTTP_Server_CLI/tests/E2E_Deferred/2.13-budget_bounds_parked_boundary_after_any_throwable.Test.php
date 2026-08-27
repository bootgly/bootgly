<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Timeout;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Boundary;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * BG-14: the walk guard is armed from the generation's BUDGET, not from the
 * class of the Throwable being offered. Work that catches its own Timeout
 * and throws something else has spent the one-shot budget just the same —
 * a boundary that parks on that Throwable is still bounded, and the client
 * gets the Catcher's 503 within two budgets instead of never.
 */
$Parker = new Boundary('parker', mode: 'park');
$Probe = new class {
   /** @var array<string,mixed> */
   public array $converted = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->converted = Client::send($Socket, Client::request('/deferred/recover/timeout/converted', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Parker): Generator {
      // @ The work converts its Timeout into an application error
      yield $Router->route('/deferred/recover/timeout/converted', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            try {
               Routes::park($Response, 10.0);
            }
            catch (Timeout) {
               throw new RuntimeException('converted');
            }
            $Response->JSON->send(['parked' => true]);
         }, timeout: 0.4);
      }, GET, [$Parker]);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'converted' => $Probe->converted]);

      yield new Assertion(
         description: 'A boundary that parks on a non-Timeout Throwable is still bounded by the spent budget',
         fallback: "The parked boundary was never bounded after a converted Timeout: {$evidence}"
      )
         ->expect([$Probe->converted['code'] ?? 0, $Probe->converted['body'] ?? null])
         ->to->be([503, ''])
         ->assert();

      yield new Assertion(
         description: 'The answer arrived within two budgets',
         fallback: "Unexpected timing: {$evidence}"
      )
         ->expect($Probe->converted['elapsed'] ?? 0.0)
         ->to->delimit(0.7, 2.5)
         ->assert();
   })
);
