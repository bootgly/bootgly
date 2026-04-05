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
use const Bootgly\CLI;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return new Project(
   name: 'HTTP Server CLI',
   description: 'HTTP server demo project for testing and benchmarking Bootgly\'s HTTP Server capabilities in CLI mode.',
   version: '0.1.0',
   author: 'Rodrigo Vieira',

   boot: function (array $arguments = [], array $options = []): void
   {
      $Server = new HTTP_Server_CLI(Mode: match (true) {
         isSet($options['i'])
            => Modes::Interactive,
         isSet($options['m'])
            => Modes::Monitor,
         default
            => Modes::Daemon
      });
      $Server->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : 8082,
         // get CPU count directly from the system for optimal performance / 2
         // workers: max(1, (int) shell_exec('nproc') ?: 1) / 2
         workers: getenv('BOOTGLY_WORKERS') ? (int) getenv('BOOTGLY_WORKERS') : 11, // default: 11 workers (best with wrk 10 threads, CPU count 24)
         // requestMaxFileSize: 500 * 1024 * 1024, // 500 MB (default)
         // requestMaxBodySize: 10 * 1024 * 1024,  // 10 MB (default)
      );
      $Server->on(
         // # Test (Benchmarking)
         request: require __DIR__ . '/router/HTTP_Server_CLI-benchmark-bootgly_router.SAPI.php',
         #request: require __DIR__ . '/router/HTTP_Server_CLI-benchmark-static_router.SAPI.php',

         // # Test Request - Download (streaming decoder writes directly to disk)
         #request: require __DIR__ . '/router/HTTP_Server_CLI-request-download.SAPI.php',
         // # Test Request - Basic request tests
         #request: require __DIR__ . '/router/HTTP_Server_CLI-request.SAPI.php',
   
         // # Test Response - Basic response tests
         #request: require __DIR__ . '/router/HTTP_Server_CLI-response.SAPI.php',
         // # Test Response - Scheduled (delayed/async) responses
         #request: require __DIR__ . '/router/HTTP_Server_CLI-response-scheduled.SAPI.php',

         // # Test Router - all route cases from Testing.routes.php adapted to Generator pattern
         #request: require __DIR__ . '/router/HTTP_Server_CLI-router.SAPI.php',

         #request: fn ($Request, $Response) => $Response(body: 'Hello, World!'),

         started: function ($Server) {
            $Output = CLI->Terminal->Output;

            $protocol = $Server->socket ?? 'http://';
            $host = $Server->host ?? '0.0.0.0';
            $port = $Server->port ?? 0;

            $Output->render('@.;@#green:✓ Bootgly HTTP Server started@;@.;');
            $Output->render('  Listening on @#cyan:' . $protocol . $host . ':' . $port . '@;@.;');
            $Output->render('  @#green:● Ready for connections@;@..;');

            $projectName = defined('BOOTGLY_PROJECT') ? BOOTGLY_PROJECT->folder : 'HTTP_Server_CLI';
            $Output->render('@#Green:Tip:@; Use @#Black:`bootgly project stop` ' . $projectName . '@; to stop the server.@..;');
         },
         stopped: function ($Server) {
            $Output = CLI->Terminal->Output;

            $Output->render('@.;@#yellow:■ Bootgly HTTP Server stopped@;@.;');
         }
      );
      $Server->start();
   }
);
