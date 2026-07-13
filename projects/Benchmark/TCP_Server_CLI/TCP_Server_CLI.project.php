<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Benchmark\TCP_Server_CLI;


use function exec;
use function getenv;
use function max;
use function str_starts_with;
use function strlen;

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Events as TCP_Server_Events;


return new Project(
   name: 'Benchmark TCP Server CLI',
   description: 'Raw TCP server benchmark for Bootgly (HTTP or echo)',
   version: '1.0.0',
   author: 'Bootgly',
   exportable: false,

   boot: function (array $arguments = [], array $options = []): void {
      // @ A/B: TCP-layer packet stats are OFF by default; `BOOTGLY_STATS=1`
      //   re-enables collection from boot for A/B benchmarking. Applied AFTER
      //   construct (the Connections ctor resets the static) and BEFORE fork.
      $statsOn = getenv('BOOTGLY_STATS') === '1';

      // @ Pre-build fixed HTTP response for http_raw scenario
      $httpBody = "Hello World\n";
      $httpResponse = "HTTP/1.1 200 OK\r\n"
         . "Content-Type: text/plain\r\n"
         . "Content-Length: " . strlen($httpBody) . "\r\n"
         . "Connection: keep-alive\r\n"
         . "\r\n"
         . $httpBody;

      $Server = new TCP_Server_CLI(Modes::Daemon);

      // ? After construct (Connections ctor resets the static), before fork
      if ($statsOn) {
         \Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections::$stats = true;
      }

      $Server
         ->configure(
            host: '0.0.0.0',
            port: getenv('PORT') ? (int) getenv('PORT') : 8083,
            workers: getenv('BOOTGLY_WORKERS') ? (int) getenv('BOOTGLY_WORKERS') : max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2)),
         )
         ->on(
            TCP_Server_Events::DataReceive,
            static function (string $input) use ($httpResponse): string {
               // @ Per-worker profiler bootstrap (env-gated; idempotent via internal PID guard)
               if (getenv('BOOTGLY_PROFILE') === '1') {
                  require_once __DIR__ . '/../HTTP_Server_CLI/Profiler.php';
                  \Benchmark\HTTP_Server_CLI\Profiler::start();
               }

               // @ Dual-mode: HTTP or echo
               if (str_starts_with($input, 'GET ')) {
                  return $httpResponse;
               }

               return $input;
            }
         )
         ->start();
   }
);
