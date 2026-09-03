<?php

use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handler;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\AccessLog;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RequestId;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * FEAT-1: a global, outermost `AccessLog` writes exactly one line per request
 * with the real outcome — a synchronous 200 with the id an inner RequestId
 * stamped and a duration coherent with the handler's, a synchronous throw as
 * the Catcher's 500, a deferred success with the status and bytes the wire
 * carried, a deferred throw as 500 — and neutralizes a client-controlled
 * target in the message while keeping it raw in the context.
 */
$Capture = new class extends Handler {
   /** @var array<int,array<string,mixed>> */
   public array $records = [];
   public bool $mounted = false;
   protected function write (string $formatted, Record $Record): bool
   {
      if ($Record->channel === 'HTTP.Server.CLI.access') {
         $this->records[] = [
            'level' => $Record->Level->name,
            'message' => $Record->message,
            'context' => $Record->context
         ];
      }
      return true;
   }
};
$AccessLog = new AccessLog;
$Probe = new class {
   /** @var array<string,mixed> */
   public array $ok = [];
   /** @var array<string,mixed> */
   public array $thrown = [];
   /** @var array<string,mixed> */
   public array $deferred = [];
   /** @var array<string,mixed> */
   public array $failed = [];
   /** @var array<string,mixed> */
   public array $at = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   middlewares: [$AccessLog, new RequestId],

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         // ! One connection per request: an error answer may close it
         foreach (['ok' => '/access/ok', 'thrown' => '/access/throw', 'deferred' => '/access/deferred/ok', 'failed' => '/access/deferred/throw', 'at' => '/access/@user?token=secret'] as $field => $path) {
            $Socket = Client::open($hostPort);
            $Probe->{$field} = Client::send($Socket, Client::request($path, $testIndex), 4.0);
            Client::close($Socket);
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /access/records HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) use ($Capture): Generator {
      // ! In the worker: `global: true` routes every access record to the
      //   sinks (Display is NONE here, local handlers are skipped)
      if ($Capture->mounted === false) {
         Logger::$Sinks ??= new Handlers;
         Logger::$Sinks->push($Capture);
         $Capture->mounted = true;
      }

      yield $Router->route('/access/ok', static function (Request $Request, Response $Response) {
         usleep(50_000);
         return $Response(body: 'ok!');
      }, GET);

      yield $Router->route('/access/throw', static function (Request $Request, Response $Response) {
         throw new RuntimeException('access-throw');
      }, GET);

      yield $Router->route('/access/deferred/ok', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            $Response->JSON->send(['ok' => 'deferred']);
         });
      }, GET);

      yield $Router->route('/access/deferred/throw', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            throw new RuntimeException('deferred-throw');
         });
      }, GET);

      yield $Router->route('/access/@user', static function (Request $Request, Response $Response) {
         return $Response(body: 'at');
      }, GET);

      yield $Router->route('/access/records', static function (Request $Request, Response $Response) use ($Capture) {
         return $Response->JSON->send($Capture->records);
      }, GET);

      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $body = substr($response, (int) strpos($response, "\r\n\r\n") + 4);
      $records = json_decode($body, true);
      $records = is_array($records) ? $records : [];
      $select = static fn (string $URI): array => array_values(array_filter(
         $records,
         static fn (array $record): bool => ($record['context']['URI'] ?? null) === $URI
      ));
      $evidence = json_encode(['error' => $Probe->error, 'records' => $records]);

      // @ Synchronous 200
      $ok = $select('/access/ok');
      $matches = [];
      preg_match('/X-Request-Id: (\S+)/i', (string) ($Probe->ok['head'] ?? ''), $matches);
      yield new Assertion(
         description: 'A synchronous 200 writes one info line: real status, body bytes, the id on the wire, a duration coherent with the handler',
         fallback: $evidence
      )
         ->expect(
            count($ok) === 1
            && $ok[0]['level'] === 'Info'
            && ($ok[0]['context']['code'] ?? null) === 200
            && ($ok[0]['context']['bytes'] ?? null) === 3
            && ($ok[0]['context']['deferred'] ?? null) === false
            && ($ok[0]['context']['ms'] ?? 0) >= 50
            && ($ok[0]['context']['id'] ?? null) === ($matches[1] ?? '?')
            && ($ok[0]['context']['protocol'] ?? null) === 'HTTP/1.1'
            && ($ok[0]['context']['peer'] ?? '') !== ''
         )
         ->to->be(true)
         ->assert();

      // @ Synchronous throw → the Catcher's 500
      $thrown = $select('/access/throw');
      yield new Assertion(
         description: 'A synchronous throw writes one error line with 500 and the Throwable, and the client got the 500',
         fallback: $evidence
      )
         ->expect(
            count($thrown) === 1
            && $thrown[0]['level'] === 'Error'
            && ($thrown[0]['context']['code'] ?? null) === 500
            && ($thrown[0]['context']['throwable'] ?? null) === RuntimeException::class
            && str_starts_with((string) ($Probe->thrown['head'] ?? ''), 'HTTP/1.1 500')
         )
         ->to->be(true)
         ->assert();

      // @ Deferred success — settled by the lifecycle, after the sealing pass recorded the wire
      $deferred = $select('/access/deferred/ok');
      yield new Assertion(
         description: 'A deferred success writes one info line, flagged deferred, with the status and the bytes the wire carried',
         fallback: $evidence
      )
         ->expect(
            count($deferred) === 1
            && $deferred[0]['level'] === 'Info'
            && ($deferred[0]['context']['code'] ?? null) === 200
            && ($deferred[0]['context']['deferred'] ?? null) === true
            && ($deferred[0]['context']['bytes'] ?? null) === strlen((string) json_encode(['ok' => 'deferred']))
            && str_starts_with((string) ($Probe->deferred['head'] ?? ''), 'HTTP/1.1 200')
         )
         ->to->be(true)
         ->assert();

      // @ Deferred throw → the Catcher's 500, the id stamped before the handoff kept
      $failed = $select('/access/deferred/throw');
      yield new Assertion(
         description: 'A deferred throw writes one error line with 500, flagged deferred, keeping the request id',
         fallback: $evidence
      )
         ->expect(
            count($failed) === 1
            && $failed[0]['level'] === 'Error'
            && ($failed[0]['context']['code'] ?? null) === 500
            && ($failed[0]['context']['deferred'] ?? null) === true
            && is_string($failed[0]['context']['id'] ?? null)
            && str_starts_with((string) ($Probe->failed['head'] ?? ''), 'HTTP/1.1 500')
         )
         ->to->be(true)
         ->assert();

      // @ Client-controlled target
      $at = $select('/access/@user');
      yield new Assertion(
         description: 'A `@` in the target is %40 in the message and raw in the context; the query never rides',
         fallback: $evidence
      )
         ->expect(
            count($at) === 1
            && str_starts_with((string) $at[0]['message'], 'GET /access/%40user → 200 in ')
            && str_contains((string) $at[0]['message'], 'token') === false
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The probe itself answered 200',
         fallback: $response
      )
         ->expect(str_starts_with($response, 'HTTP/1.1 200'))
         ->to->be(true)
         ->assert();
   })
);
