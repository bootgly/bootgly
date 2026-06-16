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

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Events;


return new Project(
   // # Project Metadata
   name: 'Demo TCP Server CLI',
   description: 'Demonstration project for Bootgly TCP Server CLI',
   version: '1.0.0',
   author: 'Bootgly',

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $TCP_Server_CLI = new TCP_Server_CLI(Mode: match (true) {
         isset($options['f']) => Modes::Foreground,
         isset($options['i']) => Modes::Interactive,
         isset($options['m']) => Modes::Monitor,
         default => Modes::Daemon
      });
      $TCP_Server_CLI->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : 8080,
         workers: 12
      );
      // @ Raw TCP responder: reply to any received data with a minimal HTTP/1.1
      //   "Hello, World!" response (Content-Length: 13). Self-contained handler.
      $TCP_Server_CLI->on(
         Events::DataReceive,
         static fn ($input) => "HTTP/1.1 200 OK\r\nServer: Bootgly\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Length: 13\r\n\r\nHello, World!"
      );

      $TCP_Server_CLI->start();
   }
);
