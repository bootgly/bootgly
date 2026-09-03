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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * FEAT-1, the case no application middleware can cover: a client that leaves
 * with its deferred response parked gets a line too — `cancelled`, no status,
 * the time it was parked — because the lifecycle settles on no status and the
 * middleware observes it. A budget that runs out is the Catcher's 503, deferred.
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
   public array $timeout = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   middlewares: [$AccessLog],

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         // @ Park the deferral, then leave — the pattern of 1.9
         $Socket = Client::open($hostPort);
         @fwrite($Socket, Client::request('/access/deferred/leave', $testIndex));
         usleep(400_000);
         Client::close($Socket);
         // ! Let the worker observe the departure and settle the generation
         usleep(400_000);

         // @ A budget that runs out
         $Socket = Client::open($hostPort);
         $Probe->timeout = Client::send($Socket, Client::request('/access/deferred/timeout', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /access/records/settled HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) use ($Capture): Generator {
      if ($Capture->mounted === false) {
         Logger::$Sinks ??= new Handlers;
         Logger::$Sinks->push($Capture);
         $Capture->mounted = true;
      }

      yield $Router->route('/access/deferred/leave', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            Routes::park($Response, 10.0);
            $Response->JSON->send(['left' => false]);
         });
      }, GET);

      yield $Router->route('/access/deferred/timeout', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            Routes::park($Response, 10.0);
            $Response->JSON->send(['parked' => true]);
         }, timeout: 0.5);
      }, GET);

      yield $Router->route('/access/records/settled', static function (Request $Request, Response $Response) use ($Capture) {
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
      $evidence = json_encode(['error' => $Probe->error, 'timeout' => $Probe->timeout['head'] ?? null, 'records' => $records]);

      // @ The client left
      $left = $select('/access/deferred/leave');
      yield new Assertion(
         description: 'A client that left with the response parked gets one notice line: cancelled, no status, the time it was parked',
         fallback: $evidence
      )
         ->expect(
            count($left) === 1
            && $left[0]['level'] === 'Notice'
            && ($left[0]['context']['cancelled'] ?? null) === true
            && array_key_exists('code', $left[0]['context']) && $left[0]['context']['code'] === null
            && ($left[0]['context']['deferred'] ?? null) === true
            && ($left[0]['context']['ms'] ?? 0) >= 350
            && str_contains((string) $left[0]['message'], '→ cancelled after ')
         )
         ->to->be(true)
         ->assert();

      // @ The budget ran out
      $timeout = $select('/access/deferred/timeout');
      $ms = $timeout[0]['context']['ms'] ?? 0;
      yield new Assertion(
         description: 'A budget that runs out writes one error line with the 503 the Catcher chose, flagged deferred, timed at the budget',
         fallback: $evidence
      )
         ->expect(
            count($timeout) === 1
            && $timeout[0]['level'] === 'Error'
            && ($timeout[0]['context']['code'] ?? null) === 503
            && ($timeout[0]['context']['deferred'] ?? null) === true
            && $ms >= 400 && $ms <= 1900
            && str_starts_with((string) ($Probe->timeout['head'] ?? ''), 'HTTP/1.1 503')
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
