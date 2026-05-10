<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Demo_HTTP_Server_CLI;


use function getenv;
use function shell_exec;

use Bootgly\API\Projects\Project;
use Bootgly\API\Endpoints\Server\Modes;
use const Bootgly\CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;


return new Project(
   // # Project Metadata
   name: 'Demo HTTP Server CLI',
   description: 'Demonstration project for Bootgly HTTP Server CLI',
   version: '1.0.0',
   author: 'Bootgly',

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: match (true) {
         isset($options['i']) => Modes::Interactive,
         isset($options['m']) => Modes::Monitor,
         default => Modes::Daemon
      });
      $HTTP_Server_CLI->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : 8082,
         workers: max(1, (int) shell_exec('nproc') ?: 1) / 2,
         // requestMaxFileSize: 500 * 1024 * 1024,        // 500 MB (default) — max size per uploaded file part
         // requestMaxBodySize: 10 * 1024 * 1024,         // 10 MB (default) — max total non-multipart body
         // requestMaxMultipartFieldSize: 1 * 1024 * 1024, // 1 MB (default) — max size per text field value
         // requestMaxMultipartHeaderSize: 8 * 1024,        // 8 KB (default) — max size of a single part's headers
         // requestMaxMultipartFields: 1024,                // 1024 (default) — max number of text fields per request
         // requestMaxMultipartFiles: 1024,                 // 1024 (default) — max number of file parts per request
      );
      $HTTP_Server_CLI->on(
         // # Test (Benchmarking)
         requestReceived: require __DIR__ . '/router/HTTP_Server_CLI-benchmark-bootgly_router.SAPI.php',
         #request: require __DIR__ . '/router/HTTP_Server_CLI-benchmark-static_router.SAPI.php',

         // # Test Request - Download (streaming decoder writes directly to disk)
         #request: require __DIR__ . '/router/HTTP_Server_CLI-request-download.SAPI.php',
         // # Test Request - Basic request tests
         #request: require __DIR__ . '/router/HTTP_Server_CLI-request.SAPI.php',
         // # Test Request - Input validation examples
         #request: require __DIR__ . '/router/HTTP_Server_CLI-validation.SAPI.php',
         // # Test Request - Authentication examples (Basic, Bearer, JWT)
         #request: require __DIR__ . '/router/HTTP_Server_CLI-authentication.SAPI.php',

         // # Test Response - Basic response tests
         #request: require __DIR__ . '/router/HTTP_Server_CLI-response.SAPI.php',
         // # Test Response - Scheduled (delayed/async) responses
         #request: require __DIR__ . '/router/HTTP_Server_CLI-response-scheduled.SAPI.php',

         // # Test Router - all route cases from Testing.routes.php adapted to Generator pattern
         #request: require __DIR__ . '/router/HTTP_Server_CLI-router.SAPI.php',

         #request: fn ($Request, $Response) => $Response(body: 'Hello, World!'),

         serverStarted: function ($HTTP_Server_CLI) {
            $Output = CLI->Terminal->Output;

            $protocol = $HTTP_Server_CLI->socket ?? 'http://';
            $host = $HTTP_Server_CLI->host ?? '0.0.0.0';
            $port = $HTTP_Server_CLI->port ?? 0;

            $Output->render('@.;@#green:✓ Bootgly HTTP Server started@;@.;');
            $Output->render('  Listening on @#cyan:' . $protocol . $host . ':' . $port . '@;@.;');
            $Output->render('  @#green:● Ready for connections@;@..;');

            $projectName = defined('BOOTGLY_PROJECT') ? BOOTGLY_PROJECT->folder : 'Demo-HTTP_Server_CLI';
            $Output->render('@#Green:Tip:@; Use @#Black:`bootgly project stop` ' . $projectName . '@; to stop the server.@..;');
         },
         serverStopped: function ($HTTP_Server_CLI) {
            $Output = CLI->Terminal->Output;

            $Output->render('@.;@#yellow:■ Bootgly HTTP Server stopped@;@.;');
         }
      );

      $HTTP_Server_CLI->start();
   }
);