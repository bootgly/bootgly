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
 * The app catches the Timeout at its wait point and selects its own answer —
 * the budget is a signal, not a verdict.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $timeout = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->timeout = Client::send($Socket, Client::request('/idle/timeout/handled', $testIndex), 4.0);
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
      $evidence = json_encode(['error' => $Probe->error, 'timeout' => $Probe->timeout]);

      yield new Assertion(
         description: 'The app answered its own status after catching the Timeout',
         fallback: "The app's own answer did not arrive: {$evidence}"
      )
         ->expect([$Probe->timeout['code'] ?? 0, $Probe->timeout['body'] ?? ''])
         ->to->be([202, 'handled'])
         ->assert();

      yield new Assertion(
         description: 'The answer arrived at the budget',
         fallback: "Unexpected timing: {$evidence}"
      )
         ->expect($Probe->timeout['elapsed'] ?? 0.0)
         ->to->delimit(0.9, 1.9)
         ->assert();
   })
);
