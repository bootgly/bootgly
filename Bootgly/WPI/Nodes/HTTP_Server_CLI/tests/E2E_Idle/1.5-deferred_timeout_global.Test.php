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
 * The server-wide deferral budget (`Response::$deferredTimeout`, read by
 * defer() when the Fiber parks): a deferral that outlives it answers a clean
 * 503 instead of holding the connection forever.
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
         $Probe->timeout = Client::send($Socket, Client::request('/idle/timeout/global', $testIndex), 4.0);
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
         description: 'A deferral past the server-wide budget answers 503 with an empty body',
         fallback: "No 503 for the timed-out deferral: {$evidence}"
      )
         ->expect([$Probe->timeout['code'] ?? 0, $Probe->timeout['body'] ?? null])
         ->to->be([503, ''])
         ->assert();

      yield new Assertion(
         description: 'The 503 arrived at the budget, not at the park end',
         fallback: "Unexpected timing: {$evidence}"
      )
         ->expect($Probe->timeout['elapsed'] ?? 0.0)
         ->to->delimit(0.9, 1.9)
         ->assert();
   })
);
