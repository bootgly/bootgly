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


use parallel\Runtime;
use parallel\Channel;

use Bootgly\Web\TCP;
#use Bootgly\Web\HTTP;


$thread = function (int $id, Channel $Channel, string $host, int $port) {
   $TCPClient = new TCP\Client;
   $TCPClient->configure(
      $host,
      $port,
   );

   if ($TCPClient->connect() === false) {
      $Channel->send($TCPClient->error);
      return false;
   }

   $started = time();
   $duration = 10;

   while (time() - $started < $duration) {
      $TCPClient->Data->write("GET / HTTP/1.1\r\nHost: $host:$port\r\n\r\n");
   }

   $TCPClient->close();

   $Channel->send([
      'writes' => $TCPClient->Data->writes,
      'written' => $TCPClient->Data->written,
   ]);
};

try {
   $duration = 10;

   $host = '0.0.0.0';
   $port = 8080;

   // channel where the date will be sharead
   $Channel = new Channel();
   // args that will be sent to $thread
   $args = [];
   $args[0] = null;
   $args[1] = $Channel;
   $args[2] = $host;
   $args[3] = $port;

   $threads = 4;

   echo "Running {$duration}s test @ http://$host:$port" . PHP_EOL;


   // @ Creating threads
   $runtimes = [];
   for ($index = 0; $index < $threads; $index++) {
      $runtimes[$index] = new Runtime(HOME_DIR . '@/autoload.php');
   }
   // @ Run all threads
   $futures = [];
   for ($index = 0; $index < $threads; $index++) {
      $args[0] = $index;
      $futures[$index] = $runtimes[$index]->run($thread, $args);
   }
   // @ Receive messages from Channel
   $messages = [];
   for ($index = 0; $index < $threads; $index++) {
      $messages[$index] = $Channel->recv();
   }
   $Channel->close();
   // @ Kill all threads
   for ($index = 0; $index < $threads; $index++) {
      $runtimes[$index]->kill();
   }

   // @ Set results
   $error = false;
   $requests = 0;
   $written = 0;
   foreach ($messages as $data) {
      if (@$data['code'] && @$data['message']) {
         $error = $data;
         break;
      }

      $requests += $data['writes'];
      $written += round($data['written'] / 1024 / 1024, 2);
   }

   // @ Show results
   if ($error) {
      echo "Unable to connect to $host:$port: " . $error['message'] . PHP_EOL;
      return false;
   }

   echo "  {$threads} threads 1 connections" . PHP_EOL;

   echo "  $requests requests in {$duration}s, {$written}MB written" . PHP_EOL;
   echo "\nRequests/sec: " . $requests / $duration . PHP_EOL;
} catch (\Throwable $Throwable) {
   echo "\Throwable:", $Throwable->getMessage();
}
