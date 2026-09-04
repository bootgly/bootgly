<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Benchmark\HTTP_Server_CLI;


use const BOOTGLY_PROJECT;
use function exec;
use function getenv;
use function is_string;
use function max;
use function strtolower;

use const Bootgly\CLI;
use Benchmark\HTTP_Server_CLI\Encoders\WorkerEvidence;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Process\Events as ProcessEvents;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Configs as ServerConfigs;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events as HTTP_Server_Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Configs as RequestConfigs;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Configs as ResponseConfigs;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database as DatabaseResource;


require_once __DIR__ . '/Encoders/WorkerEvidence.php';


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
         // # The `sse` set is driven by its own runner but served by the same
         //   Bootgly router — `/sse/stream` lives beside the reactor probes.
         'sse'         => 'bootgly',
         default       => 'simple',
      };
      $routerFile = match ($router) {
         'techempower' => 'techempower-benchmark.SAPI.php',
         'bootgly'     => 'bootgly-benchmark.SAPI.php',
         default       => 'simple-benchmark.SAPI.php',
      };

      $Handler = require __DIR__ . "/router/{$routerFile}";
      $warmupToken = getenv('BENCHMARK_WARMUP_TOKEN');

      $Resources = null;

      // # The Database response resource is needed by both routers:
      //   - techempower:  /db, /query, /fortunes, /updates
      //   - bootgly:      /database/resource/*, /database/runner/*
      if ($router === 'techempower' || $router === 'bootgly') {
         $Resources = [
            'Database' => DatabaseResource::provide(__DIR__ . '/configs/'),
         ];
      }

      $Server = new HTTP_Server_CLI(Modes::Daemon);

      if (is_string($warmupToken) && $warmupToken !== '') {
         $Handler = (new WorkerEvidence)->wrap($warmupToken, $Handler);
         // ! Emitted inside every initial and recovered serving worker. Opening
         //   the lease here makes an idle generation replacement observable
         //   before it receives any request; the seal still restores the exact
         //   production Handler and Encoder before measurement.
         Emitter::$Instance->listen(
            ProcessEvents::Boot,
            static function (): void {
               WorkerEvidence::register();
            },
         );
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
            new ServerConfigs(
               host: '0.0.0.0',
               port: getenv('PORT') ? (int) getenv('PORT') : 8082,
               workers: getenv('BOOTGLY_WORKERS') ? (int) getenv('BOOTGLY_WORKERS') : max(1, (int) ((int)(exec('nproc 2>/dev/null') ?: 1) / 2)),
            ),
            // new RequestConfigs(
            //    maxFileSize: 500 * 1024 * 1024, // 500 MB (default)
            //    maxBodySize: 10 * 1024 * 1024,  // 10 MB (default)
            // ),
            new ResponseConfigs(
               Resources: $Resources
            )
         )
         // # Test (Benchmarking)
         ->on(HTTP_Server_Events::RequestReceived, $Handler);

      $Server
         // # Launch banner — fired on the process that owns the terminal. On Daemon
         //   mode the master is already detached, so the launcher renders it here;
         //   `ServerStarted` would write to a closed stream and print nothing.
         ->on(HTTP_Server_Events::ServerAdvertised, function ($HTTP_Server_CLI) {
               $Output = CLI->Terminal->Output;

               $Output->render('@.;@#green:✓ Bootgly HTTP Server started@;@.;');
               $HTTP_Server_CLI->advertise();
               $Output->render('  @#green:● Ready for connections@;@..;');

               $project = BOOTGLY_PROJECT->folder;
               $Output->render("@#Green:Tip:@; Use @#Black:`bootgly project stop {$project}`@; to stop the server.@..;");
            })
         ->on(HTTP_Server_Events::ServerStopped, function ($HTTP_Server_CLI) {
               $Output = CLI->Terminal->Output;

               $Output->render('@.;@#yellow:■ Bootgly HTTP Server stopped@;@.;');
            })
         ->start();
   }
);
