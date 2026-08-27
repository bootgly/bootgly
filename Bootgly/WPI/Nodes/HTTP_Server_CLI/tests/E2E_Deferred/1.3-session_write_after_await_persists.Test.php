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
 * BG-15: a Session written after the first `wait()` of a deferral is
 * persisted when the deferral answers — the next request on the same session
 * reads it back without the app calling `save()` itself.
 */
$Probe = new class {
   public string $cookie = '';
   /** @var array<string,mixed> */
   public array $seed = [];
   /** @var array<string,mixed> */
   public array $write = [];
   /** @var array<string,mixed> */
   public array $read = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->seed = Client::send($Socket, Client::request('/deferred/session/seed', $testIndex), 4.0);
         $matches = [];
         if (preg_match('/Set-Cookie: PHPSID=([^;\r\n]+)/i', $Probe->seed['head'], $matches) === 1) {
            $Probe->cookie = $matches[1];
         }
         $headers = ['Cookie' => "PHPSID={$Probe->cookie}"];
         $Probe->write = Client::send($Socket, Client::request('/deferred/session/write', $testIndex, $headers), 4.0);
         $Probe->read = Client::send($Socket, Client::request('/deferred/session/read', $testIndex, $headers), 4.0);
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
      $evidence = json_encode([
         'error' => $Probe->error,
         'cookie' => $Probe->cookie,
         'seed' => $Probe->seed,
         'write' => $Probe->write,
         'read' => $Probe->read
      ]);
      $read = json_decode((string) ($Probe->read['body'] ?? ''), true);
      $read = is_array($read) ? $read : [];

      yield new Assertion(
         description: 'The synchronous seed established the session cookie',
         fallback: "No session cookie on the seed: {$evidence}"
      )
         ->expect([$Probe->seed['code'] ?? 0, $Probe->cookie !== ''])
         ->to->be([200, true])
         ->assert();

      // ! A mutation may refresh the cookie; what it must never do is mint a
      //   different session for a request that presented a valid one
      $matches = [];
      $reissued = preg_match('/Set-Cookie: PHPSID=([^;\r\n]+)/i', $Probe->write['head'] ?? '', $matches) === 1
         ? $matches[1]
         : '';

      yield new Assertion(
         description: 'The deferred write answered 200 and never minted a different session',
         fallback: "Unexpected write response: {$evidence}"
      )
         ->expect([$Probe->write['code'] ?? 0, $reissued === '' || $reissued === $Probe->cookie])
         ->to->be([200, true])
         ->assert();

      yield new Assertion(
         description: 'The value written after the first wait() is persisted for the next request',
         fallback: "The deferred write did not persist: {$evidence}"
      )
         ->expect([$read['sync'] ?? null, $read['deferred'] ?? null])
         ->to->be(['seed', 'yes'])
         ->assert();
   })
);
