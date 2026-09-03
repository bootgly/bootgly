<?php


use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handler;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Exchange;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\AccessLog;


/**
 * One line per request, written by whichever side settles it: the post-`$next()`
 * half for a synchronous outcome (and for a throw that leaves the onion), the
 * lifecycle's terminal transition for a deferred one — with the status it
 * settles on, or as `cancelled` when it settles on none. The sealing pass only
 * records what the wire will carry.
 */
return new Test(
   description: 'AccessLog writes exactly one line per request — synchronous, thrown, deferred, sealed or cancelled — with the real outcome',
   test: new Assertions(Case: function (): Generator {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $saved = Display::$segments;
      Display::show(Display::MESSAGE);

      $capture = static fn (): Handler => new class extends Handler {
         /** @var array<int,Record> */
         public array $Records = [];
         protected function write (string $formatted, Record $Record): bool
         {
            $this->Records[] = $Record;
            return true;
         }
      };
      $build = static function (AccessLog $AccessLog, Handler $Capture): AccessLog {
         $AccessLog->Logger->Handlers = new Handlers;
         $AccessLog->Logger->Handlers->push($Capture);
         return $AccessLog;
      };
      $props = ['URI' => '/path?token=secret', 'protocol' => 'HTTP/1.1'];

      try {
         // @ Synchronous 200 — an inner RequestId stamps the id before `$next()` returns
         $Capture = $capture();
         $AccessLog = $build(new AccessLog, $Capture);
         [$Request, $Response] = $createMocks(requestProps: $props);
         $Result = $AccessLog->process($Request, $Response, static function (object $Request, object $Response): object {
            $Response->Header->set('X-Request-Id', 'req-1');
            return $Response(code: 200, body: 'hello');
         });
         $Record = $Capture->Records[0] ?? null;
         yield new Assertion(
            description: 'A synchronous 200 writes one info record on the default channel and returns the Response',
            fallback: json_encode(['records' => count($Capture->Records)])
         )
            ->expect($Result === $Response && count($Capture->Records) === 1 && $Record?->Level === Levels::Info && $Record->channel === 'HTTP.Server.CLI.access')
            ->to->be(true)
            ->assert();
         yield new Assertion(
            description: 'The default line: method, target without the query, status and duration',
            fallback: (string) $Record?->message
         )
            ->expect(preg_match('/^GET \/path → 200 in [0-9.]+ms@\.;$/', (string) $Record?->message) === 1)
            ->to->be(true)
            ->assert();
         $context = $Record?->context ?? [];
         yield new Assertion(
            description: 'The context carries every field, raw',
            fallback: json_encode($context)
         )
            ->expect(
               ($context['method'] ?? null) === 'GET'
               && ($context['URI'] ?? null) === '/path'
               && ($context['protocol'] ?? null) === 'HTTP/1.1'
               && ($context['code'] ?? null) === 200
               && is_float($context['ms'] ?? null) && $context['ms'] >= 0
               && ($context['bytes'] ?? null) === 5
               && ($context['peer'] ?? null) === '127.0.0.1'
               && ($context['address'] ?? null) === '127.0.0.1'
               && ($context['id'] ?? null) === 'req-1'
               && ($context['deferred'] ?? null) === false
               && ($context['cancelled'] ?? null) === false
               && array_key_exists('throwable', $context) === false
            )
            ->to->be(true)
            ->assert();

         // @ 404 → warning
         $Capture->Records = [];
         [$Request, $Response] = $createMocks(requestProps: $props);
         $AccessLog->process($Request, $Response, static fn (object $Request, object $Response): object => $Response(code: 404, body: ''));
         yield new Assertion(
            description: 'A 4xx is a warning',
         )
            ->expect(($Capture->Records[0] ?? null)?->Level)
            ->to->be(Levels::Warning)
            ->assert();

         // @ A throw that leaves the onion: one error line with the Catcher's 500, and the throw goes on
         $Capture->Records = [];
         [$Request, $Response] = $createMocks(requestProps: $props);
         $thrown = null;
         try {
            $AccessLog->process($Request, $Response, static function (object $Request, object $Response): object {
               throw new RuntimeException('boom');
            });
         }
         catch (RuntimeException $Throwable) {
            $thrown = $Throwable;
         }
         $Record = $Capture->Records[0] ?? null;
         yield new Assertion(
            description: 'A synchronous throw writes one error record with 500 and the Throwable class, and propagates',
            fallback: json_encode(['records' => count($Capture->Records), 'context' => $Record?->context])
         )
            ->expect(
               $thrown instanceof RuntimeException
               && count($Capture->Records) === 1
               && $Record?->Level === Levels::Error
               && ($Record->context['code'] ?? null) === 500
               && array_key_exists('bytes', $Record->context) && $Record->context['bytes'] === null
               && ($Record->context['throwable'] ?? null) === RuntimeException::class
            )
            ->to->be(true)
            ->assert();

         // @ Deferred without a lifecycle (a double): the line goes out now, flagged
         $Capture->Records = [];
         [$Request, $Response] = $createMocks(requestProps: $props);
         $AccessLog->process($Request, $Response, static function (object $Request, object $Response): object {
            $Response->deferred = true;
            return $Response;
         });
         yield new Assertion(
            description: 'A deferred Response with no lifecycle to wait for is written at once, flagged deferred',
            fallback: json_encode(($Capture->Records[0] ?? null)?->context)
         )
            ->expect(count($Capture->Records) === 1 && (($Capture->Records[0]->context['deferred'] ?? null) === true))
            ->to->be(true)
            ->assert();

         // @ Deferred with a lifecycle: nothing until it settles; the sealing pass records; finish() writes once
         $Capture->Records = [];
         [$Request, $Response] = $createMocks(requestProps: $props);
         $Exchange = new Exchange;
         Exchange::track($Response, $Exchange);
         $AccessLog->process($Request, $Response, static function (object $Request, object $Response): object {
            $Response->Header->set('X-Request-Id', 'req-2');
            $Response->deferred = true;
            return $Response(code: 200, body: 'x');
         });
         yield new Assertion(
            description: 'A parked deferral writes nothing until its lifecycle settles',
         )
            ->expect(count($Capture->Records))
            ->to->be(0)
            ->assert();

         $key = AccessLog::class . '#' . spl_object_id($AccessLog);
         $Snapshot = new Request;
         $Snapshot->address = '10.0.0.7';
         $Snapshot->{$key} = $Request->{$key};
         $Sealed = new Response(code: 204, body: 'abc');
         $AccessLog->seal($Snapshot, $Sealed);
         yield new Assertion(
            description: 'seal() records the wire (status, bytes, address) and writes nothing; the id already stamped stands',
            fallback: json_encode(['records' => count($Capture->Records), 'entry' => (array) $Request->{$key}])
         )
            ->expect(
               count($Capture->Records) === 0
               && $Request->{$key}->code === 204
               && $Request->{$key}->bytes === 3
               && $Request->{$key}->address === '10.0.0.7'
               && $Request->{$key}->id === 'req-2'
               && $Request->{$key}->deferred === true
            )
            ->to->be(true)
            ->assert();

         $Exchange->finish($Sealed);
         $Exchange->finish($Sealed);
         $Record = $Capture->Records[0] ?? null;
         yield new Assertion(
            description: 'The lifecycle settling writes the line once, with the sealed outcome',
            fallback: json_encode(['records' => count($Capture->Records), 'context' => $Record?->context])
         )
            ->expect(
               count($Capture->Records) === 1
               && $Record?->Level === Levels::Info
               && ($Record->context['code'] ?? null) === 204
               && ($Record->context['bytes'] ?? null) === 3
               && ($Record->context['address'] ?? null) === '10.0.0.7'
               && ($Record->context['id'] ?? null) === 'req-2'
               && ($Record->context['deferred'] ?? null) === true
               && ($Record->context['cancelled'] ?? null) === false
            )
            ->to->be(true)
            ->assert();
         Exchange::track($Response, null);

         // @ Cancelled: the lifecycle settles on no status
         $Capture->Records = [];
         [$Request, $Response] = $createMocks(requestProps: $props);
         $Exchange = new Exchange;
         Exchange::track($Response, $Exchange);
         $AccessLog->process($Request, $Response, static function (object $Request, object $Response): object {
            $Response->deferred = true;
            return $Response;
         });
         usleep(5_000);
         $Exchange->finish(null);
         $Record = $Capture->Records[0] ?? null;
         yield new Assertion(
            description: 'A client that left gets its line: notice, cancelled, no status, the time it was parked',
            fallback: json_encode(['records' => count($Capture->Records), 'message' => $Record?->message, 'context' => $Record?->context])
         )
            ->expect(
               count($Capture->Records) === 1
               && $Record?->Level === Levels::Notice
               && ($Record->context['cancelled'] ?? null) === true
               && array_key_exists('code', $Record->context) && $Record->context['code'] === null
               && ($Record->context['deferred'] ?? null) === true
               && ($Record->context['ms'] ?? 0) >= 5
               && preg_match('/^GET \/path → cancelled after [0-9.]+ms@\.;$/', $Record->message) === 1
            )
            ->to->be(true)
            ->assert();
         Exchange::track($Response, null);

         // @ A deferral that completed inline and then threw: the wire is out, the settled status wins over 500
         $Capture->Records = [];
         [$Request, $Response] = $createMocks(requestProps: $props);
         $Exchange = new Exchange;
         Exchange::track($Response, $Exchange);
         $thrown = null;
         try {
            $AccessLog->process($Request, $Response, static function (object $Request, object $Response) use ($Exchange): object {
               $Exchange->finish(new Response(code: 201, body: ''));
               throw new LogicException('after the wire');
            });
         }
         catch (LogicException $Throwable) {
            $thrown = $Throwable;
         }
         $Record = $Capture->Records[0] ?? null;
         yield new Assertion(
            description: 'A throw after an inline completion logs the settled status, not 500',
            fallback: json_encode(['records' => count($Capture->Records), 'context' => $Record?->context])
         )
            ->expect($thrown instanceof LogicException && count($Capture->Records) === 1 && ($Record?->context['code'] ?? null) === 201 && ($Record->context['throwable'] ?? null) === null)
            ->to->be(true)
            ->assert();
         Exchange::track($Response, null);

         // @ Configuration: channel, no id, the query kept
         $Capture = $capture();
         $Audit = $build(new AccessLog(channel: 'audit', header: null, query: true), $Capture);
         [$Request, $Response] = $createMocks(requestProps: $props);
         $Audit->process($Request, $Response, static function (object $Request, object $Response): object {
            $Response->Header->set('X-Request-Id', 'req-3');
            return $Response(code: 200, body: '');
         });
         $Record = $Capture->Records[0] ?? null;
         yield new Assertion(
            description: 'channel names the Logger, header null logs no id, query true keeps the query in the line and the context',
            fallback: json_encode(['channel' => $Record?->channel, 'message' => $Record?->message, 'context' => $Record?->context])
         )
            ->expect(
               $Record?->channel === 'audit'
               && array_key_exists('id', $Record->context) && $Record->context['id'] === null
               && ($Record->context['URI'] ?? null) === '/path?token=secret'
               && str_starts_with($Record->message, 'GET /path?token=secret → 200')
            )
            ->to->be(true)
            ->assert();

         // @ A Formatter builds the message from the neutralized fields
         $Capture = $capture();
         $Custom = $build(new AccessLog(Formatter: static fn (array $entry): string => "{$entry['method']} {$entry['target']} {$entry['code']} {$entry['URI']}"), $Capture);
         [$Request, $Response] = $createMocks(requestProps: ['URI' => '/a@b', 'protocol' => 'HTTP/1.1']);
         $Custom->process($Request, $Response, static fn (object $Request, object $Response): object => $Response(code: 200, body: ''));
         yield new Assertion(
            description: 'The Formatter receives the context plus the neutralized target and method; its return is the message',
            fallback: (string) ($Capture->Records[0] ?? null)?->message
         )
            ->expect(($Capture->Records[0] ?? null)?->message)
            ->to->be('GET /a%40b 200 /a@b@.;')
            ->assert();

         // @ A failing sink never fails the request
         $Broken = $build(new AccessLog, new class extends Handler {
            protected function write (string $formatted, Record $Record): bool
            {
               throw new RuntimeException('disk full');
            }
         });
         [$Request, $Response] = $createMocks(requestProps: $props);
         $failed = false;
         try {
            $Result = $Broken->process($Request, $Response, static fn (object $Request, object $Response): object => $Response(code: 200, body: ''));
         }
         catch (Throwable) {
            $failed = true;
         }
         yield new Assertion(
            description: 'A handler that throws is contained: process() returns the Response',
         )
            ->expect($failed === false && ($Result ?? null) === $Response)
            ->to->be(true)
            ->assert();
      }
      finally {
         Display::$segments = $saved;
      }
   })
);
