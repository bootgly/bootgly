<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Events\Timer;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Interfaces\UDP_Client_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Events;


return new Project(
   // # Project Metadata
   name: 'Demo UDP Client CLI',
   description: 'Demonstration project for Bootgly UDP Client CLI',
   version: '1.0.0',
   author: 'Bootgly',
   exportable: true,

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $UDP_Client_CLI = new UDP_Client_CLI(UDP_Client_CLI::MODE_MONITOR);
      $UDP_Client_CLI->configure(
         new Configs(
            host: '127.0.0.1',
            port: getenv('PORT') ? (int) getenv('PORT') : 9999,
            workers: 1
         )
      );
      $UDP_Client_CLI
         ->on(Events::WorkerStarted, function ($UDP_Client_CLI) {
            $Socket = $UDP_Client_CLI->connect();
            if ($Socket) {
               $UDP_Client_CLI::$Event->loop();
            }
         })
         ->on(Events::ClientConnect, function ($Socket, $Connection) {
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
         })
         ->on(Events::ClientDisconnect, function ($Connection) use ($UDP_Client_CLI) {
            $UDP_Client_CLI->Logger->log(
               info: "Connection #{$Connection->id} ({$Connection->address}:{$Connection->port})"
               . " from Worker with PID {$UDP_Client_CLI->Process->id} was closed!" . PHP_EOL
            );
         })
         ->on(Events::DatagramWrite, function ($Socket, $Connection) use ($UDP_Client_CLI) {
            // The EVENT_WRITE registration is one-shot: it was already
            // dropped — re-arm only after queueing a new `output`.
            $UDP_Client_CLI->Logger->log(
               info: "Sent {$Connection->written} bytes." . PHP_EOL
            );
         });

      $UDP_Client_CLI->start();
   }
);