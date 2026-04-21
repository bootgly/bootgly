<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Demo_TCP_Server_CLI;


use function getenv;

use Bootgly\API\Projects\Project;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;


return new Project(
   // # Project Metadata
   name: 'Demo TCP Server CLI',
   description: 'Demonstration project for Bootgly TCP Server CLI',
   version: '1.0.0',
   author: 'Bootgly',

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $Server = new TCP_Server_CLI(Mode: match (true) {
         isset($options['i']) => Modes::Interactive,
         isset($options['m']) => Modes::Monitor,
         default => Modes::Daemon
      });
      $Server->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : 8080,
         workers: 12
      );
      $Server->on(
         package: (require __DIR__ . '/../Demo/TCP_Server_CLI/TCP_Server_CLI.SAPI.php')['on.Package.Receive']
      );

      $Server->start();
   }
);
