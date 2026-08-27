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
 * The exemption is not permanent: once the deferral answered and the
 * connection falls silent, the idle reaper closes it like any other keep-alive
 * peer — the reply write buys one renewal, the next silent tick reaps.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $park = [];
   public null|float $reaped = null;
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->park = Client::send($Socket, Client::request('/idle/park?seconds=3', $testIndex), 7.0);
         $Probe->reaped = Client::wait($Socket, 8.0);
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
      $evidence = json_encode(['error' => $Probe->error, 'park' => $Probe->park, 'reaped' => $Probe->reaped]);

      yield new Assertion(
         description: 'The parked deferral answered 200 first',
         fallback: "The idle reaper cut the parked deferral: {$evidence}"
      )
         ->expect($Probe->park['code'] ?? 0)
         ->to->be(200)
         ->assert();

      yield new Assertion(
         description: 'The silent connection is reaped after the deferral settled',
         fallback: "The connection was never reaped (or too early/late) after the reply: {$evidence}"
      )
         ->expect($Probe->reaped ?? -1.0)
         ->to->delimit(1.0, 6.0)
         ->assert();
   })
);
