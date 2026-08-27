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
 * BG-15 (the contract, pinned from the other side): the `$Request` a route
 * received and captured with `use ()` is the live per-connection object. The
 * encoder scrubs its payload the moment the synchronous cycle ends, so inside
 * deferred work it reads empty after the first `wait()` — the retained-body
 * ceiling (audit H1) relies on exactly that scrub.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $outer = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $body = '{"alpha":"1","beta":"two"}';
         $request = "POST /deferred/outer/42 HTTP/1.1\r\nHost: localhost\r\nX-Bootgly-Test: {$testIndex}\r\n"
            . "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}";
         $Socket = Client::open($hostPort);
         $Probe->outer = Client::send($Socket, $request, 4.0);
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
      $evidence = json_encode(['error' => $Probe->error, 'outer' => $Probe->outer]);
      $decoded = json_decode((string) ($Probe->outer['body'] ?? ''), true);
      $decoded = is_array($decoded) ? $decoded : [];

      yield new Assertion(
         description: 'The deferred exchange answered 200',
         fallback: "Unexpected status: {$evidence}"
      )
         ->expect($Probe->outer['code'] ?? 0)
         ->to->be(200)
         ->assert();

      yield new Assertion(
         description: 'The live request captured by use() is scrubbed after the first wait()',
         fallback: "The live request still carried its payload: {$evidence}"
      )
         ->expect($decoded['outer'] ?? null)
         ->to->be([])
         ->assert();
   })
);
