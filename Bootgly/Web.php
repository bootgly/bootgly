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


use Bootgly\Web\nodes\HTTP;
use Bootgly\Web\nodes\HTTP\Server\Request;
use Bootgly\Web\nodes\HTTP\Server\Response;
use Bootgly\Web\nodes\HTTP\Server\Router;

use Bootgly\Web\App;
use Bootgly\Web\API;


class Web
{
   public const BOOT_FILE = 'Web.constructor.php';

   // @ nodes
   public HTTP\Server $Server;

   public Request $Request;
   public Response $Response;
   public Router $Router;
   // @ programs
   public App $App;
   public API $API;


   // TODO REFACTOR
   public function __construct ()
   {
      if (@$_SERVER['REDIRECT_URL'] === NULL) {
         if (\PHP_SAPI !== 'cli') {
            echo 'Missing Rewrite!';
         }

         return;
      }
      // * Config
      // Debugger
      Debugger::$debug = false;
      Debugger::$cli = false;
      Debugger::$exit = true;

      // @ Instance
      $_ = [
         'Server' => $this->Server = new HTTP\Server,
         'Request' => $this->Request = &$this->Server->Request,
         'Response' => $this->Response = &$this->Server->Response,
         'Router' => $this->Router = &$this->Server->Router
      ];

      // ---

      // @ Boot
      // Author
      $projects = Project::BOOTGLY_PROJECTS_DIR . self::BOOT_FILE;
      \Bootgly::boot($projects, $_);
      // Consumer
      if (BOOTGLY_DIR !== BOOTGLY_WORKABLES_DIR) {
         // Multi projects
         $projects = Project::PROJECTS_DIR . self::BOOT_FILE;

         \Bootgly::boot($projects, $_);
      }
   }
}
