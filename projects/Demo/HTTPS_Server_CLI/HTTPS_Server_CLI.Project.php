<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Demo\HTTPS_Server_CLI;


use const BOOTGLY_PROJECT;
use const BOOTGLY_ROOT_DIR;
use function getenv;

use const Bootgly\CLI;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Configs as ServerConfigs;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Configs as RequestConfigs;


return new Project(
   // # Project Metadata
   name: 'Demo HTTPS Server CLI',
   description: 'Demonstration project for Bootgly HTTPS Server CLI',
   version: '1.0.0',
   author: 'Bootgly',
   exportable: true,

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: match (true) {
         isset($options['f']) => Modes::Foreground,
         isset($options['i']) => Modes::Interactive,
         isset($options['m']) => Modes::Monitor,
         default => Modes::Daemon
      });
      $HTTP_Server_CLI->configure(
         new ServerConfigs(
            host: '0.0.0.0',
            port: getenv('PORT') ? (int) getenv('PORT') : 443,
            workers: 4,
            secure: [
               'local_cert' => BOOTGLY_ROOT_DIR . '@/certificates/localhost.cert.pem',
               'local_pk' => BOOTGLY_ROOT_DIR . '@/certificates/localhost.key.pem',

               'verify_peer' => false,
            ],
            // Drop privileges after binding to port 443
            user: 'www-data',
         ),
         // new RequestConfigs(
         //    maxFileSize: 500 * 1024 * 1024, // 500 MB (default)
         //    maxBodySize: 10 * 1024 * 1024,  // 10 MB (default)
         // ),
      );
      $HTTP_Server_CLI
         ->on(Events::RequestReceived, fn ($Request, $Response) => $Response(body: 'Hello, Secure World!'))
         // # Launch banner — fired on the process that owns the terminal. On Daemon
         //   mode the master is already detached, so the launcher renders it here;
         //   `ServerStarted` would write to a closed stream and print nothing.
         ->on(Events::ServerAdvertised, function ($HTTP_Server_CLI) {
            $Output = CLI->Terminal->Output;

            $Output->render('@.;@#green:✓ Bootgly HTTPS Server started@;@.;');
            $HTTP_Server_CLI->advertise();
            $Output->render('  @#green:● Ready for connections@;@..;');

            $project = BOOTGLY_PROJECT->folder;
            $Output->render("@#Green:Tip:@; Use @#Black:`bootgly project stop {$project}`@; to stop the server.@..;");
         })
         ->on(Events::ServerStopped, function ($HTTP_Server_CLI) {
            $Output = CLI->Terminal->Output;

            $Output->render('@.;@#yellow:■ Bootgly HTTPS Server stopped@;@.;');
         });

      $HTTP_Server_CLI->start();
   }
);
