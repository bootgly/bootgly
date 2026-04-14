<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Demo_CLI;


use function getenv;

use Bootgly\ACI\Events\Timer;
use Bootgly\API\Projects\Project;
use Bootgly\API\Endpoints\Server\Modes;
use const Bootgly\CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;


return new Project(
   // # Project Metadata
   name: 'Demo Bootgly',
   description: 'Demonstration project showcasing Bootgly\'s workables',
   version: '1.0.0',
   author: 'Bootgly',

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      // * Config
      $demos = [
         'CLI',
         'HTTP_Server_CLI',
         'HTTPS_Server_CLI',
         'HTTP_Client_CLI',
         'HTTPS_Client_CLI',
         'TCP_Server_CLI',
         'TCP_Client_CLI',
      ];

      // @ Resolve which demo was requested via --<demo> option
      $demo = null;
      foreach ($demos as $name) {
         if ($options[$name] ?? false) {
            $demo = $name;
            break;
         }
      }

      // @ Show helper if no demo specified
      if ($demo === null) {
         $Output = CLI->Terminal->Output;
         $Output->render(<<<'HELP'

         Usage: @#white: bootgly project Demo start <demo> [args...] @;

         Available demos:
           @#yellow: --CLI @;                  Terminal Input/Output and UI components;
           @#yellow: --HTTP_Server_CLI @;      HTTP server (router, middleware, request/response);
           @#yellow: --HTTPS_Server_CLI @;     HTTPS server with SSL/TLS;
           @#yellow: --TCP_Server_CLI @;       Raw TCP server;
           @#yellow: --HTTP_Client_CLI @;      HTTP client (connect to running HTTP server);
           @#yellow: --TCP_Client_CLI @;       TCP client benchmark;

         Examples:
           bootgly project Demo start --CLI
           bootgly project Demo start --CLI 1
           bootgly project Demo start --HTTP_Server_CLI
           bootgly project Demo start --HTTP_Server_CLI -i
           bootgly project Demo start --TCP_Server_CLI


         HELP);

         return;
      }

      // @ Load demo
      match ($demo) {
         'CLI' => (function () use ($arguments) {
            $id = $arguments[0] ?? null;
            if ($id !== null) {
               $id = (int) $id;
            }

            // @
            $Output = CLI->Terminal->Output;
            $Output->expand(lines: CLI->Terminal::$lines);

            // @ Reset Output
            if ($id === 0) {
               $Output->reset();
               return;
            }

            $examples = [
               // ! Terminal
               // ? Input
               1 => 'CLI/Terminal/Input/@reading-01.demo.php',

               // ? Output
               // Terminal -> Output @ writing
               2 => 'CLI/Terminal/Output/@writing-01.demo.php',

               // Terminal -> Output -> Cursor Positioning
               3 => 'CLI/Terminal/Output/Cursor-positioning-01.demo.php',
               // Terminal -> Output -> Cursor Shaping
               4 => 'CLI/Terminal/Output/Cursor-shaping-01.demo.php',
               // Terminal -> Output -> Cursor Visualizing
               5 => 'CLI/Terminal/Output/Cursor-visualizing-01.demo.php',

               // Terminal -> Output -> Text Formatting - Coloring
               6 => 'CLI/Terminal/Output/Text-formatting-coloring-01.demo.php',
               // Terminal -> Output -> Text Formatting - Styling
               7 => 'CLI/Terminal/Output/Text-formatting-styling-01.demo.php',

               // Terminal -> Output -> Text Modifying
               8 => 'CLI/Terminal/Output/Text-modifying-01.demo.php',
               // Terminal -> Output -> Text Modifying - In Display
               9 => 'CLI/Terminal/Output/Text-modifying-indisplay-01.demo.php',
               // Terminal -> Output -> Text Modifying - Inline
               10 => 'CLI/Terminal/Output/Text-modifying-inline-01.demo.php',
               // Terminal -> Output -> Text Modifying - Line
               11 => 'CLI/Terminal/Output/Text-modifying-line-01.demo.php',

               // ! UI
               // UI - Alert component
               12 => 'CLI/UI/Alert-01.demo.php',

               // UI - Menu component
               13 => 'CLI/UI/Menu-01.demo.php',
               14 => 'CLI/UI/Menu-02.demo.php',
               15 => 'CLI/UI/Menu-03.demo.php',
               16 => 'CLI/UI/Menu-04.demo.php',
               17 => 'CLI/UI/Menu-05.demo.php',
               18 => 'CLI/UI/Menu-06.demo.php',

               // UI - Progress component
               19 => 'CLI/UI/Progress-01.demo.php',
               20 => 'CLI/UI/Progress-02.demo.php',

               // UI - Table component
               21 => 'CLI/UI/Table-01.demo.php',

               // UI - Fieldset component
               22 => 'CLI/UI/Fieldset-01.demo.php',
            ];

            foreach ($examples as $index => $example) {
               if ($id && $index !== $id) {
                  continue;
               }

               $file = $example;
               $wait = 3;
               $location = "projects/Demo/CLI/$file";

               require __DIR__ . '/' . $file;

               sleep($wait);

               CLI->Terminal->clear();
            }
         })(),

         'HTTP_Server_CLI' => (function (array $options = []) {
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
               workers: max(1, (int) shell_exec('nproc') ?: 1) / 2,
               // requestMaxFileSize: 500 * 1024 * 1024, // 500 MB (default)
               // requestMaxBodySize: 10 * 1024 * 1024,  // 10 MB (default)
            );
            $Server->on(
               // # Test (Benchmarking)
               request: require __DIR__ . '/HTTP_Server_CLI/router/HTTP_Server_CLI-benchmark-bootgly_router.SAPI.php',
               #request: require __DIR__ . '/HTTP_Server_CLI/router/HTTP_Server_CLI-benchmark-static_router.SAPI.php',

               // # Test Request - Download (streaming decoder writes directly to disk)
               #request: require __DIR__ . '/HTTP_Server_CLI/router/HTTP_Server_CLI-request-download.SAPI.php',
               // # Test Request - Basic request tests
               #request: require __DIR__ . '/HTTP_Server_CLI/router/HTTP_Server_CLI-request.SAPI.php',
         
               // # Test Response - Basic response tests
               #request: require __DIR__ . '/HTTP_Server_CLI/router/HTTP_Server_CLI-response.SAPI.php',
               // # Test Response - Scheduled (delayed/async) responses
               #request: require __DIR__ . '/HTTP_Server_CLI/router/HTTP_Server_CLI-response-scheduled.SAPI.php',

               // # Test Router - all route cases from Testing.routes.php adapted to Generator pattern
               #request: require __DIR__ . '/HTTP_Server_CLI/router/HTTP_Server_CLI-router.SAPI.php',

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
         })($options),
         'HTTPS_Server_CLI' => (function (array $options = []) {
            // Similar to HTTP_Server_CLI but with SSL/TLS configuration

            $Server = new HTTP_Server_CLI(Mode: match (true) {
               isset($options['i'])
               => Modes::Interactive,
               isset($options['m'])
               => Modes::Monitor,
               default
               => Modes::Daemon
            });
            $Server->configure(
               host: '0.0.0.0',
               port: getenv('PORT') ? (int) getenv('PORT') : 443,
               workers: 4,
               // requestMaxFileSize: 500 * 1024 * 1024, // 500 MB (default)
               // requestMaxBodySize: 10 * 1024 * 1024,  // 10 MB (default)
               ssl: [
                  'local_cert' => BOOTGLY_ROOT_DIR . '@/certificates/localhost.cert.pem',
                  'local_pk' => BOOTGLY_ROOT_DIR . '@/certificates/localhost.key.pem',

                  'verify_peer' => false,
               ],
               // Drop privileges after binding to port 443
               user: 'www-data',
            );
            $Server->on(
            request: fn($Request, $Response) => $Response(body: 'Hello, Secure World!'),

            started: function ($Server) {
               $Output = CLI->Terminal->Output;
               $protocol = $Server->socket ?? 'https://';
               $host = $Server->host ?? '0.0.0.0';
               $port = $Server->port ?? 0;

               $Output->render('@.;@#green:✓ Bootgly HTTPS Server started@;@.;');
               $Output->render('  Listening on @#cyan:' . $protocol . $host . ':' . $port . '@;@.;');
               $Output->render('  @#green:● Ready for connections@;@..;');

               $projectName = \defined('BOOTGLY_PROJECT') ? BOOTGLY_PROJECT->folder : 'HTTPS_Server_CLI';
               $Output->render('@#Green:Tip:@; Use @#Black:bootgly project stop ' . $projectName . '@; to stop the server.@..;');
            },
            stopped: function ($Server) {
               $Output = CLI->Terminal->Output;

               $Output->render('@.;@#yellow:■ Bootgly HTTPS Server stopped@;@.;');
            }
            );

            $Server->start();
         })($options),

         'TCP_Server_CLI' => (function (array $options = []) {
            $TCP_Server_CLI = new TCP_Server_CLI(Mode: match (true) {
               isset($options['i']) => Modes::Interactive,
               isset($options['m']) => Modes::Monitor,
               default => Modes::Daemon
            });
            $TCP_Server_CLI->configure(
            host: '0.0.0.0',
            port: getenv('PORT') ? (int) getenv('PORT') : 8080,
            workers: 12
            );
            $TCP_Server_CLI->on(
            package: (require __DIR__ . '/TCP_Server_CLI/TCP_Server_CLI.SAPI.php')['on.Package.Receive']
            );
            $TCP_Server_CLI->start();
         })($options),
         'HTTP_Client_CLI' => (function () use ($options) {
            (require __DIR__ . '/HTTP_Client_CLI/HTTP_Client_CLI.SAPI.php')($options);
         })(),
         'HTTPS_Client_CLI' => (function () use ($options) {
            (require __DIR__ . '/HTTPS_Client_CLI/HTTPS_Client_CLI.SAPI.php')($options);
         })(),
         'TCP_Client_CLI' => (function () {
            $TCP_Client = new TCP_Client_CLI(
            TCP_Client_CLI::MODE_MONITOR
            );
            $TCP_Client->configure(
            host: '127.0.0.1',
            port: getenv('PORT') ? (int) getenv('PORT') : 8082,
            workers: 1
            );
            // This runs a Benchmark for 10 seconds with 1 Worker
            // type stats command in Server to get stats of writes
            $TCP_Client->on(
            // on Worker instance
            instance: function ($Client) {
               // @ Connect to Server
               $Socket = $Client->connect();

               if ($Socket) {
                  $Client::$Event->loop();
               }
            },
            // on Connection connect
            connect: function ($Socket, $Connection) {
               // @ Set Connection expiration
               Timer::add(
               interval: 10,
               handler: function ($Connection) {
                  $Connection->close();
               },
               args: [$Connection],
               persistent: false
               );

               // @ Set Data raw to write
               $Connection::$output = "GET / HTTP/1.1\r\nHost: localhost:8080\r\n\r\n";

               // @ Add Package write to Event loop
               TCP_Client_CLI::$Event->add($Socket, TCP_Client_CLI::$Event::EVENT_WRITE, $Connection);
            },
            disconnect: function ($Connection) use ($TCP_Client) {
               $TCP_Client->log(
               'Connection #' . $Connection->id . ' (' . $Connection->address . ':' . $Connection->port . ')'
               . ' from Worker with PID @_:' . $TCP_Client->Process->id . '_@ was closed! @\;'
               );
            },
            // on Package write / read
            write: function ($Socket, $Connection, $Package) {
               // @ Add Package read to Event loop
               TCP_Client_CLI::$Event->add($Socket, TCP_Client_CLI::$Event::EVENT_READ, $Connection);
            },
            read: null,
            );
            $TCP_Client->start();
         })(),
      };
   }
);
