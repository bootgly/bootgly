<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RequestId;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * M1 PoC — route-cache lifecycle boundaries must not replay one request's
 * middleware/event output to a later request.
 *
 * This case invokes the production Encoder_ directly. The ordinary cache
 * control proves a real handler-skipping hit. Two adversarial pairs then
 * exercise the exact finding:
 *
 * 1. RequestId writes the current request's header before `$Next`; a hit must
 *    not return the primer's raw wire and discard that live header.
 * 2. A synchronous Handled listener appends a per-request body marker; a hit
 *    must not overwrite event 2 with the cached event-1 body.
 *
 * A `Cache-Control: no-cache` request follows each pair as a matched live-path
 * control. A remediation may either compose current mutations with a hit or
 * decline storage/replay for that lifecycle; both are safe.
 */
$probe = [
   'error' => '',
   'cache' => [],
   'request_id' => [],
   'request_id_transition' => [],
   'handled' => [],
   'handled_transition' => [],
   'late_handled_clone_guard' => [],
   'deferred_generation' => [],
   'deferred_guard' => [],
];

return new Test(
   description: 'Route-cache hits must preserve current middleware and Handled output',
   Separator: new Separator(line: true),

   request: static function () use (&$probe): string {
      $socket = tmpfile();
      if (! is_resource($socket)) {
         $probe['error'] = 'Could not allocate the production-encoder stream surrogate.';

         return "GET /m1-lifecycle-harness HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
      }

      $OldRequest = Server::$Request;
      $OldResponse = Server::$Response;
      $OldRouter = Server::$Router;
      $OldDecoder = Server::$Decoder;
      $OldHandler = SAPI::$Handler ?? null;
      $OldMiddlewares = SAPI::$Middlewares ?? null;
      $OldEmitter = Emitter::$Instance;
      $oldEntries = Cache::$entries;
      $oldBytes = Cache::$bytes;
      $oldURIs = Cache::$URIs;
      $oldGeneration = Cache::$generation;

      try {
         /** @var Connection $Connection */
         $Connection = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
         $Connection->Socket = $socket;
         $Connection->timers = [];
         $Connection->ip = '127.0.0.1';
         $Connection->port = 12345;
         $Connection->encrypted = false;
         $Connection->writes = 0;

         $Encode = static function (string $raw) use ($Connection): string {
            $Package = new class($Connection) extends TCPPackages {
               public function __construct (Connection $Connection)
               {
                  $this->Connection = $Connection;

                  $this->cache = true;
                  $this->changed = true;
                  $this->input = '';
                  $this->output = '';
                  $this->callbacks = [&$this->input];
                  $this->expired = false;
                  $this->consumed = 0;
                  $this->rejected = false;

                  $this->downloading = [];
                  $this->uploading = [];
                  $this->closeAfterWrite = false;
               }
            };

            Server::$Request = new Request;
            Server::$Decoder = new Decoder_;

            $size = strlen($raw);
            $State = Server::$Request->decode($Package, $raw, $size);
            if (
               $State !== States::Complete
               || $Package->consumed !== $size
               || $Package->rejected
            ) {
               throw new RuntimeException('Production Request::decode() rejected the PoC request.');
            }

            $length = null;

            return Encoder_::encode($Package, $length);
         };

         $Body = static function (string $wire): null|string {
            $separator = strpos($wire, "\r\n\r\n");

            return $separator === false ? null : substr($wire, $separator + 4);
         };
         $Header = static function (string $wire, string $name): null|string {
            $quoted = preg_quote($name, '/');
            if (preg_match("/^{$quoted}:\\s*([^\\r\\n]+)\\r?$/mi", $wire, $matches) !== 1) {
               return null;
            }

            return $matches[1];
         };

         // ? Positive control: the same production encoder must perform an
         //   ordinary handler-skipping route-cache hit.
         Cache::flush();
         Emitter::$Instance = new Emitter;
         SAPI::$Middlewares = new Middlewares;
         Server::$Response = new Response;
         Server::$Router = new Router;
         $cacheHandlers = 0;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use (&$cacheHandlers): Generator {
            yield $Router->route('/m1-cache-control', static function (
               Request $Request,
               Response $Response
            ) use (&$cacheHandlers): Response {
               return $Response(body: 'M1-CACHE:handler=' . ++$cacheHandlers);
            }, GET, cache: ['TTL' => 60]);
         };

         $cacheCold = $Encode(
            "GET /m1-cache-control HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         $cacheWarm = $Encode(
            "GET /m1-cache-control HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         $probe['cache'] = [
            'handlers' => $cacheHandlers,
            'entries' => count(Cache::$entries),
            'cold_body' => $Body($cacheCold),
            'warm_body' => $Body($cacheWarm),
         ];

         // ! Variant 1: RequestId writes before `$Next`.
         Cache::flush();
         Emitter::$Instance = new Emitter;
         $RequestID = new RequestId;
         SAPI::$Middlewares = (new Middlewares)->append($RequestID);
         Server::$Response = new Response;
         Server::$Router = new Router;
         $requestIDHandlers = 0;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use (&$requestIDHandlers): Generator {
            yield $Router->route('/m1-request-id', static function (
               Request $Request,
               Response $Response
            ) use (&$requestIDHandlers): Response {
               return $Response(body: 'M1-REQUEST-ID:handler=' . ++$requestIDHandlers);
            }, GET, cache: ['TTL' => 60]);
         };

         $requestIDCold = $Encode(
            "GET /m1-request-id HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Request-Id: m1-primer\r\n\r\n"
         );
         $requestIDWarm = $Encode(
            "GET /m1-request-id HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Request-Id: m1-current\r\n\r\n"
         );
         $requestIDWarmHandlers = $requestIDHandlers;
         $requestIDEntries = count(Cache::$entries);
         $requestIDControl = $Encode(
            "GET /m1-request-id HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Request-Id: m1-control\r\n"
            . "Cache-Control: no-cache\r\n\r\n"
         );
         $probe['request_id'] = [
            'warm_handlers' => $requestIDWarmHandlers,
            'control_handlers' => $requestIDHandlers,
            'entries_after_warm' => $requestIDEntries,
            'cold_id' => $Header($requestIDCold, 'X-Request-Id'),
            'warm_id' => $Header($requestIDWarm, 'X-Request-Id'),
            'control_id' => $Header($requestIDControl, 'X-Request-Id'),
            'cold_body' => $Body($requestIDCold),
            'warm_body' => $Body($requestIDWarm),
            'control_body' => $Body($requestIDControl),
         ];

         // ! Variant 1b: an already-primed clean entry must become
         //   ineligible when pre-$Next response mutation is enabled later.
         Cache::flush();
         Emitter::$Instance = new Emitter;
         SAPI::$Middlewares = new Middlewares;
         Server::$Response = new Response;
         Server::$Router = new Router;
         $requestIDTransitionHandlers = 0;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use (&$requestIDTransitionHandlers): Generator {
            yield $Router->route('/m1-request-id-transition', static function (
               Request $Request,
               Response $Response
            ) use (&$requestIDTransitionHandlers): Response {
               return $Response(
                  body: 'M1-REQUEST-ID-TRANSITION:handler=' . ++$requestIDTransitionHandlers
               );
            }, GET, cache: ['TTL' => 60]);
         };

         $requestIDTransitionCold = $Encode(
            "GET /m1-request-id-transition HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         SAPI::$Middlewares = (new Middlewares)->append(new RequestId);
         $requestIDTransitionWarm = $Encode(
            "GET /m1-request-id-transition HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Request-Id: m1-transition-current\r\n\r\n"
         );
         SAPI::$Middlewares = new Middlewares;
         $requestIDTransitionRecovery = $Encode(
            "GET /m1-request-id-transition HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         $probe['request_id_transition'] = [
            'handlers' => $requestIDTransitionHandlers,
            'entries' => count(Cache::$entries),
            'cold_body' => $Body($requestIDTransitionCold),
            'warm_body' => $Body($requestIDTransitionWarm),
            'warm_id' => $Header($requestIDTransitionWarm, 'X-Request-Id'),
            'recovery_body' => $Body($requestIDTransitionRecovery),
            'recovery_id' => $Header($requestIDTransitionRecovery, 'X-Request-Id'),
         ];

         // ! Variant 2: Handled mutates after the admission snapshot.
         Cache::flush();
         $Events = new Emitter;
         Emitter::$Instance = $Events;
         SAPI::$Middlewares = new Middlewares;
         Server::$Response = new Response;
         Server::$Router = new Router;
         $handledHandlers = 0;
         $handledEvents = 0;
         $handledObserved = [];
         $Events->listen(
            RequestEvents::Handled,
            static function (Emission $Emission) use (&$handledEvents, &$handledObserved): void {
               /** @var Request $Request */
               /** @var Response $Response */
               [$Request, $Response] = $Emission->payload;
               if ($Request->URI !== '/m1-handled') {
                  return;
               }

               $handledObserved[] = $Response->Body->raw;
               $handledEvents++;
               $Response->Body->raw .= ';event=' . $handledEvents;
               $Response->Header->set('X-M1-Event', (string) $handledEvents);
            }
         );
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use (&$handledHandlers): Generator {
            yield $Router->route('/m1-handled', static function (
               Request $Request,
               Response $Response
            ) use (&$handledHandlers): Response {
               return $Response(body: 'M1-HANDLED:handler=' . ++$handledHandlers);
            }, GET, cache: ['TTL' => 60]);
         };

         $handledCold = $Encode(
            "GET /m1-handled HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         $handledWarm = $Encode(
            "GET /m1-handled HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         $handledWarmHandlers = $handledHandlers;
         $handledEntries = count(Cache::$entries);
         $handledControl = $Encode(
            "GET /m1-handled HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Cache-Control: no-cache\r\n\r\n"
         );
         $probe['handled'] = [
            'warm_handlers' => $handledWarmHandlers,
            'control_handlers' => $handledHandlers,
            'events' => $handledEvents,
            'observed_bodies' => $handledObserved,
            'entries_after_warm' => $handledEntries,
            'cold_event' => $Header($handledCold, 'X-M1-Event'),
            'warm_event' => $Header($handledWarm, 'X-M1-Event'),
            'control_event' => $Header($handledControl, 'X-M1-Event'),
            'cold_body' => $Body($handledCold),
            'warm_body' => $Body($handledWarm),
            'control_body' => $Body($handledControl),
         ];

         // ! Variant 2b: a listener registered after a clean prime must not
         //   consume that old entry or observe an empty live Response.
         Cache::flush();
         $TransitionEvents = new Emitter;
         Emitter::$Instance = $TransitionEvents;
         SAPI::$Middlewares = new Middlewares;
         Server::$Response = new Response;
         Server::$Router = new Router;
         $handledTransitionHandlers = 0;
         $handledTransitionObserved = [];
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use (&$handledTransitionHandlers): Generator {
            yield $Router->route('/m1-handled-transition', static function (
               Request $Request,
               Response $Response
            ) use (&$handledTransitionHandlers): Response {
               return $Response(
                  body: 'M1-HANDLED-TRANSITION:handler=' . ++$handledTransitionHandlers
               );
            }, GET, cache: ['TTL' => 60]);
         };

         $handledTransitionCold = $Encode(
            "GET /m1-handled-transition HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         $TransitionEvents->listen(
            RequestEvents::Handled,
            static function (Emission $Emission) use (&$handledTransitionObserved): void {
               /** @var Request $Request */
               /** @var Response $Response */
               [$Request, $Response] = $Emission->payload;
               if ($Request->URI !== '/m1-handled-transition') {
                  return;
               }

               $handledTransitionObserved[] = $Response->Body->raw;
               $Response->Body->raw .= ';transition-event=current';
            }
         );
         $handledTransitionWarm = $Encode(
            "GET /m1-handled-transition HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         Emitter::$Instance = new Emitter;
         $handledTransitionRecovery = $Encode(
            "GET /m1-handled-transition HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         $probe['handled_transition'] = [
            'handlers' => $handledTransitionHandlers,
            'entries' => count(Cache::$entries),
            'observed_bodies' => $handledTransitionObserved,
            'cold_body' => $Body($handledTransitionCold),
            'warm_body' => $Body($handledTransitionWarm),
            'recovery_body' => $Body($handledTransitionRecovery),
         ];

         // ! Variant 2c: a listener registered by the handler runs after the
         //   admission snapshot. It must observe an already guarded Response;
         //   otherwise cloning/stashing inside the callback bypasses the
         //   encoder's later storage gate.
         Cache::flush();
         $LateEvents = new Emitter;
         Emitter::$Instance = $LateEvents;
         SAPI::$Middlewares = new Middlewares;
         Server::$Response = new Response;
         Server::$Router = new Router;
         $lateHandledHandlers = 0;
         $lateHandledEvents = 0;
         $lateCloneCacheable = null;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use (
            $LateEvents,
            &$lateHandledHandlers,
            &$lateHandledEvents,
            &$lateCloneCacheable
         ): Generator {
            yield $Router->route('/m1-late-handled-clone', static function (
               Request $Request,
               Response $Response
            ) use (
               $LateEvents,
               &$lateHandledHandlers,
               &$lateHandledEvents,
               &$lateCloneCacheable
            ): Response {
               $LateEvents->listen(
                  RequestEvents::Handled,
                  static function (Emission $Emission) use (
                     &$lateHandledEvents,
                     &$lateCloneCacheable
                  ): void {
                     /** @var Request $Request */
                     /** @var Response $Response */
                     [$Request, $Response] = $Emission->payload;
                     if ($Request->URI !== '/m1-late-handled-clone') {
                        return;
                     }

                     $lateHandledEvents++;
                     $DeferredResponse = clone $Response;
                     $lateCloneCacheable = $DeferredResponse->cacheable;
                     $DeferredResponse->stash(
                        "HTTP/1.1 200 OK\r\nContent-Length: 15\r\n\r\nM1-LATE-HANDLED"
                     );
                  }
               );

               return $Response(
                  body: 'M1-LATE-HANDLED:handler=' . ++$lateHandledHandlers
               );
            }, GET, cache: ['TTL' => 60]);
         };

         $lateHandled = $Encode(
            "GET /m1-late-handled-clone HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         $probe['late_handled_clone_guard'] = [
            'handlers' => $lateHandledHandlers,
            'events' => $lateHandledEvents,
            'clone_cacheable' => $lateCloneCacheable,
            'entries' => count(Cache::$entries),
            'body' => $Body($lateHandled),
         ];

         // ! Variant 2d: a cache-eligible clone can outlive the clean request
         //   that created it. A later lifecycle transition must invalidate
         //   that delayed writer, not merely flush entries already present.
         Cache::flush();
         Emitter::$Instance = new Emitter;
         SAPI::$Middlewares = new Middlewares;
         Server::$Response = new Response;
         Server::$Router = new Router;
         $deferredGenerationHandlers = 0;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use (&$deferredGenerationHandlers): Generator {
            yield $Router->route('/m1-deferred-generation', static function (
               Request $Request,
               Response $Response
            ) use (&$deferredGenerationHandlers): Response {
               return $Response(
                  body: 'M1-DEFERRED-GENERATION:handler=' . ++$deferredGenerationHandlers
               );
            }, GET, cache: ['TTL' => 60]);
         };

         $deferredGenerationCold = $Encode(
            "GET /m1-deferred-generation HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         $DelayedResponse = clone Server::$Response;
         $DelayedResponse->cache = 60;
         $generationBefore = Cache::$generation;

         SAPI::$Middlewares = (new Middlewares)->append(new RequestId);
         $deferredGenerationMediated = $Encode(
            "GET /m1-deferred-generation HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Request-Id: m1-generation-current\r\n\r\n"
         );
         $generationAfter = Cache::$generation;

         SAPI::$Middlewares = new Middlewares;
         $DelayedResponse->stash(
            "HTTP/1.1 200 OK\r\nContent-Length: 17\r\n\r\nM1-DEFERRED-STALE"
         );
         $delayedEntries = count(Cache::$entries);
         $deferredGenerationRecovery = $Encode(
            "GET /m1-deferred-generation HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         $probe['deferred_generation'] = [
            'handlers' => $deferredGenerationHandlers,
            'delayed_entries' => $delayedEntries,
            'entries' => count(Cache::$entries),
            'generation_before' => $generationBefore,
            'generation_after' => $generationAfter,
            'cold_body' => $Body($deferredGenerationCold),
            'mediated_body' => $Body($deferredGenerationMediated),
            'mediated_id' => $Header($deferredGenerationMediated, 'X-Request-Id'),
            'recovery_body' => $Body($deferredGenerationRecovery),
         ];

         // ! Deferred work stores from a private Response clone after the
         //   synchronous encoder has returned. The lifecycle eligibility flag
         //   must survive that clone and block the later stash path.
         Cache::flush();
         $DeferredSource = Server::$Response;
         $DeferredSource->cache = 60;
         $DeferredSource->guard();
         $DeferredResponse = clone $DeferredSource;
         $DeferredResponse->stash(
            "HTTP/1.1 200 OK\r\nContent-Length: 17\r\n\r\nM1-DEFERRED-GUARD"
         );
         $probe['deferred_guard'] = [
            'cacheable' => $DeferredResponse->cacheable,
            'entries' => count(Cache::$entries),
         ];

         // @ Clear Encoder_'s private replay roots before restoring the
         //   process-global objects. A neutral non-cache response forces the
         //   guarded reset at the next encode() without creating a new entry.
         Cache::flush();
         Emitter::$Instance = new Emitter;
         SAPI::$Middlewares = new Middlewares;
         Server::$Response = new Response;
         Server::$Router = new Router;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ): Response {
            return $Response(body: 'M1-NEUTRAL');
         };
         $neutral = $Encode(
            "GET /m1-neutral HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         if ($Body($neutral) !== 'M1-NEUTRAL') {
            throw new RuntimeException('Could not clear the production encoder replay state.');
         }
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         Cache::$entries = $oldEntries;
         Cache::$bytes = $oldBytes;
         Cache::$URIs = $oldURIs;
         Cache::$generation = $oldGeneration;
         Emitter::$Instance = $OldEmitter;
         Server::$Request = $OldRequest;
         Server::$Response = $OldResponse;
         Server::$Router = $OldRouter;
         Server::$Decoder = $OldDecoder;

         if ($OldHandler !== null) {
            SAPI::$Handler = $OldHandler;
         }
         if ($OldMiddlewares !== null) {
            SAPI::$Middlewares = $OldMiddlewares;
         }

         @fclose($socket);
      }

      return "GET /m1-lifecycle-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield $Router->route('/m1-lifecycle-harness', static function (
         Request $Request,
         Response $Response
      ): Response {
         return $Response(body: 'M1-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['M1 production probe'];
         dump(json_encode($probe));

         return 'M1 fixture failed before validation: ' . $probe['error'];
      }

      if (! str_contains($response, 'M1-HARNESS-OK')) {
         Vars::$labels = ['M1 harness response', 'M1 production probe'];
         dump(json_encode($response), json_encode($probe));

         return 'M1 fixture failed: the registered native harness route did not execute.';
      }

      $cache = $probe['cache'];
      if (
         ($cache['handlers'] ?? null) !== 1
         || ($cache['entries'] ?? null) !== 1
         || ($cache['cold_body'] ?? null) !== 'M1-CACHE:handler=1'
         || ($cache['warm_body'] ?? null) !== 'M1-CACHE:handler=1'
      ) {
         Vars::$labels = ['M1 route-cache positive control'];
         dump(json_encode($probe));

         return 'M1 control failed: the production route cache did not perform a handler-skipping hit.';
      }

      $requestID = $probe['request_id'];
      if (
         ($requestID['cold_id'] ?? null) !== 'm1-primer'
         || ($requestID['control_id'] ?? null) !== 'm1-control'
         || ($requestID['cold_body'] ?? null) !== 'M1-REQUEST-ID:handler=1'
         || ($requestID['warm_handlers'] ?? null) !== 2
         || ($requestID['entries_after_warm'] ?? null) !== 0
         || ($requestID['warm_body'] ?? null)
            !== 'M1-REQUEST-ID:handler=' . (string) ($requestID['warm_handlers'] ?? 0)
         || ($requestID['control_handlers'] ?? 0) !== ($requestID['warm_handlers'] ?? -1) + 1
         || ($requestID['control_body'] ?? null)
            !== 'M1-REQUEST-ID:handler=' . (string) ($requestID['control_handlers'] ?? 0)
      ) {
         Vars::$labels = ['M1 RequestId controls'];
         dump(json_encode($probe));

         return 'M1 control failed: RequestId or the matched no-cache path did not execute as expected.';
      }

      $requestIDTransition = $probe['request_id_transition'];
      if (
         ($requestIDTransition['handlers'] ?? null) !== 3
         || ($requestIDTransition['entries'] ?? null) !== 1
         || ($requestIDTransition['cold_body'] ?? null)
            !== 'M1-REQUEST-ID-TRANSITION:handler=1'
         || ($requestIDTransition['warm_body'] ?? null)
            !== 'M1-REQUEST-ID-TRANSITION:handler=2'
         || ($requestIDTransition['warm_id'] ?? null) !== 'm1-transition-current'
         || ($requestIDTransition['recovery_body'] ?? null)
            !== 'M1-REQUEST-ID-TRANSITION:handler=3'
         || ($requestIDTransition['recovery_id'] ?? null) !== null
      ) {
         Vars::$labels = ['M1 RequestId lifecycle transition'];
         dump(json_encode($probe));

         return 'M1 replay guard failed: a clean entry survived later pre-Next response mutation.';
      }

      $handled = $probe['handled'];
      $observedBodies = $handled['observed_bodies'] ?? null;
      if (
         ($handled['events'] ?? null) !== 3
         || ($handled['warm_handlers'] ?? null) !== 2
         || ($handled['entries_after_warm'] ?? null) !== 0
         || ! is_array($observedBodies)
         || count($observedBodies) !== 3
         || ($observedBodies[0] ?? null) !== 'M1-HANDLED:handler=1'
         || ($observedBodies[2] ?? null)
            !== 'M1-HANDLED:handler=' . (string) ($handled['control_handlers'] ?? 0)
         || ($handled['cold_event'] ?? null) !== '1'
         || ($handled['warm_event'] ?? null) !== '2'
         || ($handled['control_event'] ?? null) !== '3'
         || ($handled['cold_body'] ?? null) !== 'M1-HANDLED:handler=1;event=1'
         || ($handled['control_handlers'] ?? 0) !== ($handled['warm_handlers'] ?? -1) + 1
         || ($handled['control_body'] ?? null)
            !== 'M1-HANDLED:handler=' . (string) ($handled['control_handlers'] ?? 0) . ';event=3'
      ) {
         Vars::$labels = ['M1 Handled controls'];
         dump(json_encode($probe));

         return 'M1 control failed: Handled or the matched no-cache path did not execute as expected.';
      }

      $handledTransition = $probe['handled_transition'];
      if (
         ($handledTransition['handlers'] ?? null) !== 3
         || ($handledTransition['entries'] ?? null) !== 1
         || ($handledTransition['cold_body'] ?? null)
            !== 'M1-HANDLED-TRANSITION:handler=1'
         || ($handledTransition['observed_bodies'] ?? null)
            !== ['M1-HANDLED-TRANSITION:handler=2']
         || ($handledTransition['warm_body'] ?? null)
            !== 'M1-HANDLED-TRANSITION:handler=2;transition-event=current'
         || ($handledTransition['recovery_body'] ?? null)
            !== 'M1-HANDLED-TRANSITION:handler=3'
      ) {
         Vars::$labels = ['M1 Handled lifecycle transition'];
         dump(json_encode($probe));

         return 'M1 replay guard failed: a clean entry survived a later Handled listener.';
      }

      $lateHandledCloneGuard = $probe['late_handled_clone_guard'];
      if (
         ($lateHandledCloneGuard['handlers'] ?? null) !== 1
         || ($lateHandledCloneGuard['events'] ?? null) !== 1
         || ($lateHandledCloneGuard['clone_cacheable'] ?? null) !== false
         || ($lateHandledCloneGuard['entries'] ?? null) !== 0
         || ($lateHandledCloneGuard['body'] ?? null) !== 'M1-LATE-HANDLED:handler=1'
      ) {
         Vars::$labels = ['M1 late Handled clone guard'];
         dump(json_encode($probe));

         return 'M1 late-event guard failed: Handled cloned cache-eligible response state.';
      }

      $deferredGeneration = $probe['deferred_generation'];
      if (
         ($deferredGeneration['handlers'] ?? null) !== 3
         || ($deferredGeneration['delayed_entries'] ?? null) !== 0
         || ($deferredGeneration['entries'] ?? null) !== 1
         || ($deferredGeneration['generation_after'] ?? -1)
            !== ($deferredGeneration['generation_before'] ?? -2) + 1
         || ($deferredGeneration['cold_body'] ?? null)
            !== 'M1-DEFERRED-GENERATION:handler=1'
         || ($deferredGeneration['mediated_body'] ?? null)
            !== 'M1-DEFERRED-GENERATION:handler=2'
         || ($deferredGeneration['mediated_id'] ?? null) !== 'm1-generation-current'
         || ($deferredGeneration['recovery_body'] ?? null)
            !== 'M1-DEFERRED-GENERATION:handler=3'
      ) {
         Vars::$labels = ['M1 deferred generation guard'];
         dump(json_encode($probe));

         return 'M1 generation guard failed: a pre-transition clone repopulated stale cache wire.';
      }

      $deferredGuard = $probe['deferred_guard'];
      if (
         ($deferredGuard['cacheable'] ?? null) !== false
         || ($deferredGuard['entries'] ?? null) !== 0
      ) {
         Vars::$labels = ['M1 deferred route-cache guard'];
         dump(json_encode($probe));

         return 'M1 deferred guard failed: an ineligible Response clone stored shared wire.';
      }

      $violations = [];
      if (
         ($requestID['warm_handlers'] ?? null) === 1
         && ($requestID['entries_after_warm'] ?? null) === 1
         && ($requestID['warm_id'] ?? null) === 'm1-primer'
      ) {
         $violations[] = 'RequestId `m1-current` was discarded and `m1-primer` replayed';
      }
      else if (($requestID['warm_id'] ?? null) !== 'm1-current') {
         Vars::$labels = ['M1 RequestId ambiguous result'];
         dump(json_encode($probe));

         return 'M1 fixture produced neither a safe current ID nor the directly attributable stale replay.';
      }

      $safeHandledBody = 'M1-HANDLED:handler='
         . (string) ($handled['warm_handlers'] ?? 0)
         . ';event=2';
      if (
         ($handled['warm_handlers'] ?? null) === 1
         && ($handled['entries_after_warm'] ?? null) === 1
         && ($observedBodies[1] ?? null) === ''
         && ($handled['warm_body'] ?? null) === 'M1-HANDLED:handler=1;event=1'
      ) {
         $violations[] = 'Handled event 2 ran, but the cached event-1 body overwrote it';
      }
      else if (($handled['warm_body'] ?? null) !== $safeHandledBody) {
         Vars::$labels = ['M1 Handled ambiguous result'];
         dump(json_encode($probe));

         return 'M1 fixture produced neither current Handled output nor the directly attributable stale replay.';
      }

      if ($violations !== []) {
         Vars::$labels = ['M1 confirmed production evidence'];
         dump(json_encode($probe));

         return 'CONFIRMED M1: route-cache lifecycle boundaries replayed stale '
            . 'per-request output — ' . implode('; ', $violations) . '.';
      }

      return true;
   },
);
