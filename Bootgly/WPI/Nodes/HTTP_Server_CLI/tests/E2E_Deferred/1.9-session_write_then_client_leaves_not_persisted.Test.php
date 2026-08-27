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
 * BG-15 (the decided contract, pinned from both sides): a deferral the client
 * abandons mid-park is cancelled, never answered, and gets no save point of
 * its own — the server does not persist a Session write made before the
 * client left (read back right after the cancellation). The Session's
 * destructor remains a GC-time safety net: once the cycle collector runs,
 * the abandoned write lands — as the docs promise.
 */
$Probe = new class {
   public string $cookie = '';
   /** @var array<string,mixed> */
   public array $seed = [];
   /** @var array<string,mixed> */
   public array $read = [];
   /** @var array<string,mixed> */
   public array $reread = [];
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
         // @ Park the deferral (it writes the Session first), then leave
         @fwrite($Socket, Client::request('/deferred/session/leave', $testIndex, $headers));
         usleep(400_000);
         Client::close($Socket);
         // ! Let the worker observe the departure and reap the generation
         usleep(400_000);

         $Socket = Client::open($hostPort);
         $Probe->read = Client::send($Socket, Client::request('/deferred/session/read', $testIndex, $headers), 4.0);
         // @ Force the cycle collector the destructor safety net depends on,
         //   then read back once more
         Client::send($Socket, Client::request('/deferred/collect', $testIndex), 4.0);
         $Probe->reread = Client::send($Socket, Client::request('/deferred/session/read', $testIndex, $headers), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      // ! Spec-local: forces the cycle collector the safety net depends on
      yield $Router->route('/deferred/collect', static function (Request $Request, Response $Response) {
         $Response->JSON->send(['collected' => gc_collect_cycles()]);

         return $Response;
      }, GET);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'cookie' => $Probe->cookie, 'read' => $Probe->read, 'reread' => $Probe->reread]);
      $read = json_decode((string) ($Probe->read['body'] ?? ''), true);
      $read = is_array($read) ? $read : [];
      $reread = json_decode((string) ($Probe->reread['body'] ?? ''), true);
      $reread = is_array($reread) ? $reread : [];

      yield new Assertion(
         description: 'The session survived and the server itself did not persist the abandoned write',
         fallback: "Unexpected read-back after the client left: {$evidence}"
      )
         ->expect([$read['sync'] ?? null, $read['left'] ?? null])
         ->to->be(['seed', null])
         ->assert();

      yield new Assertion(
         description: "The Session destructor's safety net saves the abandoned write once the collector runs",
         fallback: "Unexpected read-back after the collection: {$evidence}"
      )
         ->expect([$reread['sync'] ?? null, $reread['left'] ?? null])
         ->to->be(['seed', 'yes'])
         ->assert();
   })
);
