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
use function getenv;

use Bootgly\API\Projects\Project;
use Bootgly\API\Endpoints\Server\Modes;
use const Bootgly\CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;


return new Project(
   name: 'Benchmark Bootgly',
   description: 'Benchmarking project for Bootgly\'s',
   version: '1.0.0',
   author: 'Bootgly',

   boot: function (array $arguments = [], array $options = []): void {
      if ($options['HTTP_Server_CLI'] ?? false) {
         new HTTP_Server_CLI(Modes::Daemon)
            ->configure(
               host: '0.0.0.0',
               port: getenv('PORT') ? (int) getenv('PORT') : 8082,
               workers: getenv('BOOTGLY_WORKERS') ? (int) getenv('BOOTGLY_WORKERS') : max(1, (int) ((int)(exec('nproc 2>/dev/null') ?: 1) / 2)),
               // requestMaxFileSize: 500 * 1024 * 1024, // 500 MB (default)
               // requestMaxBodySize: 10 * 1024 * 1024,  // 10 MB (default)
            )
            ->on(
               // # Test (Benchmarking)
               #request: require __DIR__ . '/HTTP_Server_CLI/router/basic.SAPI.php',
               request: require __DIR__ . '/HTTP_Server_CLI/router/default.SAPI.php',

               started: function ($HTTP_Server_CLI) {
                  $Output = CLI->Terminal->Output;

                  $protocol = $HTTP_Server_CLI->socket ?? 'http://';
                  $host = $HTTP_Server_CLI->host ?? '0.0.0.0';
                  $port = $HTTP_Server_CLI->port ?? 0;

                  $Output->render('@.;@#green:✓ Bootgly HTTP Server started@;@.;');
                  $Output->render('  Listening on @#cyan:' . $protocol . $host . ':' . $port . '@;@.;');
                  $Output->render('  @#green:● Ready for connections@;@..;');

                  $projectName = defined('BOOTGLY_PROJECT') ? BOOTGLY_PROJECT->folder : 'Benchmark-HTTP_Server_CLI';
                  $Output->render('@#Green:Tip:@; Use @#Black:`bootgly project stop` ' . $projectName . '@; to stop the server.@..;');
               },
               stopped: function ($HTTP_Server_CLI) {
                  $Output = CLI->Terminal->Output;

                  $Output->render('@.;@#yellow:■ Bootgly HTTP Server stopped@;@.;');
               }
            )
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

         new TCP_Server_CLI(Modes::Daemon)
            ->configure(
               host: '0.0.0.0',
               port: getenv('PORT') ? (int) getenv('PORT') : 8083,
               workers: getenv('BOOTGLY_WORKERS') ? (int) getenv('BOOTGLY_WORKERS') : max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2)),
            )
            ->on(
               package: static function (string $input) use ($httpResponse): string {
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
               package: static function (string $input): string {
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
