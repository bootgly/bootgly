<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Benchmark\HTTP_Server_CLI;


use Closure;
use Generator;
use function bin2hex;
use function defined;
use function exec;
use function getenv;
use function getmypid;
use function hash_equals;
use function is_string;
use function max;
use function random_bytes;
use function strtolower;

use const Bootgly\CLI;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events as HTTP_Server_Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database as DatabaseResource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


return new Project(
   name: 'Benchmark HTTP Server CLI',
   description: 'HTTP server benchmark for Bootgly',
   version: '1.0.0',
   author: 'Bootgly',
   exportable: false,

   boot: function (array $arguments = [], array $options = []): void {
      // @ A/B: TCP-layer packet stats are OFF by default (lazily enabled by
      //   the `stats` command). `BOOTGLY_STATS=1` re-enables collection from
      //   boot for A/B benchmarking.
      //   ! Must be applied AFTER the server is constructed (the Connections
      //   constructor resets the static) and BEFORE `start()` forks the
      //   workers (statics propagate by fork inheritance).
      $statsOn = getenv('BOOTGLY_STATS') === '1';

      // # Router — derived from the active benchmark load set (BENCHMARK_LOAD_SET,
      //   set by `--loads=<set>:<indexes>`). A standalone run (no load set) falls
      //   back to the simple router.
      $router = match (strtolower(getenv('BENCHMARK_LOAD_SET') ?: '')) {
         'techempower' => 'techempower',
         'benchmark'   => 'bootgly',
         default       => 'simple',
      };
      $routerFile = match ($router) {
         'techempower' => 'techempower-benchmark.SAPI.php',
         'bootgly'     => 'bootgly-benchmark.SAPI.php',
         default       => 'simple-benchmark.SAPI.php',
      };

      $Handler = require __DIR__ . "/router/{$routerFile}";
      $warmupToken = getenv('BENCHMARK_WARMUP_TOKEN');

      $responseResources = null;

      // # The Database response resource is needed by both routers:
      //   - techempower:  /db, /query, /fortunes, /updates
      //   - bootgly:      /database/resource/*, /database/runner/*
      if ($router === 'techempower' || $router === 'bootgly') {
         $responseResources = [
            'Database' => DatabaseResource::provide(__DIR__ . '/configs/'),
         ];
      }

      $Server = new HTTP_Server_CLI(Modes::Daemon);

      // @ Benchmark-only worker evidence wraps the normal encoder, not the
      //   SAPI middleware stack: a warmed Router resolves directly from its
      //   route cache and bypasses global middleware. Application::boot()
      //   selects the production encoder during start(), so the request
      //   Handler installs this wrapper lazily in each forked worker, after
      //   that selection, and marks that first response directly.
      //
      //   On later requests, installing the evidence header before the normal
      //   encoder runs lets Response::reset() retain it as a preset; defer()
      //   then copies it into the private Response clone encoded after async
      //   database work completes. The harness also sends Authorization to
      //   bypass the separate full-wire response cache. A paired seal restores
      //   the exact original Encoder and SAPI Handler in that worker.
      if (is_string($warmupToken) && $warmupToken !== '') {
         $OriginalHandler = $Handler;
         $EvidenceEncoder = new class extends Encoders {
            private static bool $booted = false;
            private static string $token;
            private static ?string $workerIdentity = null;
            private static Encoder $Encoder;
            private static Closure $Handler;

            public function boot (string $token, Encoder $Encoder, Closure $Handler): void
            {
               if (self::$booted) {
                  return;
               }

               self::$booted = true;
               self::$token = $token;
               self::$Encoder = $Encoder;
               self::$Handler = $Handler;
            }

            public function mark (Request $Request, Response $Response): bool
            {
               $providedToken = $Request->Header->get('X-Bootgly-Benchmark-Warmup');

               if (!is_string($providedToken) || !hash_equals(self::$token, $providedToken)) {
                  return false;
               }

               self::$workerIdentity ??= getmypid() . '-' . bin2hex(random_bytes(8));
               $Response->Header->set(
                  'X-Bootgly-Benchmark-Worker',
                  self::$token . ':' . self::$workerIdentity,
               );

               $providedSeal = $Request->Header->get('X-Bootgly-Benchmark-Seal');

               return is_string($providedSeal)
                  && hash_equals(self::$token, $providedSeal);
            }

            public static function restore (): void
            {
               HTTP_Server_CLI::$Encoder = self::$Encoder;
               SAPI::$Handler = self::$Handler;
            }

            public static function encode (Packages $Packages, null|int &$length): string
            {
               $Request = HTTP_Server_CLI::$Request;
               $providedToken = $Request->Header->get('X-Bootgly-Benchmark-Warmup');
               $Encoder = self::$Encoder;

               if (!is_string($providedToken) || !hash_equals(self::$token, $providedToken)) {
                  return $Encoder::encode($Packages, $length);
               }

               self::$workerIdentity ??= getmypid() . '-' . bin2hex(random_bytes(8));

               $Response = HTTP_Server_CLI::$Response;
               $Response->Header->preset(
                  'X-Bootgly-Benchmark-Worker',
                  self::$token . ':' . self::$workerIdentity,
               );

               $providedSeal = $Request->Header->get('X-Bootgly-Benchmark-Seal');
               $sealed = is_string($providedSeal)
                  && hash_equals(self::$token, $providedSeal);

               try {
                  return $Encoder::encode($Packages, $length);
               }
               finally {
                  // ! For deferred responses the normal encoder has already
                  //   cloned Response, so the clone keeps the acknowledgement
                  //   while the worker singleton is clean for the next request.
                  $Response->Header->preset('X-Bootgly-Benchmark-Worker');

                  if ($sealed) {
                     self::restore();
                  }
               }
            }
         };

         $Handler = static function
         (Request $Request, Response $Response, Router $Router)
         use ($EvidenceEncoder, $OriginalHandler, $warmupToken): Generator
         {
            $OriginalEncoder = HTTP_Server_CLI::$Encoder;

            if ($OriginalEncoder instanceof Encoder) {
               $EvidenceEncoder->boot($warmupToken, $OriginalEncoder, $OriginalHandler);
               HTTP_Server_CLI::$Encoder = $EvidenceEncoder;
            }

            $sealed = $EvidenceEncoder->mark($Request, $Response);

            try {
               yield from $OriginalHandler($Request, $Response, $Router);
            }
            finally {
               // ? A seal can legally be the worker's first routed request;
               //   the current normal encoder still owns that response, so
               //   restoring here does not remove its already-set header.
               if ($sealed) {
                  $EvidenceEncoder::restore();
               }
            }
         };
      }

      // ? After construct (Connections ctor resets the static), before fork
      if ($statsOn) {
         \Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections::$stats = true;
      }

      // ? A/B: request telemetry (H1) — BOOTGLY_OBSERVE=1 registers the per-request metric
      //   listeners (Telemetry) to measure observability ON cost vs the OFF baseline. Registered
      //   pre-fork so workers inherit the listeners.
      if (getenv('BOOTGLY_OBSERVE') === '1') {
         \Bootgly\ACI\Observability::$Instance = new \Bootgly\ACI\Observability(collectors: false);
         new HTTP_Server_CLI\Telemetry(\Bootgly\ACI\Observability::$Instance)->boot();
      }

      $Server
         ->configure(
            host: '0.0.0.0',
            port: getenv('PORT') ? (int) getenv('PORT') : 8082,
            workers: getenv('BOOTGLY_WORKERS') ? (int) getenv('BOOTGLY_WORKERS') : max(1, (int) ((int)(exec('nproc 2>/dev/null') ?: 1) / 2)),
            responseResources: $responseResources,
            // requestMaxFileSize: 500 * 1024 * 1024, // 500 MB (default)
            // requestMaxBodySize: 10 * 1024 * 1024,  // 10 MB (default)
         )
         // # Test (Benchmarking)
         ->on(HTTP_Server_Events::RequestReceived, $Handler);

      $Server
         ->on(HTTP_Server_Events::ServerStarted, function ($HTTP_Server_CLI) {
               $Output = CLI->Terminal->Output;

               $protocol = $HTTP_Server_CLI->socket ?? 'http://';
               $host = $HTTP_Server_CLI->host ?? '0.0.0.0';
               $port = $HTTP_Server_CLI->port ?? 0;

               $Output->render('@.;@#green:✓ Bootgly HTTP Server started@;@.;');
               $Output->render('  Listening on @#cyan:' . $protocol . $host . ':' . $port . '@;@.;');
               $Output->render('  @#green:● Ready for connections@;@..;');

               $projectName = defined('BOOTGLY_PROJECT') ? BOOTGLY_PROJECT->folder : 'Benchmark/HTTP_Server_CLI';
               $Output->render('@#Green:Tip:@; Use @#Black:`bootgly project stop` ' . $projectName . '@; to stop the server.@..;');
            })
         ->on(HTTP_Server_Events::ServerStopped, function ($HTTP_Server_CLI) {
               $Output = CLI->Terminal->Output;

               $Output->render('@.;@#yellow:■ Bootgly HTTP Server stopped@;@.;');
            })
         ->start();
   }
);
