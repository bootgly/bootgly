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


use Bootgly\ACI\Debugger;
use Bootgly\API\Project;
use Bootgly\WPI\modules\HTTP;
use Bootgly\WPI\modules\HTTP\Server\Request;
use Bootgly\WPI\modules\HTTP\Server\Response;
use Bootgly\WPI\modules\HTTP\Server\Router;


class WPI // Web Programming Interface
{
   public const BOOT_FILE = 'WPI.php';

   // @ nodes
   public HTTP\Server $Server;

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
      // Debugger
      Debugger::$debug = true;
      Debugger::$cli = false;
      Debugger::$exit = true;

      // @ Instance
      // Bootgly
      $Project = \Bootgly::$Project;
      // Web
      $Server = $this->Server = new HTTP\Server($this);

      $Request = self::$Request = &$Server::$Request;
      $Response = self::$Response = &$Server::$Response;
      $Router = self::$Router = &$Server::$Router;

      // ---

      // @ Boot WPI
      // Author
      @include(Project::BOOTGLY_PROJECTS_DIR . 'Bootgly/' . self::BOOT_FILE);
      // Consumer
      if (BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
         // Multi projects
         @include(Project::PROJECTS_DIR . 'Bootgly/' . self::BOOT_FILE);
      }
   }
}
