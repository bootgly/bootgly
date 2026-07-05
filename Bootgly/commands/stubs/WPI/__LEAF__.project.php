<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use function getenv;

use const Bootgly\CLI;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
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
         workers: 2
      );
      $Server
         ->on(Events::RequestReceived, HTTP_Server_CLI::$Router->load(__DIR__ . '/router'))
         ->on(Events::ServerStarted, function ($Server) {
            $Output = CLI->Terminal->Output;

            $Output->render('@.;@#green:✓ __NAME__ started@;@.;');
            $Output->render('  Listening on @#cyan:http://0.0.0.0:' . ($Server->port ?? 0) . '@;@..;');
         })
         ->on(Events::ServerStopped, function ($Server) {
            CLI->Terminal->Output->render('@.;@#yellow:■ __NAME__ stopped@;@.;');
         });

      $Server->start();
   }
);
