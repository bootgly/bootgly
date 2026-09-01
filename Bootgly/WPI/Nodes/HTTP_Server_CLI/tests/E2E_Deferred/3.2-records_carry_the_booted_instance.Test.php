<?php

use Bootgly\ACI\Logs\Data\Record;
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
 * BG-24 — every record a server process writes carries the bound port as its
 * instance: the master stamps `Record::$qualifier` at `start()` entry, before
 * the workers fork, so the WORKER answering this request sees this suite's
 * port (8104) — not the port of the first Test-mode server the runner booted,
 * not an empty stamp — and the Test-mode master in the runner sees it too.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $wire = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->wire = Client::send($Socket, Client::request('/deferred/instance', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      // @ Worker-side: the qualifier every record of this worker is stamped with
      yield $Router->route('/deferred/instance', static function (Request $Request, Response $Response) {
         return $Response->JSON->send([
            'stamp' => isset(Record::$qualifier) ? Record::$qualifier : null,
         ]);
      });
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'wire' => $Probe->wire]);
      $decoded = json_decode((string) ($Probe->wire['body'] ?? ''), true);
      $decoded = is_array($decoded) ? $decoded : [];

      yield new Assertion(
         description: 'The worker stamps its records with this server\'s bound port (set before the fork)',
         fallback: "The worker carries another stamp: {$evidence}"
      )
         ->expect([$Probe->wire['code'] ?? 0, $decoded['stamp'] ?? null])
         ->to->be([200, '8104'])
         ->assert();

      yield new Assertion(
         description: 'The Test-mode master in the runner process carries the same qualifier',
         fallback: 'Runner-side Record::$qualifier is not the bound port'
      )
         ->expect(isset(Record::$qualifier) ? Record::$qualifier : null)
         ->to->be('8104')
         ->assert();
   })
);
