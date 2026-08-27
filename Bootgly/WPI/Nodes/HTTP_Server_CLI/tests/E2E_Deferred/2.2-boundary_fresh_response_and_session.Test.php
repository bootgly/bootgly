<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Boundary;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * BG-14: a boundary may answer on a fresh Response (bound to the deferred
 * generation before serialization); the Session the work wrote before it
 * threw is persisted whichever answer wins; and a boundary answering IN PLACE
 * keeps what the deferred clone already carries — a first-touch session
 * cookie included, which the Catcher's fresh Response never has.
 */
$Fresh = new Boundary('fresh', fresh: true);
$Route = new Boundary('route');
$Probe = new class {
   public string $cookie = '';
   /** @var array<string,mixed> */
   public array $seed = [];
   /** @var array<string,mixed> */
   public array $session = [];
   /** @var array<string,mixed> */
   public array $read = [];
   /** @var array<string,mixed> */
   public array $cookieless = [];
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
         $Probe->session = Client::send($Socket, Client::request('/deferred/recover/session', $testIndex, $headers), 4.0);
         Client::close($Socket);

         $Socket = Client::open($hostPort);
         $Probe->read = Client::send($Socket, Client::request('/deferred/session/read', $testIndex, $headers), 4.0);
         Client::close($Socket);

         // @ No cookie: the session is first touched inside the work
         $Socket = Client::open($hostPort);
         $Probe->cookieless = Client::send($Socket, Client::request('/deferred/recover/cookie', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Fresh, $Route): Generator {
      // @ Session write, then a throw answered on a FRESH Response
      yield $Router->route('/deferred/recover/session', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response, Request $Snapshot): void {
            $Response->wait();
            $Session = $Snapshot->Session;
            if ($Session === null) {
               throw new RuntimeException('Deferred fixture lost the session snapshot.');
            }
            $Session->set('recovered', 'yes');
            throw new RuntimeException('deferred-throw');
         });
      }, GET, [$Fresh]);
      // @ First touch inside the work, then a throw answered IN PLACE
      yield $Router->route('/deferred/recover/cookie', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response, Request $Snapshot): void {
            $Response->wait();
            $Session = $Snapshot->Session;
            if ($Session === null) {
               throw new RuntimeException('Deferred fixture could not build the session.');
            }
            $Session->set('touched', 'yes');
            throw new RuntimeException('deferred-throw');
         });
      }, GET, [$Route]);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'cookie' => $Probe->cookie,
         'session' => $Probe->session,
         'read' => $Probe->read,
         'cookieless' => $Probe->cookieless
      ]);
      $decode = static function (array $exchange): array {
         $decoded = json_decode((string) ($exchange['body'] ?? ''), true);
         return is_array($decoded) ? $decoded : [];
      };
      $session = $decode($Probe->session);
      $read = $decode($Probe->read);
      $cookieless = $decode($Probe->cookieless);

      yield new Assertion(
         description: 'A boundary answering on a fresh Response reaches the wire',
         fallback: "The fresh answer did not arrive: {$evidence}"
      )
         ->expect([
            $Probe->session['code'] ?? 0,
            stripos((string) ($Probe->session['head'] ?? ''), 'Content-Type: application/json') !== false,
            $session['recovered'] ?? null
         ])
         ->to->be([500, true, 'fresh'])
         ->assert();

      yield new Assertion(
         description: 'The Session write made before the throw is persisted when a boundary answers',
         fallback: "The write before the recovered throw did not persist: {$evidence}"
      )
         ->expect([$read['sync'] ?? null, $read['recovered'] ?? null])
         ->to->be(['seed', 'yes'])
         ->assert();

      yield new Assertion(
         description: 'A boundary answering in place keeps the first-touch session cookie',
         fallback: "The in-place answer lost the cookie: {$evidence}"
      )
         ->expect([
            $Probe->cookieless['code'] ?? 0,
            $cookieless['recovered'] ?? null,
            preg_match('/Set-Cookie: PHPSID=/i', (string) ($Probe->cookieless['head'] ?? ''))
         ])
         ->to->be([500, 'route', 1])
         ->assert();
   })
);
