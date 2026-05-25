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


use const Bootgly\CLI;
use function getenv;
use function intdiv;
use function shell_exec;
use RuntimeException;

use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Environment\Configs;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\DatabaseConfig;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database as DatabaseResource;


return new Project(
   // # Project Metadata
   name: 'Demo HTTP Server CLI',
   description: 'Demonstration project for Bootgly HTTP Server CLI',
   version: '1.0.0',
   author: 'Bootgly',

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $DatabaseResource = static function (object $Context): DatabaseResource {
         static $Database = null;

         if ($Context instanceof Response === false) {
            throw new RuntimeException('Database response resource expects a Response context.');
         }

         if ($Database instanceof SQL === false) {
            $Configs = new Configs(__DIR__ . '/configs/');
            $Configs->allow('database', [
               'DB_CONNECTION',
               'DB_ENABLED',
               'DB_HOST',
               'DB_NAME',
               'DB_PASS',
               'DB_POOL_MAX',
               'DB_POOL_MIN',
               'DB_PORT',
               'DB_SSLCAFILE',
               'DB_SSLMODE',
               'DB_SSLPEER',
               'DB_SSLVERIFY',
               'DB_STATEMENTS',
               'DB_TIMEOUT',
               'DB_USER',
            ]);
            $Scope = $Configs->get('database');

            // @phpstan-ignore-next-line
            if ($Scope instanceof Config === false || $Scope->Enabled->get() !== true) {
               throw new RuntimeException('Enable DB_ENABLED=true in the database config scope and set DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASS as needed.');
            }

            $Database = new SQL((new DatabaseConfig($Scope))->configure());
         }

         return new DatabaseResource($Database);
      };

      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: match (true) {
         isset($options['i']) => Modes::Interactive,
         isset($options['m']) => Modes::Monitor,
         default => Modes::Daemon
      });
      $HTTP_Server_CLI->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : 8082,
         workers: 1,
         responseResources: [
            'Database' => $DatabaseResource,
         ],
         // requestMaxFileSize: 500 * 1024 * 1024,        // 500 MB (default) — max size per uploaded file part
         // requestMaxBodySize: 10 * 1024 * 1024,         // 10 MB (default) — max total non-multipart body
         // requestMaxMultipartFieldSize: 1 * 1024 * 1024, // 1 MB (default) — max size per text field value
         // requestMaxMultipartHeaderSize: 8 * 1024,        // 8 KB (default) — max size of a single part's headers
         // requestMaxMultipartFields: 1024,                // 1024 (default) — max number of text fields per request
         // requestMaxMultipartFiles: 1024,                 // 1024 (default) — max number of file parts per request
      );
      // # Test (Benchmarking)
      // $HTTP_Server_CLI->on(Events::RequestReceived, require __DIR__ . '/router/HTTP_Server_CLI-benchmark-bootgly_router.SAPI.php');
      // $HTTP_Server_CLI->on(Events::RequestReceived, require __DIR__ . '/router/HTTP_Server_CLI-benchmark-static_router.SAPI.php');
      // # Test Request - Download (streaming decoder writes directly to disk)
      // $HTTP_Server_CLI->on(Events::RequestReceived, require __DIR__ . '/router/HTTP_Server_CLI-request-download.SAPI.php');
      // # Test Request - Basic request tests
      // $HTTP_Server_CLI->on(Events::RequestReceived, require __DIR__ . '/router/HTTP_Server_CLI-request.SAPI.php');
      // # Test Request - Input validation examples
      // $HTTP_Server_CLI->on(Events::RequestReceived, require __DIR__ . '/router/HTTP_Server_CLI-validation.SAPI.php');
      // # Test Request - Authentication examples (Basic, Bearer, JWT)
      // $HTTP_Server_CLI->on(Events::RequestReceived, require __DIR__ . '/router/HTTP_Server_CLI-authentication.SAPI.php');
      // # Test Response - Basic response tests
      // $HTTP_Server_CLI->on(Events::RequestReceived, require __DIR__ . '/router/HTTP_Server_CLI-response.SAPI.php');
      // # Test Response - Scheduled (delayed/async) responses
      // $HTTP_Server_CLI->on(Events::RequestReceived, require __DIR__ . '/router/HTTP_Server_CLI-response-scheduled.SAPI.php');
      // # Test Router - all route cases from Testing.routes.php adapted to Generator pattern
      // $HTTP_Server_CLI->on(Events::RequestReceived, require __DIR__ . '/router/HTTP_Server_CLI-router.SAPI.php');
      // $HTTP_Server_CLI->on(Events::RequestReceived, fn ($Request, $Response) => $Response(body: 'Hello, World!'));

      $HTTP_Server_CLI
         // # Test Response - Database (native async PostgreSQL examples)
         ->on(Events::RequestReceived, require __DIR__ . '/router/HTTP_Server_CLI-response-database.SAPI.php')
         ->on(Events::ServerStarted, function ($HTTP_Server_CLI) {
            $Output = CLI->Terminal->Output;

            $protocol = $HTTP_Server_CLI->socket ?? 'http://';
            $host = $HTTP_Server_CLI->host ?? '0.0.0.0';
            $port = $HTTP_Server_CLI->port ?? 0;

            $Output->render('@.;@#green:✓ Bootgly HTTP Server started@;@.;');
            $Output->render('  Listening on @#cyan:' . $protocol . $host . ':' . $port . '@;@.;');
            $Output->render('  @#green:● Ready for connections@;@..;');

            $projectName = defined('BOOTGLY_PROJECT') ? BOOTGLY_PROJECT->folder : 'Demo-HTTP_Server_CLI';
            $Output->render('@#Green:Tip:@; Use @#Black:`bootgly project stop` ' . $projectName . '@; to stop the server.@..;');
         })
         ->on(Events::ServerStopped, function ($HTTP_Server_CLI) {
            $Output = CLI->Terminal->Output;

            $Output->render('@.;@#yellow:■ Bootgly HTTP Server stopped@;@.;');
         });

      $HTTP_Server_CLI->start();
   }
);
