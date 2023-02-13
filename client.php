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


$TCPClient = new TCP\Client(TCP\Client::MODE_MONITOR);
$TCPClient->configure(
   host: '127.0.0.1',
   port: 8080,
   workers: 1
);
// This runs a Benchmark for 10 seconds with 1 Worker \
// type stats command in Server to get stats of writes
$TCPClient->on(
   // on Worker instance
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
   // on Connection connect
   connect: function ($Socket, $Connection) {
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
      $Connection::$output = "GET / HTTP/1.0\r\n\r\n";

      // @ Add Package write to Event loop
      TCP\Client::$Event->add($Socket, TCP\Client::$Event::EVENT_WRITE, $Connection);
   },
   // on Package write / read
   write: function ($Socket, $Connection, $Package) {
      // @ Add Package read to Event loop
      TCP\Client::$Event->add($Socket, TCP\Client::$Event::EVENT_READ, $Connection);
   },
   read: null,
);
$TCPClient->start();