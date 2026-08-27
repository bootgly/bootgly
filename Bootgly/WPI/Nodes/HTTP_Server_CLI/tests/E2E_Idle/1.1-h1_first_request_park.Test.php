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
 * BG-20: the FIRST request of an HTTP/1.1 connection parks a deferral longer
 * than the idle timeout (2 s here). Before the fix the reaper found no write
 * since the accept and cut the connection at its first tick — an empty reply
 * in [2, 3) s. A parked deferral is pending work: the connection is not idle.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $park = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->park = Client::send($Socket, Client::request('/idle/park?seconds=4', $testIndex), 8.0);
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
      $evidence = json_encode(['error' => $Probe->error, 'park' => $Probe->park]);

      yield new Assertion(
         description: 'A first-request deferral parked past the idle timeout still answers 200',
         fallback: "The idle reaper cut the parked deferral: {$evidence}"
      )
         ->expect($Probe->park['code'] ?? 0)
         ->to->be(200)
         ->assert();

      yield new Assertion(
         description: 'The answer arrived when the park ended, not earlier',
         fallback: "Unexpected timing: {$evidence}"
      )
         ->expect($Probe->park['elapsed'] ?? 0.0)
         ->to->delimit(3.9, 5.5)
         ->assert();

      yield new Assertion(
         description: 'The harness keep-alive connection still answers',
         fallback: 'The ping after the choreography did not answer 200'
      )
         ->expect(str_starts_with($response, 'HTTP/1.1 200'))
         ->to->be(true)
         ->assert();
   })
);
