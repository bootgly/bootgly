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
 * The per-call budget (`defer($work, timeout: 1)`): the Timeout is delivered
 * at the app's wait point BEFORE the exchange settles, so its catch and
 * finally run; letting it propagate answers 503 and the keep-alive
 * connection survives the answer.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $timeout = [];
   /** @var array<string,mixed> */
   public array $report = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         Client::send($Socket, Client::request('/idle/reset', $testIndex), 2.0);
         $Probe->timeout = Client::send($Socket, Client::request('/idle/timeout/per-call', $testIndex), 4.0);
         $Probe->report = Client::send($Socket, Client::request('/idle/report', $testIndex), 2.0);
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
      $report = (array) json_decode($Probe->report['body'] ?? '', true);
      $evidence = json_encode(['error' => $Probe->error, 'timeout' => $Probe->timeout, 'report' => $Probe->report]);

      yield new Assertion(
         description: 'A deferral past its per-call budget answers 503',
         fallback: "No 503 for the timed-out deferral: {$evidence}"
      )
         ->expect($Probe->timeout['code'] ?? 0)
         ->to->be(503)
         ->assert();

      yield new Assertion(
         description: 'The 503 arrived at the budget',
         fallback: "Unexpected timing: {$evidence}"
      )
         ->expect($Probe->timeout['elapsed'] ?? 0.0)
         ->to->delimit(0.9, 1.9)
         ->assert();

      yield new Assertion(
         description: "The app's catch saw the Timeout (with its budget) and its finally ran",
         fallback: "The Timeout never reached the app's catch/finally: {$evidence}"
      )
         ->expect([$report['caught'] ?? null, $report['finally'] ?? null])
         ->to->be([1, true])
         ->assert();

      yield new Assertion(
         description: 'The keep-alive connection survived the 503',
         fallback: "The report request on the same connection failed: {$evidence}"
      )
         ->expect($Probe->report['code'] ?? 0)
         ->to->be(200)
         ->assert();
   })
);
