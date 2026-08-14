<?php

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\Debugging\Data\Vars;
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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 response-selection regression — production Encoder_ must transfer the
 * admitted exchange to whichever fresh Response becomes terminal.
 *
 * Each leg uses a new Emitter and Observability registry, decodes exactly one
 * request and snapshots metrics immediately after that one encode():
 *
 * 1. the application handler returns a fresh Response(201), distinct from the
 *    reset worker singleton;
 * 2. the handler throws and Catcher selects its fresh Response(500).
 *
 * Wire status, handler count and Catcher reporter delivery are path controls.
 * They prevent a decode, dispatch or error-harness failure from resembling a
 * lifecycle defect.
 */
$Probe = new class {
   public string $error = '';
   /** @var array<string,mixed> */
   public array $replacement = [];
   /** @var array<string,mixed> */
   public array $catcher = [];
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
   description: 'Fresh handler and Catcher responses must terminalize Telemetry immediately',
   Separator: new Separator(line: true),

   request: static function () use ($Probe, $Snapshot): string {
      $socket = tmpfile();
      if (is_resource($socket) === false) {
         $Probe->error = 'Could not allocate the production-encoder stream surrogate.';

         return "GET /l4/response-selection/harness HTTP/1.1\r\nHost: localhost\r\n\r\n";
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
      $OldReporters = Throwables::$reporters;
      $OldCatcherEnvironment = Catcher::$Environment;
      $oldEntries = Cache::$entries;
      $oldBytes = Cache::$bytes;
      $oldURIs = Cache::$URIs;
      $oldGeneration = Cache::$generation;

      // @ A direct Encoder_ probe must restore its worker-persistent replay
      //   roots so this case cannot influence the registered harness request.
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
               throw new RuntimeException(
                  'L4 response-selection fixture request was rejected before Encoder_.'
               );
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

         $Configure = static function (
            Emitter $Emitter,
            Observability $Observability,
         ): void {
            Cache::flush();
            Emitter::$Instance = $Emitter;
            new Telemetry($Observability)->boot();
            SAPI::$Middlewares = new Middlewares;
            Server::$Response = new Response;
            Server::$Router = new Router;
         };

         // ! Leg 1: the direct handler returns a new response rather than
         //   mutating the admitted singleton.
         $ReplacementEvents = new Emitter;
         $ReplacementObservability = new Observability(collectors: false);
         $Configure($ReplacementEvents, $ReplacementObservability);

         $replacementHandlers = 0;
         $replacementDistinct = false;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router,
         ) use (&$replacementDistinct, &$replacementHandlers): Response {
            $replacementHandlers++;
            $Selected = new Response(code: 201, body: 'L4-FRESH-RESPONSE-201');
            $replacementDistinct = $Selected !== $Response;

            return $Selected;
         };

         $replacement = $Run(static fn (): string => $Encode(
            "GET /l4/response-selection/fresh HTTP/1.1\r\nHost: localhost\r\n\r\n"
         ));
         $Probe->replacement = [
            ...$replacement,
            'handlers' => $replacementHandlers,
            'distinct' => $replacementDistinct,
            // ! Snapshot before any second request can force cleanup of an
            //   exchange accidentally left on the old singleton.
            'metrics' => $Snapshot($ReplacementObservability),
         ];

         // ! Leg 2: Catcher reports the exact handler throwable and selects
         //   a fresh 500 response. This registry has seen no primer request.
         $CatcherEvents = new Emitter;
         $CatcherObservability = new Observability(collectors: false);
         $Configure($CatcherEvents, $CatcherObservability);

         $marker = 'L4-CATCHER-FRESH-RESPONSE';
         $catcherHandlers = 0;
         $catcherReports = 0;
         $reportURI = null;
         Throwables::$reporters = [
            static function (Throwable $Throwable, array $context) use (
               &$catcherReports,
               &$reportURI,
               $marker,
            ): void {
               if ($Throwable->getMessage() !== $marker) {
                  return;
               }

               $catcherReports++;
               $reportURI = $context['URI'] ?? null;
            },
         ];
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router,
         ) use (&$catcherHandlers, $marker): Response {
            $catcherHandlers++;

            throw new RuntimeException($marker);
         };

         $catcher = $Run(static fn (): string => $Encode(
            "GET /l4/response-selection/catcher HTTP/1.1\r\nHost: localhost\r\n\r\n"
         ));
         $Probe->catcher = [
            ...$catcher,
            'handlers' => $catcherHandlers,
            'reports' => $catcherReports,
            'report_URI' => $reportURI,
            // ! This is still the first and only request in this registry.
            'metrics' => $Snapshot($CatcherObservability),
         ];
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($EncoderState as $name => $Value) {
            $Property = $EncoderReflection->getProperty($name);
            $Property->setValue(null, $Value);
         }

         Cache::$entries = $oldEntries;
         Cache::$bytes = $oldBytes;
         Cache::$URIs = $oldURIs;
         Cache::$generation = $oldGeneration;
         Throwables::$reporters = $OldReporters;
         Catcher::$Environment = $OldCatcherEnvironment;
         Emitter::$Instance = $OldEmitter;
         Server::$Request = $OldRequest;
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
         gc_collect_cycles();
      }

      return "GET /l4/response-selection/harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ): Generator {
      yield $Router->route('/l4/response-selection/harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'L4-RESPONSE-SELECTION-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use ($Probe): bool|string {
      if ($Probe->error !== '') {
         Vars::$labels = ['L4 response-selection production fixture'];
         dump(json_encode($Probe));

         return 'L4 response-selection fixture failed before validation: ' . $Probe->error;
      }

      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || str_contains($response, 'L4-RESPONSE-SELECTION-HARNESS-OK') === false
      ) {
         Vars::$labels = ['L4 response-selection native harness control'];
         dump(json_encode(['wire' => $response, 'probe' => $Probe]));

         return 'L4 response-selection fixture failed: the native harness route did not execute.';
      }

      $replacement = $Probe->replacement;
      if (
         ($replacement['throwable_class'] ?? null) !== null
         || ($replacement['handlers'] ?? null) !== 1
         || ($replacement['distinct'] ?? null) !== true
         || is_string($replacement['wire'] ?? null) === false
         || str_contains($replacement['wire'], 'HTTP/1.1 201 Created') === false
         || str_contains($replacement['wire'], 'L4-FRESH-RESPONSE-201') === false
      ) {
         Vars::$labels = ['L4 fresh handler Response path controls'];
         dump(json_encode($replacement));

         return 'L4 fixture failed: Encoder_ did not select the distinct handler Response(201).';
      }

      $catcher = $Probe->catcher;
      if (
         ($catcher['throwable_class'] ?? null) !== null
         || ($catcher['handlers'] ?? null) !== 1
         || ($catcher['reports'] ?? null) !== 1
         || ($catcher['report_URI'] ?? null) !== '/l4/response-selection/catcher'
         || is_string($catcher['wire'] ?? null) === false
         || str_contains($catcher['wire'], 'HTTP/1.1 500 Internal Server Error') === false
      ) {
         Vars::$labels = ['L4 Catcher Response(500) path controls'];
         dump(json_encode($catcher));

         return 'L4 fixture failed: the handler throwable did not traverse Catcher to a 500 response.';
      }

      $expected201 = [
         'requests_total' => 1,
         'in_flight' => 0,
         'duration_count' => 1,
         'responses_1xx' => 0,
         'responses_2xx' => 1,
         'responses_3xx' => 0,
         'responses_4xx' => 0,
         'responses_5xx' => 0,
      ];
      $expected500 = [
         'requests_total' => 1,
         'in_flight' => 0,
         'duration_count' => 1,
         'responses_1xx' => 0,
         'responses_2xx' => 0,
         'responses_3xx' => 0,
         'responses_4xx' => 0,
         'responses_5xx' => 1,
      ];
      $failures = [];
      if (($replacement['metrics'] ?? null) !== $expected201) {
         $failures['fresh_201'] = $replacement['metrics'] ?? null;
      }
      if (($catcher['metrics'] ?? null) !== $expected500) {
         $failures['catcher_500'] = $catcher['metrics'] ?? null;
      }

      if ($failures !== []) {
         Vars::$labels = ['L4 immediate response-selection Telemetry'];
         dump(json_encode([
            'expected_201' => $expected201,
            'expected_500' => $expected500,
            'failures' => $failures,
            'probe' => $Probe,
         ]));

         return 'L4 regression: a fresh selected Response did not immediately close '
            . 'its one admitted Telemetry exchange with the exact status class. Evidence: '
            . json_encode($failures);
      }

      return true;
   },
);
