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
 * BG-14: the global pipeline (`SAPI::$Middlewares`) is walked after the route
 * chain, last entry first. A route boundary answers before any global one; a
 * declining route boundary lets the Throwable continue into the global stack.
 *
 * In Test mode the pipeline is rebuilt per indexed request from the spec's
 * `middlewares:` — every exchange here is single-tick and sequential, so the
 * stack installed at throw time is always this spec's.
 */
$G1 = new Boundary('g1', prefix: '/deferred/recover/global');
$G2 = new Boundary('g2', prefix: '/deferred/recover/global');
$Route = new Boundary('route');
$Pass = new Boundary('pass', mode: 'pass');
$Probe = new class {
   /** @var array<string,mixed> */
   public array $before = [];
   /** @var array<string,mixed> */
   public array $plain = [];
   /** @var array<string,mixed> */
   public array $first = [];
   /** @var array<string,mixed> */
   public array $guarded = [];
   /** @var array<string,mixed> */
   public array $second = [];
   /** @var array<string,mixed> */
   public array $passed = [];
   /** @var array<string,mixed> */
   public array $third = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),
   middlewares: [$G1, $G2],

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->before = Client::send($Socket, Client::request('/deferred/recovered', $testIndex), 4.0);
         $Probe->plain = Client::send($Socket, Client::request('/deferred/recover/global/plain', $testIndex), 4.0);
         $Probe->first = Client::send($Socket, Client::request('/deferred/recovered', $testIndex), 4.0);
         $Probe->guarded = Client::send($Socket, Client::request('/deferred/recover/global/guarded', $testIndex), 4.0);
         $Probe->second = Client::send($Socket, Client::request('/deferred/recovered', $testIndex), 4.0);
         $Probe->passed = Client::send($Socket, Client::request('/deferred/recover/global/passed', $testIndex), 4.0);
         $Probe->third = Client::send($Socket, Client::request('/deferred/recovered', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($G1, $G2, $Route, $Pass): Generator {
      yield $Router->route('/deferred/recovered', static function (Request $Request, Response $Response) use ($G1, $G2, $Route, $Pass) {
         $Response->JSON->send([
            'g1' => $G1->recoveries,
            'g2' => $G2->recoveries,
            'route' => $Route->recoveries,
            'pass' => $Pass->recoveries
         ]);

         return $Response;
      }, GET);
      $work = static function (Response $Response): void {
         $Response->wait();
         throw new RuntimeException('deferred-throw');
      };
      yield $Router->route('/deferred/recover/global/plain', static function (Request $Request, Response $Response) use ($work) {
         return $Response->defer($work);
      }, GET);
      yield $Router->route('/deferred/recover/global/guarded', static function (Request $Request, Response $Response) use ($work) {
         return $Response->defer($work);
      }, GET, [$Route]);
      yield $Router->route('/deferred/recover/global/passed', static function (Request $Request, Response $Response) use ($work) {
         return $Response->defer($work);
      }, GET, [$Pass]);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'before' => $Probe->before,
         'plain' => $Probe->plain,
         'first' => $Probe->first,
         'guarded' => $Probe->guarded,
         'second' => $Probe->second,
         'passed' => $Probe->passed,
         'third' => $Probe->third
      ]);
      $decode = static function (array $exchange): array {
         $decoded = json_decode((string) ($exchange['body'] ?? ''), true);
         return is_array($decoded) ? $decoded : [];
      };
      $before = $decode($Probe->before);
      $plain = $decode($Probe->plain);
      $first = $decode($Probe->first);
      $guarded = $decode($Probe->guarded);
      $second = $decode($Probe->second);
      $passed = $decode($Probe->passed);
      $third = $decode($Probe->third);
      $delta = static fn (array $to, array $from, string $key): int => (int) ($to[$key] ?? 0) - (int) ($from[$key] ?? 0);

      yield new Assertion(
         description: 'With no route boundary the global stack answers, last entry first',
         fallback: "Unexpected global answer: {$evidence}"
      )
         ->expect([
            $Probe->plain['code'] ?? 0,
            $plain['recovered'] ?? null,
            $delta($first, $before, 'g1'),
            $delta($first, $before, 'g2')
         ])
         ->to->be([500, 'g2', 0, 1])
         ->assert();

      yield new Assertion(
         description: 'A route boundary answers before any global one',
         fallback: "The global stack was consulted ahead of the route: {$evidence}"
      )
         ->expect([
            $guarded['recovered'] ?? null,
            $delta($second, $first, 'route'),
            $delta($second, $first, 'g1'),
            $delta($second, $first, 'g2')
         ])
         ->to->be(['route', 1, 0, 0])
         ->assert();

      yield new Assertion(
         description: 'A declining route boundary lets the Throwable continue into the global stack',
         fallback: "The decline did not continue outward: {$evidence}"
      )
         ->expect([
            $passed['recovered'] ?? null,
            $delta($third, $second, 'pass'),
            $delta($third, $second, 'g2')
         ])
         ->to->be(['g2', 1, 1])
         ->assert();
   })
);
