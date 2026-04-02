<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\TCP_Server_CLI;


use function getenv;

use Bootgly\API\Projects\Project;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;


return new Project(
   name: 'TCP Server CLI',
   description: 'Raw TCP server with configurable workers',
   version: '0.1.0',
   author: 'Rodrigo Vieira',

   boot: function (array $arguments = [], array $options = []): void
   {
      $TCP_Server_CLI = new TCP_Server_CLI(Mode: match (true) {
         isSet($options['i']) => Modes::Interactive,
         isSet($options['m']) => Modes::Monitor,
         default              => Modes::Daemon
      });
      $TCP_Server_CLI->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : 8080,
         workers: 12
      );
      $TCP_Server_CLI->on(
         package: (require __DIR__ . '/TCP_Server_CLI.SAPI.php')['on.Package.Receive']
      );
      $TCP_Server_CLI->start();
   }
);
