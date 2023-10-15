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


use Bootgly\ABI\Debugging\Code\Vars;

use Bootgly\API\Project;

use Bootgly\WPI\Nodes\HTTP\Server\Bridge as Server;
use Bootgly\WPI\Nodes\HTTP\Server\Bridge\Request;
use Bootgly\WPI\Nodes\HTTP\Server\Bridge\Response;
use Bootgly\WPI\Modules\HTTP\Server\Router;


class WPI // Web Programming Interface
{
   public const BOOT_FILE = 'WPI.php';

   // HTTP
   // @ nodes
   public Server $Server;

   public static Request $Request;
   public static Response $Response;
   public static Router $Router;


   public function __construct ()
   {
      // TODO remove or modify
      if (@$_SERVER['REDIRECT_URL'] === NULL) {
         if (\PHP_SAPI !== 'cli') {
            echo 'Missing Rewrite!';
         }

         return;
      }
      // * Config
      // Debugging Vars
      Vars::$debug = false;
      Vars::$exit = true;

      // @ Instance
      // Bootgly
      $Project = \Bootgly::$Project;
      // Bootgly\WPI
      // HTTP
      $Server = $this->Server = new Server($this);

      $Request = self::$Request = &$Server::$Request;
      $Response = self::$Response = &$Server::$Response;
      $Router = self::$Router = &$Server::$Router;

      // ---

      // @ Boot WPI
      // Author
      @include(Project::AUTHOR_DIR . 'Bootgly/' . self::BOOT_FILE);
      // Consumer
      if (BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
         // Multi projects
         @include(Project::CONSUMER_DIR . 'Bootgly/' . self::BOOT_FILE);
      }
   }
}
