<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


use const BOOTGLY_SAPI;
use Exception;

use Bootgly\API\Projects;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


class WPI extends Projects // Web Programming Interface
{
   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   // ...

   // # HTTP
   public HTTP_Server_CLI $Server;
   // # HTTP Server
   public Request $Request;
   public Response $Response;
   public Router $Router;


   public function autoboot (): void
   {
      // ? The Web platform is served exclusively by Bootgly's own CLI HTTP
      //   server (one-way policy) — there is no web SAPI mode.
      switch (BOOTGLY_SAPI) {
         case 'cli':
            break;
         default:
            throw new Exception(
               'Bootgly serves the web through its own CLI HTTP server; '
               . 'web SAPIs are not supported.'
            );
      }
   }
}
