<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * M1 residual PoC — a Received listener can change a persistent response
 * preset before Response::reset(), but route-cache admission does not treat
 * that lifecycle as mediated.
 *
 * The ordinary cached route is primed with one request-specific preset and
 * requested again with a different value. The no-cache request proves the
 * listener's current value otherwise reaches the production wire.
 */
$probe = [
   'error' => '',
   'events' => 0,
   'warm_handlers' => 0,
   'control_handlers' => 0,
   'entries_after_warm' => 0,
   'cold_header' => null,
   'warm_header' => null,
   'control_header' => null,
   'cold_body' => null,
   'warm_body' => null,
   'control_body' => null,
   'transition' => [],
];

return new Specification(
   description: 'Route-cache hits must preserve current Received-listener presets',
   Separator: new Separator(line: true),

   request: static function () use (&$probe): string {
      $socket = tmpfile();
      if (! is_resource($socket)) {
         $probe['error'] = 'Could not allocate the production-encoder stream surrogate.';

         return "GET /m1-received-preset-harness HTTP/1.1\r\n"
            . "Host: localhost\r\nConnection: close\r\n\r\n";
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

         Cache::flush();
         $Events = new Emitter;
         Emitter::$Instance = $Events;
         SAPI::$Middlewares = new Middlewares;
         Server::$Response = new Response;
         Server::$Router = new Router;

         $events = 0;
         $handlers = 0;
         $Events->listen(
            RequestEvents::Received,
            static function (Emission $Emission) use (&$events): void {
               /** @var Request $Request */
               [$Request] = $Emission->payload;
               if ($Request->URI !== '/m1-received-preset') {
                  return;
               }

               $events++;
               Server::$Response->Header->preset(
                  'X-M1-Received-Preset',
                  $Request->headers['x-m1-preset'] ?? 'missing'
               );
            }
         );
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use (&$handlers): Generator {
            yield $Router->route('/m1-received-preset', static function (
               Request $Request,
               Response $Response
            ) use (&$handlers): Response {
               return $Response(
                  body: 'M1-RECEIVED-PRESET:handler=' . ++$handlers
               );
            }, GET, cache: ['TTL' => 60]);
         };

         $cold = $Encode(
            "GET /m1-received-preset HTTP/1.1\r\n"
            . "Host: localhost\r\nX-M1-Preset: primer\r\n\r\n"
         );
         $warm = $Encode(
            "GET /m1-received-preset HTTP/1.1\r\n"
            . "Host: localhost\r\nX-M1-Preset: current\r\n\r\n"
         );
         $warmHandlers = $handlers;
         $entriesAfterWarm = count(Cache::$entries);
         $control = $Encode(
            "GET /m1-received-preset HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-M1-Preset: control\r\n"
            . "Cache-Control: no-cache\r\n\r\n"
         );

         // @ Prove the fail-closed transition itself. Prime while no listener
         //   exists, install Received only after storage, then remove it again.
         //   The listener also attempts to stash from the pre-reset singleton:
         //   both the old entry and that synchronous write must be absent while
         //   the listener is running.
         Cache::flush();
         Emitter::$Instance = new Emitter;
         SAPI::$Middlewares = new Middlewares;
         Server::$Response = new Response;
         Server::$Router = new Router;

         $transitionEvents = 0;
         $transitionHandlers = 0;
         $entriesOnEvent = -1;
         $preResetStashEntries = -1;
         $snapshotCacheable = null;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use (&$transitionHandlers): Generator {
            yield $Router->route('/m1-received-transition', static function (
               Request $Request,
               Response $Response
            ) use (&$transitionHandlers): Response {
               return $Response(
                  body: 'M1-RECEIVED-TRANSITION:handler=' . ++$transitionHandlers
               );
            }, GET, cache: ['TTL' => 60]);
         };

         $transitionCold = $Encode(
            "GET /m1-received-transition HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n"
         );
         $transitionEntriesAfterCold = count(Cache::$entries);
         $transitionGenerationBefore = Cache::$generation;

         $TransitionEvents = new Emitter;
         Emitter::$Instance = $TransitionEvents;
         $TransitionEvents->listen(
            RequestEvents::Received,
            static function (Emission $Emission) use (
               &$transitionEvents,
               &$entriesOnEvent,
               &$preResetStashEntries,
               &$snapshotCacheable,
               $transitionCold
            ): void {
               /** @var Request $Request */
               [$Request] = $Emission->payload;
               if ($Request->URI !== '/m1-received-transition') {
                  return;
               }

               $transitionEvents++;
               $entriesOnEvent = count(Cache::$entries);
               $Snapshot = clone Server::$Response;
               $snapshotCacheable = $Snapshot->cacheable;
               $Snapshot->cache = 60;
               $Snapshot->stash($transitionCold);
               $preResetStashEntries = count(Cache::$entries);

               Server::$Response->Header->preset(
                  'X-M1-Received-Transition',
                  $Request->headers['x-m1-preset'] ?? 'missing'
               );
            }
         );
         $transitionMediated = $Encode(
            "GET /m1-received-transition HTTP/1.1\r\n"
            . "Host: localhost\r\nX-M1-Preset: current\r\n\r\n"
         );
         $transitionEntriesAfterReceived = count(Cache::$entries);
         $transitionGenerationAfter = Cache::$generation;

         Server::$Response->Header->preset('X-M1-Received-Transition', null);
         Emitter::$Instance = new Emitter;
         $transitionRecovery = $Encode(
            "GET /m1-received-transition HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n"
         );
         $transitionEntriesAfterRecovery = count(Cache::$entries);
         $transitionReplay = $Encode(
            "GET /m1-received-transition HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n"
         );
         $transitionEntriesAfterReplay = count(Cache::$entries);

         $probe = [
            'error' => '',
            'events' => $events,
            'warm_handlers' => $warmHandlers,
            'control_handlers' => $handlers,
            'entries_after_warm' => $entriesAfterWarm,
            'cold_header' => $Header($cold, 'X-M1-Received-Preset'),
            'warm_header' => $Header($warm, 'X-M1-Received-Preset'),
            'control_header' => $Header($control, 'X-M1-Received-Preset'),
            'cold_body' => $Body($cold),
            'warm_body' => $Body($warm),
            'control_body' => $Body($control),
            'transition' => [
               'events' => $transitionEvents,
               'handlers' => $transitionHandlers,
               'entries_on_event' => $entriesOnEvent,
               'pre_reset_stash_entries' => $preResetStashEntries,
               'snapshot_cacheable' => $snapshotCacheable,
               'entries_after_cold' => $transitionEntriesAfterCold,
               'entries_after_received' => $transitionEntriesAfterReceived,
               'entries_after_recovery' => $transitionEntriesAfterRecovery,
               'entries_after_replay' => $transitionEntriesAfterReplay,
               'generation_before' => $transitionGenerationBefore,
               'generation_after' => $transitionGenerationAfter,
               'cold_header' => $Header(
                  $transitionCold,
                  'X-M1-Received-Transition'
               ),
               'mediated_header' => $Header(
                  $transitionMediated,
                  'X-M1-Received-Transition'
               ),
               'recovery_header' => $Header(
                  $transitionRecovery,
                  'X-M1-Received-Transition'
               ),
               'replay_header' => $Header(
                  $transitionReplay,
                  'X-M1-Received-Transition'
               ),
               'cold_body' => $Body($transitionCold),
               'mediated_body' => $Body($transitionMediated),
               'recovery_body' => $Body($transitionRecovery),
               'replay_body' => $Body($transitionReplay),
            ],
         ];

         Server::$Response->Header->preset('X-M1-Received-Preset', null);
         Server::$Response->Header->preset('X-M1-Received-Transition', null);

         // @ Clear Encoder_'s private replay roots before restoring globals.
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
            return $Response(body: 'M1-RECEIVED-NEUTRAL');
         };
         $neutral = $Encode(
            "GET /m1-received-neutral HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         if ($Body($neutral) !== 'M1-RECEIVED-NEUTRAL') {
            throw new RuntimeException('Could not clear the production encoder replay state.');
         }
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         Server::$Response->Header->preset('X-M1-Received-Preset', null);
         Server::$Response->Header->preset('X-M1-Received-Transition', null);
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

      return "GET /m1-received-preset-harness HTTP/1.1\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield $Router->route('/m1-received-preset-harness', static function (
         Request $Request,
         Response $Response
      ): Response {
         return $Response(body: 'M1-RECEIVED-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['M1 Received production probe'];
         dump(json_encode($probe));

         return 'M1 Received fixture failed before validation: ' . $probe['error'];
      }

      if (! str_contains($response, 'M1-RECEIVED-HARNESS-OK')) {
         Vars::$labels = ['M1 Received harness response', 'M1 Received production probe'];
         dump(json_encode($response), json_encode($probe));

         return 'M1 Received fixture failed: the registered harness route did not execute.';
      }

      if (
         ($probe['events'] ?? null) !== 3
         || ($probe['cold_header'] ?? null) !== 'primer'
         || ($probe['control_header'] ?? null) !== 'control'
         || ($probe['cold_body'] ?? null) !== 'M1-RECEIVED-PRESET:handler=1'
         || ($probe['control_handlers'] ?? 0) !== ($probe['warm_handlers'] ?? -1) + 1
         || ($probe['warm_body'] ?? null)
            !== 'M1-RECEIVED-PRESET:handler=' . (string) ($probe['warm_handlers'] ?? 0)
         || ($probe['control_body'] ?? null)
            !== 'M1-RECEIVED-PRESET:handler=' . (string) ($probe['control_handlers'] ?? 0)
      ) {
         Vars::$labels = ['M1 Received controls'];
         dump(json_encode($probe));

         return 'M1 Received control failed: listener, handler, or no-cache path did not execute.';
      }

      if (
         ($probe['warm_handlers'] ?? null) === 1
         && ($probe['entries_after_warm'] ?? null) === 1
         && ($probe['warm_header'] ?? null) === 'primer'
      ) {
         Vars::$labels = ['M1 Received stale-preset evidence'];
         dump(json_encode($probe));

         return 'CONFIRMED M1: Received preset `current` was discarded and '
            . 'cached `primer` replayed.';
      }

      if (($probe['warm_header'] ?? null) !== 'current') {
         Vars::$labels = ['M1 Received ambiguous result'];
         dump(json_encode($probe));

         return 'M1 Received fixture produced neither current output nor the '
            . 'directly attributable stale replay.';
      }

      if (
         ($probe['warm_handlers'] ?? null) !== 2
         || ($probe['control_handlers'] ?? null) !== 3
         || ($probe['entries_after_warm'] ?? null) !== 0
         || ($probe['warm_body'] ?? null) !== 'M1-RECEIVED-PRESET:handler=2'
      ) {
         Vars::$labels = ['M1 Received fail-closed result'];
         dump(json_encode($probe));

         return 'M1 Received remained cache-admitted despite current listener output.';
      }

      $transition = $probe['transition'] ?? [];
      if (
         ($transition['events'] ?? null) !== 1
         || ($transition['handlers'] ?? null) !== 3
         || ($transition['entries_on_event'] ?? null) !== 0
         || ($transition['pre_reset_stash_entries'] ?? null) !== 0
         || ($transition['snapshot_cacheable'] ?? null) !== false
         || ($transition['entries_after_cold'] ?? null) !== 1
         || ($transition['entries_after_received'] ?? null) !== 0
         || ($transition['entries_after_recovery'] ?? null) !== 1
         || ($transition['entries_after_replay'] ?? null) !== 1
         || ($transition['generation_after'] ?? -1)
            !== ($transition['generation_before'] ?? -1) + 1
         || ($transition['cold_header'] ?? null) !== null
         || ($transition['mediated_header'] ?? null) !== 'current'
         || ($transition['recovery_header'] ?? null) !== null
         || ($transition['replay_header'] ?? null) !== null
         || ($transition['cold_body'] ?? null)
            !== 'M1-RECEIVED-TRANSITION:handler=1'
         || ($transition['mediated_body'] ?? null)
            !== 'M1-RECEIVED-TRANSITION:handler=2'
         || ($transition['recovery_body'] ?? null)
            !== 'M1-RECEIVED-TRANSITION:handler=3'
         || ($transition['replay_body'] ?? null)
            !== 'M1-RECEIVED-TRANSITION:handler=3'
      ) {
         Vars::$labels = ['M1 Received transition'];
         dump(json_encode($probe));

         return 'M1 Received transition failed to flush, guard, or recover route cache: '
            . json_encode($transition);
      }

      return true;
   },
);
