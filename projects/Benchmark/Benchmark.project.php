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
use function is_numeric;
use function max;
use function str_starts_with;
use function strlen;
use function strtolower;
use RuntimeException;

use const Bootgly\CLI;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Events as TCP_Server_Events;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events as UDP_Server_Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events as HTTP_Server_Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database as DatabaseResource;


return new Project(
   name: 'Benchmark Bootgly',
   description: 'Benchmarking project for Bootgly\'s',
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

      if ($options['HTTP_Server_CLI'] ?? false) {
         $router = strtolower(getenv('BOOTGLY_HTTP_SERVER_CLI_ROUTER') ?: 'simple');
         $routerFile = match ($router) {
            'techempower' => 'techempower-benchmark.SAPI.php',
            'bootgly'     => 'bootgly-benchmark.SAPI.php',
            default       => 'simple-benchmark.SAPI.php',
         };

         $responseResources = null;

         // # The Database response resource is needed by both routers:
         //   - techempower:  /db, /query, /fortunes, /updates
         //   - bootgly:      /database/resource/*, /database/runner/*
         if ($router === 'techempower') {
            $Env = static function (string $name, string $default): string {
               $value = getenv($name);

               return $value === false || $value === '' ? $default : $value;
            };

            $Bool = static function (string $name, bool $default) use ($Env): bool {
               $value = strtolower($Env($name, $default ? 'true' : 'false'));

               return $value === '1' || $value === 'true' || $value === 'yes' || $value === 'on';
            };

            $responseResources = [
               'Database' => static function (object $Context) use ($Env, $Bool): DatabaseResource {
                  static $Database = null;

                  if ($Context instanceof Response === false) {
                     throw new RuntimeException('Database response resource expects a Response context.');
                  }

                  if ($Database instanceof SQL === false) {
                     $port = $Env('DB_PORT', (string) Config::DEFAULT_PORT);
                     $timeout = $Env('DB_TIMEOUT', (string) Config::DEFAULT_TIMEOUT);
                     $poolMin = $Env('DB_POOL_MIN', (string) Config::DEFAULT_POOL_MIN);
                     $poolMax = $Env('DB_POOL_MAX', (string) Config::DEFAULT_POOL_MAX);
                     $statements = $Env('DB_STATEMENTS', (string) Config::DEFAULT_STATEMENTS);

                     $Database = new SQL([
                        'driver' => $Env('DB_CONNECTION', Config::DEFAULT_DRIVER),
                        'host' => $Env('DB_HOST', Config::DEFAULT_HOST),
                        'port' => is_numeric($port) ? (int) $port : Config::DEFAULT_PORT,
                        'database' => $Env('DB_NAME', Config::DEFAULT_DATABASE),
                        'username' => $Env('DB_USER', Config::DEFAULT_USERNAME),
                        'password' => $Env('DB_PASS', Config::DEFAULT_PASSWORD),
                        'timeout' => is_numeric($timeout) ? (float) $timeout : Config::DEFAULT_TIMEOUT,
                        'statements' => is_numeric($statements) ? (int) $statements : Config::DEFAULT_STATEMENTS,
                        'pool' => [
                           'min' => is_numeric($poolMin) ? (int) $poolMin : Config::DEFAULT_POOL_MIN,
                           'max' => is_numeric($poolMax) ? (int) $poolMax : Config::DEFAULT_POOL_MAX,
                        ],
                        'secure' => [
                           'mode' => $Env('DB_SSLMODE', Config::SECURE_DISABLE),
                           'verify' => $Bool('DB_SSLVERIFY', false),
                           'peer' => $Env('DB_SSLPEER', ''),
                           'cafile' => $Env('DB_SSLCAFILE', ''),
                        ],
                     ]);
                  }

                  return new DatabaseResource($Database);
               },
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
            ->on(HTTP_Server_Events::RequestReceived, require __DIR__ . "/HTTP_Server_CLI/router/{$routerFile}")
            ->on(HTTP_Server_Events::ServerStarted, function ($HTTP_Server_CLI) {
                  $Output = CLI->Terminal->Output;

                  $protocol = $HTTP_Server_CLI->socket ?? 'http://';
                  $host = $HTTP_Server_CLI->host ?? '0.0.0.0';
                  $port = $HTTP_Server_CLI->port ?? 0;

                  $Output->render('@.;@#green:✓ Bootgly HTTP Server started@;@.;');
                  $Output->render('  Listening on @#cyan:' . $protocol . $host . ':' . $port . '@;@.;');
                  $Output->render('  @#green:● Ready for connections@;@..;');

                  $projectName = defined('BOOTGLY_PROJECT') ? BOOTGLY_PROJECT->folder : 'Benchmark-HTTP_Server_CLI';
                  $Output->render('@#Green:Tip:@; Use @#Black:`bootgly project stop` ' . $projectName . '@; to stop the server.@..;');
               })
            ->on(HTTP_Server_Events::ServerStopped, function ($HTTP_Server_CLI) {
                  $Output = CLI->Terminal->Output;

                  $Output->render('@.;@#yellow:■ Bootgly HTTP Server stopped@;@.;');
               })
            ->start();
      }
      else if ($options['TCP_Server_CLI'] ?? false) {
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
                     require_once __DIR__ . '/HTTP_Server_CLI/Profiler.php';
                     \projects\HTTP_Server_CLI\Profiler::start();
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
      else if ($options['UDP_Server_CLI'] ?? false) {
         new UDP_Server_CLI(Modes::Daemon)
            ->configure(
               host: '0.0.0.0',
               port: getenv(name: 'PORT') ? (int) getenv('PORT') : 8084,
               workers: getenv(name: 'BOOTGLY_WORKERS') ? (int) getenv('BOOTGLY_WORKERS') : max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2)),
            )
            ->on(
               UDP_Server_Events::DatagramReceive,
               static function (string $input): string {
                  return $input; // echo
               }
            )
            ->start();
      }
      else {
         CLI->Terminal->Output->render('@#red:Error:@; No valid server mode specified. Use --HTTP_Server_CLI, --TCP_Server_CLI or --UDP_Server_CLI to start the server for benchmarking. @..;');
      }
   }
);
