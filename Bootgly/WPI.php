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

use Bootgly\WPI\Nodes\HTTP_Server_ as Server;
use Bootgly\WPI\Nodes\HTTP_Server_\Request;
use Bootgly\WPI\Nodes\HTTP_Server_\Response;
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

   // @ HTTP
   // @ Nodes
   public Server $Server;

   public static Request $Request;
   public static Response $Response;
   public static Router $Router;


   public function __construct ()
   {
      // TODO remove or modify
      if (@$_SERVER['REDIRECT_URL'] === NULL) {
         if (\PHP_SAPI !== 'cli') {
            throw new \Exception('Missing Rewrite!');
         }

         return;
      }

      // * Config
      // ...

      // * Data
      // ...

      // * Metadata
      // ...

      // @
      // Debugging Vars
      Vars::$debug = false;
      Vars::$exit = true;

      // @ Instance variables
      // HTTP
      $Server = $this->Server = new Server($this);

      $Request = self::$Request = &$Server::$Request;
      $Response = self::$Response = &$Server::$Response;
      $Router = self::$Router = &$Server::$Router;

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
