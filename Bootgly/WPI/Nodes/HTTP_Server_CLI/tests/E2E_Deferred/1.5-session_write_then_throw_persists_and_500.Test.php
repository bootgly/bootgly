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
 * BG-15: deferred work that writes the Session and then throws still answers
 * an error (500) AND the write is persisted — the synchronous cycle persists
 * before the response leaves regardless of the handler's outcome, and a
 * deferral mirrors it.
 */
$Probe = new class {
   public string $cookie = '';
   /** @var array<string,mixed> */
   public array $seed = [];
   /** @var array<string,mixed> */
   public array $throw = [];
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
         $Probe->throw = Client::send($Socket, Client::request('/deferred/session/throw', $testIndex, $headers), 4.0);
         Client::close($Socket);

         // ! A fresh connection for the read-back: the error response may
         //   close the connection, and that is not what this spec pins
         $Socket = Client::open($hostPort);
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
         'throw' => $Probe->throw,
         'read' => $Probe->read
      ]);
      $read = json_decode((string) ($Probe->read['body'] ?? ''), true);
      $read = is_array($read) ? $read : [];

      yield new Assertion(
         description: 'Deferred work that throws after a Session write answers 500',
         fallback: "Unexpected error response: {$evidence}"
      )
         ->expect($Probe->throw['code'] ?? 0)
         ->to->be(500)
         ->assert();

      yield new Assertion(
         description: 'The Session write made before the throw is persisted',
         fallback: "The write before the throw did not persist: {$evidence}"
      )
         ->expect([$read['sync'] ?? null, $read['errored'] ?? null])
         ->to->be(['seed', 'yes'])
         ->assert();
   })
);
