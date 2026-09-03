<?php


use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handler;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
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
            ->expect(preg_match('/^GET \/path → 200 in [0-9.]+ms$/', (string) $Record?->message) === 1)
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

         // ! The captured snapshot shares the generation's exchange — that is
         //   how the sealing pass reaches the entry, with nothing parked on
         //   the Request itself
         $Snapshot = new Request;
         $Snapshot->address = '10.0.0.7';
         Exchange::admit($Snapshot, $Exchange);
         $Sealed = new Response(code: 204, body: 'abc');
         $AccessLog->seal($Snapshot, $Sealed);
         yield new Assertion(
            description: 'seal() records the wire (status, bytes, address) and writes nothing',
            fallback: json_encode(['records' => count($Capture->Records)])
         )
            ->expect(count($Capture->Records))
            ->to->be(0)
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
               && array_key_exists('bytes', $Record->context) && $Record->context['bytes'] === null
               && ($Record->context['deferred'] ?? null) === true
               && ($Record->context['ms'] ?? 0) >= 5
               && preg_match('/^GET \/path → cancelled after [0-9.]+ms$/', $Record->message) === 1
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
            ->expect($thrown instanceof LogicException && count($Capture->Records) === 1 && ($Record?->context['code'] ?? null) === 201 && ($Record->context['throwable'] ?? null) === LogicException::class)
            ->to->be(true)
            ->assert();
         Exchange::track($Response, null);

         // @ An outer middleware that throws while the deferral is still in
         //   flight: the wire belongs to the generation, so its status wins
         //   over the 500 this throw would produce
         $Capture->Records = [];
         [$Request, $Response] = $createMocks(requestProps: $props);
         $Exchange = new Exchange;
         Exchange::track($Response, $Exchange);
         $thrown = null;
         try {
            $AccessLog->process($Request, $Response, static function (object $Request, object $Response): object {
               $Response->deferred = true;
               throw new DomainException('outer middleware');
            });
         }
         catch (DomainException $Throwable) {
            $thrown = $Throwable;
         }
         yield new Assertion(
            description: 'A throw around a deferral in flight writes nothing yet — the lifecycle still owns the outcome',
            fallback: json_encode(['records' => count($Capture->Records), 'context' => ($Capture->Records[0] ?? null)?->context])
         )
            ->expect($thrown instanceof DomainException && count($Capture->Records) === 0)
            ->to->be(true)
            ->assert();

         $Exchange->finish(new Response(code: 200, body: 'wire'));
         $Record = $Capture->Records[0] ?? null;
         yield new Assertion(
            description: 'It then writes the status the wire carried, flagged deferred, with the Throwable that was raised around it',
            fallback: json_encode(['records' => count($Capture->Records), 'context' => $Record?->context])
         )
            ->expect(
               count($Capture->Records) === 1
               && $Record?->Level === Levels::Info
               && ($Record->context['code'] ?? null) === 200
               && ($Record->context['deferred'] ?? null) === true
               && ($Record->context['throwable'] ?? null) === DomainException::class
            )
            ->to->be(true)
            ->assert();
         Exchange::track($Response, null);

         // @ Two settlements, one line: the guard is what makes the claim true
         $Capture->Records = [];
         [$Request, $Response] = $createMocks(requestProps: $props);
         $Exchange = new Exchange;
         Exchange::track($Response, $Exchange);
         $AccessLog->process($Request, $Response, static function (object $Request, object $Response): object {
            $Response->deferred = true;
            return $Response(code: 200, body: 'x');
         });
         $Snapshot = new Request;
         Exchange::admit($Snapshot, $Exchange);
         $AccessLog->seal($Snapshot, new Response(code: 200, body: 'x'));
         $Exchange->finish(new Response(code: 200, body: 'x'));
         $Exchange->finish(null);
         // ! No settlement path can run twice on its own — the exchange is
         //   terminal once — so the guard is proven where it lives: the entry
         //   itself refuses a second line, whatever calls for one
         $Entries = new ReflectionProperty(AccessLog::class, 'Entries');
         $Write = new ReflectionMethod(AccessLog::class, 'write');
         $Write->invoke($AccessLog, $Entries->getValue($AccessLog)[$Exchange]);
         yield new Assertion(
            description: 'A second settlement — a re-seal, a cancellation after the answer, a second writer — never adds a line',
            fallback: json_encode(['records' => count($Capture->Records)])
         )
            ->expect(count($Capture->Records))
            ->to->be(1)
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
         $Custom = $build(new AccessLog(Formatter: static fn (array $entry): string => implode('|', [$entry['method'], $entry['target'], $entry['code'], $entry['URI'] ?? 'absent'])), $Capture);
         [$Request, $Response] = $createMocks(requestProps: ['URI' => '/a@b', 'protocol' => 'HTTP/1.1']);
         $Custom->process($Request, $Response, static fn (object $Request, object $Response): object => $Response(code: 200, body: ''));
         yield new Assertion(
            description: 'The Formatter gets the neutralized target and method, never the raw target — a message it builds cannot carry a directive',
            fallback: (string) ($Capture->Records[0] ?? null)?->message
         )
            ->expect(($Capture->Records[0] ?? null)?->message)
            ->to->be('GET|/a%40b|200|absent')
            ->assert();

         // @ A Formatter that fails costs its shape, not the line nor the request
         $Capture = $capture();
         $Broken = $build(new AccessLog(Formatter: static function (array $entry): string {
            throw new RuntimeException('formatter blew up');
         }), $Capture);
         [$Request, $Response] = $createMocks(requestProps: $props);
         $failed = false;
         try {
            $Result = $Broken->process($Request, $Response, static fn (object $Request, object $Response): object => $Response(code: 200, body: ''));
         }
         catch (Throwable) {
            $failed = true;
         }
         yield new Assertion(
            description: 'A throwing Formatter never fails the request, and the default line is written anyway',
            fallback: json_encode(['failed' => $failed, 'records' => count($Capture->Records), 'message' => ($Capture->Records[0] ?? null)?->message])
         )
            ->expect(
               $failed === false
               && ($Result ?? null) === $Response
               && count($Capture->Records) === 1
               && preg_match('/^GET \/path → 200 in [0-9.]+ms$/', (string) $Capture->Records[0]->message) === 1
            )
            ->to->be(true)
            ->assert();

         // @ The route response cache must stay shareable: whatever the
         //   middleware keeps per request never reaches the key the cache
         //   composes from the Request's principal bags
         $Compose = new ReflectionMethod(Cache::class, 'compose');
         $keys = [];
         for ($i = 0; $i < 2; $i++) {
            $Live = new Request;
            $Live->method = 'GET';
            $Live->address = '127.0.0.1';
            $Logged = $build(new AccessLog, $capture());
            $Logged->process($Live, $createMocks()[1], static fn (object $Request, object $Response): object => $Response(code: 200, body: ''));
            $keys[] = $Compose->invoke(null, $Live, false);
         }
         yield new Assertion(
            description: 'The middleware leaves the principal bag untouched, so two identical requests still compose ONE route-cache key',
            fallback: json_encode(['keys' => $keys, 'attributes' => $Live->attributes])
         )
            ->expect($keys[0] === $keys[1] && $Live->attributes === [])
            ->to->be(true)
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
