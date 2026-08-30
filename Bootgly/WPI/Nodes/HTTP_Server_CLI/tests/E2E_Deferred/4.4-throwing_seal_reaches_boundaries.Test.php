<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Boundary;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Sealer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * BG-22, success path: a seal that throws skips the remaining pass and flows
 * to the `Recovering` boundaries — exactly as a throw after `$next()` skips
 * the outer post-code and reaches the enclosing middleware. The work here
 * completed NORMALLY; the wire still answers, through the boundary, carrying
 * the seal's own Throwable. The boundary answers on a FRESH Response —
 * `send()` is one-shot, and the work already spent this generation's.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $rescued = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->rescued = Client::send($Socket, Client::request('/deferred/seal/rescued', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield $Router->route('/deferred/seal/rescued', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            $Response->JSON->send(['ok' => true]);
         });
      }, GET, [new Boundary('net', fresh: true), new Sealer('bad', mode: 'throw')]);

      yield $Router->route('/deferred/ping', static function (Request $Request, Response $Response) {
         return $Response(body: 'pong');
      }, GET);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'rescued' => [$Probe->rescued['code'] ?? 0, $Probe->rescued['body'] ?? null]
      ]);

      yield new Assertion(
         description: 'The boundary answers the seal\'s Throwable — work that had completed fine',
         fallback: "seal throw did not reach the boundary: {$evidence}"
      )
         ->expect(
            ($Probe->rescued['code'] ?? 0) === 500
            && str_contains($Probe->rescued['body'] ?? '', '"recovered":"net"')
            && str_contains($Probe->rescued['body'] ?? '', 'seal-throw:bad')
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The keep-alive connection still answers',
         fallback: "final ping failed: {$response}"
      )
         ->expect(str_starts_with($response, 'HTTP/1.1 200'))
         ->to->be(true)
         ->assert();
   })
);
