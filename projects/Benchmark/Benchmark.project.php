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
         CLI->Terminal->Output->render('@#red:Error:@; TCP_Server_CLI mode is not supported in this project. Use HTTP_Server_CLI mode instead. @..;');
      }
      else {
         CLI->Terminal->Output->render('@#red:Error:@; No valid server mode specified. Use --HTTP_Server_CLI to start the HTTP server for benchmarking. @..;');
      }
   }
);
