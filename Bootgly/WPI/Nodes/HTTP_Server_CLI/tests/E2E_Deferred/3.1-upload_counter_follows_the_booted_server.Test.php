<?php

use const Bootgly\WPI;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * BG-23 — the harness witness: the worker of THIS suite's server reserves
 * upload bytes on THIS server's counter (`<pid lock>.downloads`), not on the
 * counter of the first Test-mode server the runner process booted.
 *
 * Reach: the runner boots one Test-mode server per suite in the same PHP
 * process. Before the fix `Downloads::init()` ignored a new path, so from the
 * second server on every worker inherited the first server's binding — this
 * case is red whenever another Test-mode server booted first in the same
 * process (in a full sweep without the fix, Unit 1.46 — the detector — aborts
 * the run before this suite is reached) and vacuous (green) when the suite
 * runs alone, where this server IS the first one. 1.6 shows the consequence
 * only once the inherited inode is reclaimed (`State::sweep()`, > 300 s): an
 * empty upload behind a 200.
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
         $Probe->wire = Client::send($Socket, Client::request('/deferred/counter', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      // @ Worker-side: the binding the worker inherited vs this server's own counter path
      yield $Router->route('/deferred/counter', static function (Request $Request, Response $Response) {
         $Counterfile = new ReflectionProperty(Downloads::class, 'counterfile');
         $bound = (string) $Counterfile->getValue();
         $expected = WPI->Server->Process->State->pidLockFile . '.downloads';
         clearstatcache(true, $expected);

         return $Response->JSON->send([
            'bound' => basename($bound),
            'expected' => basename($expected),
            'exists' => is_file($expected),
         ]);
      });
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'wire' => $Probe->wire]);
      $decoded = json_decode((string) ($Probe->wire['body'] ?? ''), true);
      $decoded = is_array($decoded) ? $decoded : [];

      yield new Assertion(
         description: 'The worker is bound to this server\'s own upload counter, and the inode exists',
         fallback: "The worker inherited another server's counter: {$evidence}"
      )
         ->expect([
            $Probe->wire['code'] ?? 0,
            $decoded['bound'] ?? null,
            $decoded['exists'] ?? null
         ])
         ->to->be([200, $decoded['expected'] ?? '(no expected path)', true])
         ->assert();
   })
);
