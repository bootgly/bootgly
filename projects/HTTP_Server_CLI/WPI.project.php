<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\HTTP_Server_CLI;


use function getenv;

use Bootgly\API\Projects\Project;
use Bootgly\WPI\Endpoints\Servers\Modes;
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return new Project(
   name: 'HTTP Server CLI',
   description: 'HTTP server demo with static/dynamic routing and catch-all 404',
   version: '0.1.0',
   author: 'Rodrigo Vieira',

   boot: function (array $arguments = [], array $options = []): void
   {
      $Server = new HTTP_Server_CLI(Mode: match (true) {
         isSet($options['i'])
            => Modes::Interactive,
         isSet($options['m'])
            => Modes::Monitor,
         default
            => Modes::Daemon
      });
      $Server->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : 8082,
         workers: 11,
      );
      #$Server->handle(require __DIR__ . '/router/routes/Middlewares.routes.php');
      $Server->handle(fn ($Request, $Response) => $Response(body: 'Hello, World!'));
      $Server->start();
   }
);
