<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\HTTP_Server_CLI;


use function defined;
use function exec;
use function getenv;
use function max;
use function strtolower;

use const Bootgly\CLI;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events as HTTP_Server_Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database as DatabaseResource;


return new Project(
   name: 'Benchmark HTTP Server CLI',
   description: 'HTTP server benchmark for Bootgly',
   version: '1.0.0',
   author: 'Bootgly',

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
         ->on(HTTP_Server_Events::RequestReceived, require __DIR__ . "/router/{$routerFile}")
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
