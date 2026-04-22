<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Demo_HTTPS_Server_CLI;


use function getenv;

use Bootgly\API\Projects\Project;
use Bootgly\API\Endpoints\Server\Modes;
use const Bootgly\CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;


return new Project(
   // # Project Metadata
   name: 'Demo HTTPS Server CLI',
   description: 'Demonstration project for Bootgly HTTPS Server CLI',
   version: '1.0.0',
   author: 'Bootgly',

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $Server = new HTTP_Server_CLI(Mode: match (true) {
         isset($options['i']) => Modes::Interactive,
         isset($options['m']) => Modes::Monitor,
         default => Modes::Daemon
      });
      $Server->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : 443,
         workers: 4,
         // requestMaxFileSize: 500 * 1024 * 1024, // 500 MB (default)
         // requestMaxBodySize: 10 * 1024 * 1024,  // 10 MB (default)
         secure: [
            'local_cert' => BOOTGLY_ROOT_DIR . '@/certificates/localhost.cert.pem',
            'local_pk' => BOOTGLY_ROOT_DIR . '@/certificates/localhost.key.pem',

            'verify_peer' => false,
         ],
         // Drop privileges after binding to port 443
         user: 'www-data',
      );
      $Server->on(
         request: fn ($Request, $Response) => $Response(body: 'Hello, Secure World!'),

         started: function ($Server) {
            $Output = CLI->Terminal->Output;
            $protocol = $Server->socket ?? 'https://';
            $host = $Server->host ?? '0.0.0.0';
            $port = $Server->port ?? 0;

            $Output->render('@.;@#green:✓ Bootgly HTTPS Server started@;@.;');
            $Output->render('  Listening on @#cyan:' . $protocol . $host . ':' . $port . '@;@.;');
            $Output->render('  @#green:● Ready for connections@;@..;');

            $projectName = defined('BOOTGLY_PROJECT') ? BOOTGLY_PROJECT->folder : 'Demo-HTTPS_Server_CLI';
            $Output->render('@#Green:Tip:@; Use @#Black:bootgly project stop ' . $projectName . '@; to stop the server.@..;');
         },
         stopped: function ($Server) {
            $Output = CLI->Terminal->Output;

            $Output->render('@.;@#yellow:■ Bootgly HTTPS Server stopped@;@.;');
         }
      );

      $Server->start();
   }
);
