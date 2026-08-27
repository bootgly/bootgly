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
 * BG-20: a connection that already answered once, then parks a deferral for
 * longer than TWO idle windows. The prior write bought one renewal; the park
 * alone must buy the rest.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $ping = [];
   /** @var array<string,mixed> */
   public array $park = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->ping = Client::send($Socket, Client::request('/idle/ping', $testIndex), 2.0);
         $Probe->park = Client::send($Socket, Client::request('/idle/park?seconds=6', $testIndex), 10.0);
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
      $evidence = json_encode(['error' => $Probe->error, 'ping' => $Probe->ping, 'park' => $Probe->park]);

      yield new Assertion(
         description: 'The warm-up request answered on the same connection',
         fallback: "The ping did not answer 200: {$evidence}"
      )
         ->expect([$Probe->ping['code'] ?? 0, $Probe->ping['body'] ?? ''])
         ->to->be([200, 'pong'])
         ->assert();

      yield new Assertion(
         description: 'A deferral parked past two idle windows still answers 200',
         fallback: "The idle reaper cut the parked deferral: {$evidence}"
      )
         ->expect($Probe->park['code'] ?? 0)
         ->to->be(200)
         ->assert();

      yield new Assertion(
         description: 'The answer arrived when the park ended',
         fallback: "Unexpected timing: {$evidence}"
      )
         ->expect($Probe->park['elapsed'] ?? 0.0)
         ->to->delimit(5.9, 7.5)
         ->assert();
   })
);
