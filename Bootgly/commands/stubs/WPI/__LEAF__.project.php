<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use function getenv;

use const Bootgly\CLI;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;


return new Project(
   // # Project Metadata
   name: '__NAME__',
   description: '__DESCRIPTION__',
   version: '__VERSION__',
   author: '__AUTHOR__',
   exportable: true,

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $Server = new HTTP_Server_CLI(Mode: match (true) {
         isSet($options['f']) => Modes::Foreground,
         isSet($options['i']) => Modes::Interactive,
         isSet($options['m']) => Modes::Monitor,
         default => Modes::Daemon
      });
      $Server->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : (int) '__PORT__',
         workers: 2,
         // ? Auto-TLS (automatic HTTPS via Let's Encrypt) — set your domain and uncomment:
         // secure: new AutoTLS(
         //    domains: ['example.com'],
         //    email: 'admin@example.com',
         //    staging: true, // validate with the staging CA first — flip to false for the real certificate
         // ),
         user: 'debian',   // demote workers from root (root needed to bind port 80 for HTTP-01)
         group: 'debian',
         // health: '/health', // built-in K8s probe endpoint (answers before middlewares)
      );
      $Server
         ->on(Events::RequestReceived, HTTP_Server_CLI::$Router->load(__DIR__ . '/router'))
         ->on(Events::ServerAdvertised, function ($Server) {
            // ? Launch banner — fired on the process that owns the terminal
            //   (on Daemon mode, the launcher); advertise() prints the addresses
            CLI->Terminal->Output->render('@.;@#green:✓ __NAME__ started@;@.;');
            $Server->advertise();
         })
         ->on(Events::ServerStopped, function ($Server) {
            CLI->Terminal->Output->render('@.;@#yellow:■ __NAME__ stopped@;@.;');
         });

      $Server->start();
   }
);
