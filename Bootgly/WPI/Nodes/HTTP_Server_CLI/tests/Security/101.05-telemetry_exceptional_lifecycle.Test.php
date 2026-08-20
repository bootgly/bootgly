<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Observability;
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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Committing as SessionCommitting;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handler as SessionHandler;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource as ResponseResource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 exceptional-lifecycle regression — every admitted exchange must close its
 * core Telemetry accounting when production Encoder_ either preserves an
 * early failure or contains a reversible pre-wire Session failure.
 *
 * The positive control proves the real decoder, encoder, handler and 2xx
 * terminal path. Three isolated negative legs then check the registry:
 *
 * 1. a Received listener ordered after Telemetry throws before Response reset;
 * 2. a persistent scoped Response resource throws from clean() during reset;
 * 3. a Session atomic handler throws while save() commits the selected response;
 *    Encoder_ must replace it with a fresh 500 before any wire is emitted.
 *
 * The first two exceptions are cancellation-like for observability. The
 * reversible Session failure is a completed 5xx lifecycle: total and duration
 * close, the in-flight gauge returns to zero, and exactly one 5xx is recorded.
 */
$Probe = new class {
   public string $error = '';
   /** @var array<string,mixed> */
   public array $control = [];
   /** @var array<string,mixed> */
   public array $received = [];
   /** @var array<string,mixed> */
   public array $resource = [];
   /** @var array<string,mixed> */
   public array $session = [];
};

$Snapshot = static function (Observability $Observability): array {
   $metrics = $Observability->gather()->metrics;
   $responses = ['1xx' => null, '2xx' => null, '3xx' => null, '4xx' => null, '5xx' => null];
   $Integer = static fn (mixed $value): null|int => is_int($value) || is_float($value)
      ? (int) $value
      : null;

   foreach (($metrics['http_responses_total']['series'] ?? []) as $series) {
      $class = $series['labels']['class'] ?? null;
      if (is_string($class) && array_key_exists($class, $responses)) {
         $responses[$class] = $Integer($series['value'] ?? null);
      }
   }

   return [
      'requests_total' => $Integer($metrics['http_requests_total']['series'][0]['value'] ?? null),
      'in_flight' => $Integer($metrics['http_requests_in_flight']['series'][0]['value'] ?? null),
      'duration_count' => $Integer(
         $metrics['http_request_duration_seconds']['series'][0]['count'] ?? null
      ),
      'responses_1xx' => $responses['1xx'],
      'responses_2xx' => $responses['2xx'],
      'responses_3xx' => $responses['3xx'],
      'responses_4xx' => $responses['4xx'],
      'responses_5xx' => $responses['5xx'],
   ];
};

return new Test(
   description: 'Exceptional production paths must terminalize Telemetry with safe pre-wire Session containment',
   Separator: new Separator(line: true),

   request: static function () use ($Probe, $Snapshot): string {
      $socket = tmpfile();
      if (is_resource($socket) === false) {
         $Probe->error = 'Could not allocate the production-encoder stream surrogate.';

         return "GET /l4/exceptional/harness HTTP/1.1\r\nHost: localhost\r\n\r\n";
      }

      $OldRequest = Server::$Request;
      $OldResponse = Server::$Response;
      $OldRouter = Server::$Router;
      $OldDecoder = Server::$Decoder;
      $handlerInitialized = isset(SAPI::$Handler);
      $middlewaresInitialized = isset(SAPI::$Middlewares);
      $OldHandler = SAPI::$Handler ?? null;
      $OldMiddlewares = SAPI::$Middlewares ?? null;
      $OldEmitter = Emitter::$Instance;
      $OldSessionHandler = SessionHandler::$instance;
      $oldSessionGCProbability = Session::$gcProbability;
      $oldSessionAutoUpdateTimestamp = Session::$autoUpdateTimestamp;
      $oldEntries = Cache::$entries;
      $oldBytes = Cache::$bytes;
      $oldURIs = Cache::$URIs;
      $oldGeneration = Cache::$generation;

      // @ Direct Encoder_ probes must not change its worker-persistent replay
      //   roots or guarded-cache state for a later case in the same process.
      $EncoderReflection = new ReflectionClass(Encoder_::class);
      $encoderProperties = [
         'wire',
         'Admitted',
         'admittedBody',
         'admittedFields',
         'admittedPrepared',
         'admittedQueued',
         'admittedMasked',
         'admittedType',
         'admittedPreset',
         'admittedCode',
         'admittedHints',
         'admittedStream',
         'admittedChunked',
         'admittedEncoded',
         'adopted',
         'handled',
         'mutated',
         'observed',
         'mediated',
         'guarded',
      ];
      $EncoderState = [];
      foreach ($encoderProperties as $name) {
         $Property = $EncoderReflection->getProperty($name);
         $EncoderState[$name] = $Property->getValue();
      }

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
               throw new RuntimeException('L4 exceptional fixture request was rejected before Encoder_.');
            }

            $length = null;

            return Encoder_::encode($Package, $length);
         };

         $Run = static function (Closure $Work): array {
            $wire = null;
            $throwableClass = null;
            $throwableMessage = null;

            try {
               $wire = $Work();
            }
            catch (Throwable $Throwable) {
               $throwableClass = $Throwable::class;
               $throwableMessage = $Throwable->getMessage();
            }

            return [
               'wire' => $wire,
               'throwable_class' => $throwableClass,
               'throwable_message' => $throwableMessage,
            ];
         };

         $Configure = static function (Emitter $Emitter): void {
            Cache::flush();
            Emitter::$Instance = $Emitter;
            SAPI::$Middlewares = new Middlewares;
            Server::$Response = new Response;
            Server::$Router = new Router;
         };

         // ? Positive control: an ordinary production response must produce
         //   one complete 2xx lifecycle before any exceptional leg is trusted.
         $ControlEvents = new Emitter;
         $ControlObservability = new Observability(collectors: false);
         Emitter::$Instance = $ControlEvents;
         new Telemetry($ControlObservability)->boot();
         $Configure($ControlEvents);

         $controlHandlers = 0;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router,
         ) use (&$controlHandlers): Generator {
            yield $Router->route('/l4/exceptional/control', static function (
               Request $Request,
               Response $Response,
            ) use (&$controlHandlers): Response {
               $controlHandlers++;

               return $Response(code: 201, body: 'L4-EXCEPTIONAL-CONTROL-OK');
            }, GET);
         };
         $control = $Run(static fn (): string => $Encode(
            "GET /l4/exceptional/control HTTP/1.1\r\nHost: localhost\r\n\r\n"
         ));
         $Probe->control = [
            ...$control,
            'handlers' => $controlHandlers,
            'metrics' => $Snapshot($ControlObservability),
         ];

         // ! Leg 1: Telemetry installs first at priority 0. The application
         //   listener is explicitly later and throws before Response::reset().
         $ReceivedEvents = new Emitter;
         $ReceivedObservability = new Observability(collectors: false);
         Emitter::$Instance = $ReceivedEvents;
         new Telemetry($ReceivedObservability)->boot();
         $Configure($ReceivedEvents);

         $receivedListeners = 0;
         $receivedPayload = false;
         $ReceivedEvents->listen(
            RequestEvents::Received,
            static function (Emission $Emission) use (
               &$receivedPayload,
               &$receivedListeners,
            ): void {
               $Request = $Emission->payload[0] ?? null;
               if (
                  $Request instanceof Request === false
                  || $Request->URI !== '/l4/exceptional/received-throw'
               ) {
                  return;
               }

               $receivedListeners++;
               $receivedPayload = count($Emission->payload) === 1;

               throw new RuntimeException('L4-RECEIVED-LISTENER-THROW');
            },
            priority: -1,
         );

         $receivedHandlers = 0;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router,
         ) use (&$receivedHandlers): Generator {
            yield $Router->route('/l4/exceptional/received-throw', static function (
               Request $Request,
               Response $Response,
            ) use (&$receivedHandlers): Response {
               $receivedHandlers++;

               return $Response(body: 'L4-RECEIVED-HANDLER-MUST-NOT-RUN');
            }, GET);
         };
         $received = $Run(static fn (): string => $Encode(
            "GET /l4/exceptional/received-throw HTTP/1.1\r\nHost: localhost\r\n\r\n"
         ));
         $Probe->received = [
            ...$received,
            'listeners' => $receivedListeners,
            'public_payload' => $receivedPayload,
            'handlers' => $receivedHandlers,
            'metrics' => $Snapshot($ReceivedObservability),
         ];

         // ! Leg 2 primer: mount one persistent, request-scoped resource on the
         //   worker Response. Its next clean() runs inside Response::reset().
         $ResourceEvents = new Emitter;
         $Configure($ResourceEvents);
         $ExplosiveResource = new class extends ResponseResource {
            public bool $fail = false;
            public int $cleans = 0;

            public function __construct ()
            {
               parent::__construct(persistent: true, scoped: true);
            }

            public function clean (): void
            {
               $this->cleans++;
               if ($this->fail) {
                  throw new RuntimeException('L4-RESPONSE-RESOURCE-CLEAN-THROW');
               }
            }
         };

         $resourcePrimerHandlers = 0;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router,
         ) use ($ExplosiveResource, &$resourcePrimerHandlers): Generator {
            yield $Router->route('/l4/exceptional/resource-primer', static function (
               Request $Request,
               Response $Response,
            ) use ($ExplosiveResource, &$resourcePrimerHandlers): Response {
               $resourcePrimerHandlers++;
               $Response->mount($ExplosiveResource, 'L4Exceptional');

               return $Response(code: 201, body: 'L4-RESOURCE-PRIMER-OK');
            }, GET);
         };
         $resourcePrimer = $Run(static fn (): string => $Encode(
            "GET /l4/exceptional/resource-primer HTTP/1.1\r\nHost: localhost\r\n\r\n"
         ));

         // @ Keep the mounted Response, but isolate the measured target in a
         //   new registry and route context.
         $ResourceTargetEvents = new Emitter;
         $ResourceObservability = new Observability(collectors: false);
         Emitter::$Instance = $ResourceTargetEvents;
         new Telemetry($ResourceObservability)->boot();
         SAPI::$Middlewares = new Middlewares;
         Server::$Router = new Router;
         $ExplosiveResource->fail = true;

         $resourceTargetHandlers = 0;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router,
         ) use (&$resourceTargetHandlers): Generator {
            yield $Router->route('/l4/exceptional/resource-clean-throw', static function (
               Request $Request,
               Response $Response,
            ) use (&$resourceTargetHandlers): Response {
               $resourceTargetHandlers++;

               return $Response(body: 'L4-RESOURCE-HANDLER-MUST-NOT-RUN');
            }, GET);
         };
         $resource = $Run(static fn (): string => $Encode(
            "GET /l4/exceptional/resource-clean-throw HTTP/1.1\r\nHost: localhost\r\n\r\n"
         ));
         $ExplosiveResource->fail = false;
         $Probe->resource = [
            'primer' => $resourcePrimer,
            'primer_handlers' => $resourcePrimerHandlers,
            'target' => $resource,
            'target_handlers' => $resourceTargetHandlers,
            'cleans' => $ExplosiveResource->cleans,
            'metrics' => $Snapshot($ResourceObservability),
         ];

         // ! Leg 3: the selected response exists, but its Session commit throws
         //   in the deterministic pre-wire save boundary.
         $SessionEvents = new Emitter;
         $SessionObservability = new Observability(collectors: false);
         Emitter::$Instance = $SessionEvents;
         new Telemetry($SessionObservability)->boot();
         $Configure($SessionEvents);

         Session::$gcProbability = [0, 1];
         Session::$autoUpdateTimestamp = false;
         $SessionFailure = new class implements SessionCommitting {
            public int $fetches = 0;
            public int $commits = 0;

            public function read (string $sessionID): string|false
            {
               return false;
            }

            public function write (string $sessionID, string $sessionData): bool
            {
               return true;
            }

            public function touch (string $sessionID): bool
            {
               return true;
            }

            public function destroy (string $sessionID): bool
            {
               return true;
            }

            public function purge (int $maxLifetime): bool
            {
               return true;
            }

            public function fetch (
               string $sessionID,
               null|string &$revision = null,
            ): string|false {
               $this->fetches++;

               return false;
            }

            public function commit (
               string $sessionID,
               string $sessionData,
               null|string &$revision,
            ): bool {
               $this->commits++;

               throw new RuntimeException('L4-SESSION-COMMIT-THROW');
            }

            public function revoke (string $sessionID, string $revision): bool
            {
               return true;
            }
         };
         SessionHandler::$instance = $SessionFailure;

         $sessionHandlers = 0;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router,
         ) use (&$sessionHandlers): Generator {
            yield $Router->route('/l4/exceptional/session-commit-throw', static function (
               Request $Request,
               Response $Response,
            ) use (&$sessionHandlers): Response {
               $sessionHandlers++;
               $Session = $Request->Session;
               if ($Session === null) {
                  throw new RuntimeException('L4 session fixture did not construct a Session.');
               }
               $Session->set('l4_exceptional', 'commit');

               return $Response(code: 202, body: 'L4-SESSION-SELECTED-NO-WIRE');
            }, GET);
         };
         $session = $Run(static fn (): string => $Encode(
            "GET /l4/exceptional/session-commit-throw HTTP/1.1\r\nHost: localhost\r\n\r\n"
         ));
         $Probe->session = [
            ...$session,
            'handlers' => $sessionHandlers,
            'fetches' => $SessionFailure->fetches,
            'commits' => $SessionFailure->commits,
            'metrics' => $Snapshot($SessionObservability),
         ];

         // @ Session::save() clears its retry flag in finally. Restore the real
         //   backend before dropping that Request, then run one neutral encode
         //   to prove the contained failure left Encoder_ reusable.
         SessionHandler::$instance = $OldSessionHandler;
         $NeutralEvents = new Emitter;
         $Configure($NeutralEvents);
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router,
         ): Response {
            return $Response(body: 'L4-EXCEPTIONAL-NEUTRAL');
         };
         $neutral = $Run(static fn (): string => $Encode(
            "GET /l4/exceptional/neutral HTTP/1.1\r\nHost: localhost\r\n\r\n"
         ));
         if (
            $neutral['throwable_class'] !== null
            || is_string($neutral['wire']) === false
            || str_contains($neutral['wire'], 'L4-EXCEPTIONAL-NEUTRAL') === false
         ) {
            throw new RuntimeException(
               'L4 exceptional fixture could not restore the production encoder after its probes.'
            );
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         SessionHandler::$instance = $OldSessionHandler;

         // ! Drop the temporary Request while Session GC remains disabled and
         //   the original handler is installed; only then restore Session config.
         Server::$Request = $OldRequest;
         Session::$gcProbability = $oldSessionGCProbability;
         Session::$autoUpdateTimestamp = $oldSessionAutoUpdateTimestamp;

         foreach ($EncoderState as $name => $value) {
            $Property = $EncoderReflection->getProperty($name);
            $Property->setValue(null, $value);
         }

         Cache::$entries = $oldEntries;
         Cache::$bytes = $oldBytes;
         Cache::$URIs = $oldURIs;
         Cache::$generation = $oldGeneration;
         Emitter::$Instance = $OldEmitter;
         Server::$Response = $OldResponse;
         Server::$Router = $OldRouter;
         Server::$Decoder = $OldDecoder;

         if ($handlerInitialized && $OldHandler !== null) {
            SAPI::$Handler = $OldHandler;
         }
         if ($middlewaresInitialized && $OldMiddlewares !== null) {
            SAPI::$Middlewares = $OldMiddlewares;
         }

         @fclose($socket);
      }

      return "GET /l4/exceptional/harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ): Generator {
      yield $Router->route('/l4/exceptional/harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'L4-EXCEPTIONAL-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use ($Probe): bool|string {
      if ($Probe->error !== '') {
         Vars::$labels = ['L4 exceptional production fixture'];
         dump(json_encode($Probe));

         return 'L4 exceptional fixture failed before lifecycle validation: ' . $Probe->error;
      }

      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || str_contains($response, 'L4-EXCEPTIONAL-HARNESS-OK') === false
      ) {
         Vars::$labels = ['L4 exceptional native harness control'];
         dump(json_encode(['wire' => $response, 'probe' => $Probe]));

         return 'L4 exceptional fixture failed: the registered native harness route did not execute.';
      }

      $expectedControl = [
         'requests_total' => 1,
         'in_flight' => 0,
         'duration_count' => 1,
         'responses_1xx' => 0,
         'responses_2xx' => 1,
         'responses_3xx' => 0,
         'responses_4xx' => 0,
         'responses_5xx' => 0,
      ];
      if (
         ($Probe->control['throwable_class'] ?? null) !== null
         || ($Probe->control['handlers'] ?? null) !== 1
         || is_string($Probe->control['wire'] ?? null) === false
         || str_contains($Probe->control['wire'], 'HTTP/1.1 201 Created') === false
         || str_contains($Probe->control['wire'], 'L4-EXCEPTIONAL-CONTROL-OK') === false
         || ($Probe->control['metrics'] ?? null) !== $expectedControl
      ) {
         Vars::$labels = ['L4 exceptional production positive control'];
         dump(json_encode($Probe->control));

         return 'L4 exceptional control failed: the real decoder/encoder/handler/Telemetry '
            . 'path did not complete one ordinary 2xx lifecycle. Evidence: '
            . json_encode($Probe->control);
      }

      $expectedExceptional = [
         'requests_total' => 1,
         'in_flight' => 0,
         'duration_count' => 1,
         'responses_1xx' => 0,
         'responses_2xx' => 0,
         'responses_3xx' => 0,
         'responses_4xx' => 0,
         'responses_5xx' => 0,
      ];
      $expectedContained = [
         'requests_total' => 1,
         'in_flight' => 0,
         'duration_count' => 1,
         'responses_1xx' => 0,
         'responses_2xx' => 0,
         'responses_3xx' => 0,
         'responses_4xx' => 0,
         'responses_5xx' => 1,
      ];

      $received = $Probe->received;
      if (
         ($received['listeners'] ?? null) !== 1
         || ($received['public_payload'] ?? null) !== true
         || ($received['handlers'] ?? null) !== 0
         || ($received['wire'] ?? null) !== null
         || ($received['throwable_class'] ?? null) !== RuntimeException::class
         || ($received['throwable_message'] ?? null) !== 'L4-RECEIVED-LISTENER-THROW'
      ) {
         Vars::$labels = ['L4 posterior Received-listener throw controls'];
         dump(json_encode($received));

         return 'L4 exceptional fixture did not preserve the posterior Received listener throw/no-wire path.';
      }

      $resource = $Probe->resource;
      $resourcePrimer = $resource['primer'] ?? [];
      $resourceTarget = $resource['target'] ?? [];
      if (
         is_array($resourcePrimer) === false
         || ($resourcePrimer['throwable_class'] ?? null) !== null
         || is_string($resourcePrimer['wire'] ?? null) === false
         || str_contains($resourcePrimer['wire'], 'HTTP/1.1 201 Created') === false
         || str_contains($resourcePrimer['wire'], 'L4-RESOURCE-PRIMER-OK') === false
         || ($resource['primer_handlers'] ?? null) !== 1
         || ($resource['target_handlers'] ?? null) !== 0
         || ($resource['cleans'] ?? null) !== 1
         || is_array($resourceTarget) === false
         || ($resourceTarget['wire'] ?? null) !== null
         || ($resourceTarget['throwable_class'] ?? null) !== RuntimeException::class
         || ($resourceTarget['throwable_message'] ?? null) !== 'L4-RESPONSE-RESOURCE-CLEAN-THROW'
      ) {
         Vars::$labels = ['L4 Response resource reset/clean throw controls'];
         dump(json_encode($resource));

         return 'L4 exceptional fixture did not preserve the scoped Resource clean throw/no-wire path.';
      }

      $session = $Probe->session;
      $sessionWire = $session['wire'] ?? null;
      if (
         ($session['handlers'] ?? null) !== 1
         || ($session['fetches'] ?? null) !== 1
         || ($session['commits'] ?? null) !== 1
         || is_string($sessionWire) === false
         || str_contains($sessionWire, 'HTTP/1.1 500 Internal Server Error') === false
         || str_contains($sessionWire, 'L4-SESSION-SELECTED-NO-WIRE')
         || stripos($sessionWire, "\r\nSet-Cookie:") !== false
         || ($session['throwable_class'] ?? null) !== null
         || ($session['throwable_message'] ?? null) !== null
      ) {
         Vars::$labels = ['L4 Session save/commit containment controls'];
         dump(json_encode($session));

         return 'L4 exceptional fixture did not contain Session commit failure as a clean 500.';
      }

      $accountingFailures = [];
      foreach ([
         'posterior Received listener' => $received['metrics'] ?? null,
         'Response resource clean' => $resource['metrics'] ?? null,
      ] as $path => $metrics) {
         if ($metrics !== $expectedExceptional) {
            $accountingFailures[$path] = $metrics;
         }
      }
      if (($session['metrics'] ?? null) !== $expectedContained) {
         $accountingFailures['Session commit'] = $session['metrics'] ?? null;
      }

      if ($accountingFailures !== []) {
         Vars::$labels = ['L4 exceptional Telemetry terminal accounting'];
         dump(json_encode([
            'expected exceptional' => $expectedExceptional,
            'expected contained' => $expectedContained,
            'failures' => $accountingFailures,
            'probe' => $Probe,
         ]));

         return 'L4 regression: exceptional Encoder_ paths leaked or duplicated core '
            . 'Telemetry accounting. Every admitted throw must close total/duration, '
            . 'balance in-flight, and count only the contained Session 500. Evidence: '
            . json_encode($accountingFailures);
      }

      return true;
   },
);
