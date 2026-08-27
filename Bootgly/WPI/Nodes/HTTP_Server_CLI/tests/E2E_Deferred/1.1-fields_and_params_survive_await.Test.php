<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * BG-15: deferred work receives the request it answers as its second
 * argument — the snapshot `defer()` captured, the same object as
 * `$Response->Request` — and that snapshot survives the first `wait()`: the
 * JSON fields the live per-connection Request no longer has (spec 1.2 pins
 * that scrub) are still on the snapshot after the synchronous cycle ended.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $fields = [];
   /** @var array<string,mixed> */
   public array $optional = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $body = '{"alpha":"1","beta":"two"}';
         $request = "POST /deferred/fields/42 HTTP/1.1\r\nHost: localhost\r\nX-Bootgly-Test: {$testIndex}\r\n"
            . "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}";
         $Socket = Client::open($hostPort);
         $Probe->fields = Client::send($Socket, $request, 4.0);
         // @ A closure that declared an optional second parameter of another
         //   type must keep its default (the snapshot is not forced on it)
         $Probe->optional = Client::send($Socket, Client::request('/deferred/optional', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'fields' => $Probe->fields, 'optional' => $Probe->optional]);
      $decoded = json_decode((string) ($Probe->fields['body'] ?? ''), true);
      $decoded = is_array($decoded) ? $decoded : [];
      $optional = json_decode((string) ($Probe->optional['body'] ?? ''), true);
      $optional = is_array($optional) ? $optional : [];

      yield new Assertion(
         description: 'The deferred exchange answered 200',
         fallback: "Unexpected status: {$evidence}"
      )
         ->expect($Probe->fields['code'] ?? 0)
         ->to->be(200)
         ->assert();

      yield new Assertion(
         description: 'The snapshot keeps the JSON fields after the first wait()',
         fallback: "Fields lost on the snapshot: {$evidence}"
      )
         ->expect($decoded['snapshot'] ?? null)
         ->to->be(['alpha' => '1', 'beta' => 'two'])
         ->assert();

      yield new Assertion(
         description: 'The second argument IS the snapshot the Response carries',
         fallback: "The work received a different Request than the Response carries: {$evidence}"
      )
         ->expect($decoded['same'] ?? null)
         ->to->be(true)
         ->assert();

      // ! Sanity only — neither half discriminates in this single-exchange
      //   shape: `scrub()` never clears `method`, and `params` is read from
      //   the per-connection Router, not from the snapshot. The snapshot's
      //   independence is pinned by the `fields` assertion above and by 1.7;
      //   the Route rebind in `Response::bind()` is pinned by Security 55.01.
      yield new Assertion(
         description: 'The deferred work answers under the exchange it was scheduled for',
         fallback: "Method/params lost: {$evidence}"
      )
         ->expect([$decoded['method'] ?? null, $decoded['params'] ?? null])
         ->to->be(['POST', '42'])
         ->assert();

      yield new Assertion(
         description: 'A closure with an optional second parameter of another type keeps its default',
         fallback: "The snapshot was forced on a parameter that cannot take it: {$evidence}"
      )
         ->expect([$Probe->optional['code'] ?? 0, $optional['attempt'] ?? null])
         ->to->be([200, 0])
         ->assert();
   })
);
