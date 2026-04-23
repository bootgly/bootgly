<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Events\Timer;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Interfaces\UDP_Client_CLI;


return new Project(
   // # Project Metadata
   name: 'Demo UDP Client CLI',
   description: 'Demonstration project for Bootgly UDP Client CLI',
   version: '1.0.0',
   author: 'Bootgly',

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $UDP_Client_CLI = new UDP_Client_CLI(UDP_Client_CLI::MODE_MONITOR);
      $UDP_Client_CLI->configure(
         host: '127.0.0.1',
         port: getenv('PORT') ? (int) getenv('PORT') : 9999,
         workers: 1
      );
      $UDP_Client_CLI->on(
         workerStarted: function ($UDP_Client_CLI) {
            $Socket = $UDP_Client_CLI->connect();
            if ($Socket) {
               $UDP_Client_CLI::$Event->loop();
            }
         },
         clientConnect: function ($Socket, $Connection) {
            Timer::add(
               interval: 10,
               handler: function ($Connection) {
                  $Connection->close();
               },
               args: [$Connection],
               persistent: false
            );
            $Connection->output = 'Hello, Bootgly UDP!';
            UDP_Client_CLI::$Event->add($Socket, UDP_Client_CLI::$Event::EVENT_WRITE, $Connection);
         },
         clientDisconnect: function ($Connection) use ($UDP_Client_CLI) {
            $UDP_Client_CLI->log(
               'Connection #' . $Connection->id . ' (' . $Connection->address . ':' . $Connection->port . ')'
               . ' from Worker with PID @_:' . $UDP_Client_CLI->Process->id . '_@ was closed! @\;'
            );
         },
         datagramWrite: function ($Socket, $Connection) {
            UDP_Client_CLI::$Event->add($Socket, UDP_Client_CLI::$Event::EVENT_WRITE, $Connection);
         },
         datagramRead: null,
      );

      $UDP_Client_CLI->start();
   }
);