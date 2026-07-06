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
use function shell_exec;

use Bootgly\API\Projects\Project;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;


return new Project(
   // # Project Metadata
   name: 'Demo UDP Server CLI',
   description: 'Demonstration project for Bootgly UDP Server CLI',
   version: '1.0.0',
   author: 'Bootgly',
   exportable: true,

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $UDP_Server_CLI = new UDP_Server_CLI(Mode: match (true) {
         isset($options['f']) => Modes::Foreground,
         isset($options['i']) => Modes::Interactive,
         isset($options['m']) => Modes::Monitor,
         default => Modes::Daemon
      });
      $UDP_Server_CLI->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : 9999,
         workers: max(1, (int) shell_exec('nproc') ?: 1),
      );
      $UDP_Server_CLI->on(Events::DatagramReceive, fn ($data) => $data);

      $UDP_Server_CLI->start();
   }
);
