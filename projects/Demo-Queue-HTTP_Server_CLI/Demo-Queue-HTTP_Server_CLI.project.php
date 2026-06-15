<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Demo_Queue_HTTP_Server_CLI;


use function getenv;

use const Bootgly\CLI;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Queues;


return new Project(
   // # Project Metadata
   name: 'Demo Queue HTTP Server CLI',
   description: 'Enqueue background jobs from HTTP routes and process them with a queue worker',
   version: '1.0.0',
   author: 'Bootgly',

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      // ! Configure the shared queue messenger — file driver, the same store the worker drains
      Queues::boot(['driver' => 'file']);

      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: match (true) {
         isset($options['i']) => Modes::Interactive,
         isset($options['m']) => Modes::Monitor,
         default => Modes::Daemon
      });
      $HTTP_Server_CLI->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : 8083,
         workers: 1,
      );

      $HTTP_Server_CLI
         ->on(Events::RequestReceived, require __DIR__ . '/router/Queue.SAPI.php')
         ->on(Events::ServerStarted, function ($HTTP_Server_CLI) {
            $Output = CLI->Terminal->Output;
            $port = $HTTP_Server_CLI->port ?? 8083;

            $Output->render('@.;@#green:✓ Queue demo server started@; on @#cyan:http://0.0.0.0:' . $port . '@;@.;');
            $Output->render('  @#Black:Enqueue:@; curl http://127.0.0.1:' . $port . '/email/alice@example.com@.;');
            $Output->render('  @#Black:Process:@; bootgly queue run   (then tail workdata/queue-demo.log)@..;');
         })
         ->on(Events::ServerStopped, function ($HTTP_Server_CLI) {
            CLI->Terminal->Output->render('@.;@#yellow:■ Queue demo server stopped@;@.;');
         });

      $HTTP_Server_CLI->start();
   }
);
