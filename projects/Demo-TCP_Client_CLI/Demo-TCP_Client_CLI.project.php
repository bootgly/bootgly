<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Demo_TCP_Client_CLI;


use function getenv;

use Bootgly\ACI\Events\Timer;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;


return new Project(
   // # Project Metadata
   name: 'Demo TCP Client CLI',
   description: 'Demonstration project for Bootgly TCP Client CLI',
   version: '1.0.0',
   author: 'Bootgly',

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $TCP_Client_CLI = new TCP_Client_CLI(TCP_Client_CLI::MODE_MONITOR);
      $TCP_Client_CLI->configure(
         host: '127.0.0.1',
         port: getenv('PORT') ? (int) getenv('PORT') : 8082,
         workers: 1
      );

      // This runs a Benchmark for 10 seconds with 1 Worker
      // type stats command in Server to get stats of writes
      $TCP_Client_CLI->on(
         // on Worker start
         workerStarted: function ($TCP_Client_CLI) {
            // @ Connect to Server
            $Socket = $TCP_Client_CLI->connect();

            if ($Socket) {
               $TCP_Client_CLI::$Event->loop();
            }
         },
         // on Client connect
         clientConnect: function ($Socket, $Connection) {
            // @ Set Connection expiration
            Timer::add(
               interval: 10,
               handler: function ($Connection) {
                  $Connection->close();
               },
               args: [$Connection],
               persistent: false
            );

            // @ Set Data raw to write
            $Connection::$output = "GET / HTTP/1.1\r\nHost: localhost:8080\r\n\r\n";

            // @ Add Package write to Event loop
            TCP_Client_CLI::$Event->add($Socket, TCP_Client_CLI::$Event::EVENT_WRITE, $Connection);
         },
         clientDisconnect: function ($Connection) use ($TCP_Client_CLI) {
            $TCP_Client_CLI->log(
               'Connection #' . $Connection->id . ' (' . $Connection->address . ':' . $Connection->port . ')'
               . ' from Worker with PID @_:' . $TCP_Client_CLI->Process->id . '_@ was closed! @\;'
            );
         },
         // on Data write / read
         dataWrite: function ($Socket, $Connection, $Package) {
            // @ Add Package read to Event loop
            TCP_Client_CLI::$Event->add($Socket, TCP_Client_CLI::$Event::EVENT_READ, $Connection);
         },
         dataRead: null,
      );

      $TCP_Client_CLI->start();
   }
);
