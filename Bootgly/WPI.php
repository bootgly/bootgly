<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


use Bootgly\ABI\Debugging\Data\Vars;

use Bootgly\API\Projects;

use Bootgly\WPI\Modules\HTTP\Server;
use Bootgly\WPI\Modules\HTTP\Server\Request;
use Bootgly\WPI\Modules\HTTP\Server\Response;
use Bootgly\WPI\Modules\HTTP\Server\Router;


class WPI extends Projects // Web Programming Interface
{
   public const BOOT_FILE = 'WPI.boot.php';

   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   // ...

   // # HTTP
   public Server $Server;
   // # HTTP Server
   public Request $Request;
   public Response $Response;
   public Router $Router;


   public function autoboot (): void
   {
      // ?
      switch (\PHP_SAPI) {
         case 'cli':
            break;
         default:
            // Debugging Vars
            Vars::$debug = false;
            Vars::$exit = true;

            if (@$_SERVER['REDIRECT_URL'] === NULL) {
               throw new \Exception('Missing Rewrite!');
            }
      }

      // ---

      // @ Boot WPI
      // Consumer
      if (BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
         (@include Projects::CONSUMER_DIR . 'Bootgly/' . self::BOOT_FILE);
      }
      // Author
      require(Projects::AUTHOR_DIR . 'Bootgly/' . self::BOOT_FILE);
   }
}
