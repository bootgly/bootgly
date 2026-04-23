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
use function shell_exec;

use Bootgly\API\Projects\Project;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;


return new Project(
   // # Project Metadata
   name: 'Demo UDP Server CLI',
   description: 'Demonstration project for Bootgly UDP Server CLI',
   version: '1.0.0',
   author: 'Bootgly',

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $UDP_Server_CLI = new UDP_Server_CLI(Mode: match (true) {
         isset($options['i']) => Modes::Interactive,
         isset($options['m']) => Modes::Monitor,
         default => Modes::Daemon
      });
      $UDP_Server_CLI->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : 9999,
         workers: max(1, (int) shell_exec('nproc') ?: 1),
      );
      $UDP_Server_CLI->on(datagramReceive: fn ($data) => $data);

      $UDP_Server_CLI->start();
   }
);
