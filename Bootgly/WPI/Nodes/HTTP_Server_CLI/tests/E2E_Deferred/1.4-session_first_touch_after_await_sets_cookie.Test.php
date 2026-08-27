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
 * BG-15: a Session first touched AFTER the first `wait()` still emits its
 * `Set-Cookie` on the deferred wire (the Fiber segment re-binds the ambient
 * Response to the deferred clone), and the value it wrote is persisted.
 */
$Probe = new class {
   public string $cookie = '';
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
         $Probe->write = Client::send($Socket, Client::request('/deferred/session/write', $testIndex), 4.0);
         $matches = [];
         if (preg_match('/Set-Cookie: PHPSID=([^;\r\n]+)/i', $Probe->write['head'], $matches) === 1) {
            $Probe->cookie = $matches[1];
         }
         $headers = ['Cookie' => "PHPSID={$Probe->cookie}"];
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
         'write' => $Probe->write,
         'read' => $Probe->read
      ]);
      $read = json_decode((string) ($Probe->read['body'] ?? ''), true);
      $read = is_array($read) ? $read : [];

      yield new Assertion(
         description: 'A session first touched after the first wait() sets its cookie on the deferred wire',
         fallback: "No session cookie on the deferred response: {$evidence}"
      )
         ->expect([$Probe->write['code'] ?? 0, $Probe->cookie !== ''])
         ->to->be([200, true])
         ->assert();

      yield new Assertion(
         description: 'The value it wrote is persisted for the next request',
         fallback: "The deferred first-touch write did not persist: {$evidence}"
      )
         ->expect($read['deferred'] ?? null)
         ->to->be('yes')
         ->assert();
   })
);
