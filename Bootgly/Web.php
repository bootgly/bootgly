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
      $Server = $this->Server = new HTTP\Server($this);
      $Request = $this->Request = &$Server->Request;
      $Response = $this->Response = &$Server->Response;
      $Router = $this->Router = &$Server->Router;

      // @ Load constructor file + extract vars
      $vars = [
         'Server' => $Server,
         'Request' => $Request,
         'Response' => $Response,
         'Router' => $Router
      ];

      // @ Author
      // TODO
      $projects = Project::BOOTGLY_PROJECTS_DIR . self::BOOT_FILE;
      Bootgly::extract($projects, $vars);
      // @ Consumer
      if (BOOTGLY_DIR !== BOOTGLY_WORKABLES_DIR) {
         // Multi projects || Single project
         $projects = Project::PROJECTS_DIR . self::BOOT_FILE;
         $project = Project::PROJECT_DIR . self::BOOT_FILE;

         Bootgly::extract($projects, $vars) || Bootgly::extract($project, $vars);
      }
   }
}
