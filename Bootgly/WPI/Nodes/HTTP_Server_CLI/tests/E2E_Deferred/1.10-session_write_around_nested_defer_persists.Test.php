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
 * BG-15: a nested `defer()` is a handoff, not an abandonment. A Session write
 * made BEFORE the handoff is persisted at the handoff itself — even when the
 * child is later abandoned by the client (the flat case, 1.9, persists
 * nothing) — and a write made inside a child that answers is persisted by the
 * child's own save point.
 */
$Probe = new class {
   public string $cookie = '';
   /** @var array<string,mixed> */
   public array $seed = [];
   /** @var array<string,mixed> */
   public array $after = [];
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
         // @ Write, hand off to a child that parks, then leave
         @fwrite($Socket, Client::request('/deferred/session/nested/leave', $testIndex, $headers));
         usleep(400_000);
         Client::close($Socket);
         usleep(400_000);

         $Socket = Client::open($hostPort);
         // @ Hand off first, write inside the child that answers
         $Probe->after = Client::send($Socket, Client::request('/deferred/session/nested/after', $testIndex, $headers), 4.0);
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
      $evidence = json_encode(['error' => $Probe->error, 'cookie' => $Probe->cookie, 'after' => $Probe->after, 'read' => $Probe->read]);
      $read = json_decode((string) ($Probe->read['body'] ?? ''), true);
      $read = is_array($read) ? $read : [];

      yield new Assertion(
         description: 'A write made before a nested handoff is persisted at the handoff, even when the child is abandoned',
         fallback: "The pre-handoff write did not persist: {$evidence}"
      )
         ->expect([$read['sync'] ?? null, $read['nested'] ?? null])
         ->to->be(['seed', 'yes'])
         ->assert();

      yield new Assertion(
         description: 'A write made inside an answering child is persisted by the child',
         fallback: "The child's write did not persist: {$evidence}"
      )
         ->expect([$Probe->after['code'] ?? 0, $read['after'] ?? null])
         ->to->be([200, 'yes'])
         ->assert();
   })
);
