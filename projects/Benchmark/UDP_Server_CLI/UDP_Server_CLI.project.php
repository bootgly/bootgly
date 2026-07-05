<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Benchmark\UDP_Server_CLI;


use function exec;
use function getenv;
use function max;

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events as UDP_Server_Events;


return new Project(
   name: 'Benchmark UDP Server CLI',
   description: 'Raw UDP echo server benchmark for Bootgly',
   version: '1.0.0',
   author: 'Bootgly',
   exportable: false,

   boot: function (array $arguments = [], array $options = []): void {
      new UDP_Server_CLI(Modes::Daemon)
         ->configure(
            host: '0.0.0.0',
            port: getenv(name: 'PORT') ? (int) getenv('PORT') : 8084,
            workers: getenv(name: 'BOOTGLY_WORKERS') ? (int) getenv('BOOTGLY_WORKERS') : max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2)),
         )
         ->on(
            UDP_Server_Events::DatagramReceive,
            static function (string $input): string {
               return $input; // echo
            }
         )
         ->start();
   }
);
