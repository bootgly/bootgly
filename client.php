<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


require_once '@/autoload.php';


use Bootgly\Web\TCP;
use Bootgly\OS\Process\Timer;


$TCPClient = new TCP\Client;
$TCPClient->configure(
   host: '127.0.0.1',
   port: 8080,
   workers: 1
);
$TCPClient->on(
   // @ on Worker instance
   instance: function ($Client) {
      // @ Connect to Server
      $Socket = $Client->connect();

      if ($Socket) {
         // @ Call Event loop
         #if ($Socket) {
         #   $this->Client::$Event->add($Socket, Select::EVENT_CONNECT, true);
         #}

         $Client::$Event->loop();
      }
   },
   // @ on Connection connect
   connect: function ($Connection) {
      // @ Set Connection expiration
      Timer::add(
         interval: 10,
         handler: function ($Connection) {
            $Connection->close();
         },
         args: [$Connection],
         persistent: false
      );

      // @ Add Connection Data read to Event loop
      TCP\Client::$Event->add($Connection->Socket, TCP\Client::$Event::EVENT_WRITE, $Connection);
   },
   // @ on Package write / read
   write: null,
   read: null,
);
$TCPClient->start();