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
   // @ nodes
   public HTTP\Server $Server;

   public Request $Request;
   public Response $Response;
   public Router $Router;
   // @ programs
   public App $App;
   public API $API;


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
      $this->Server = new HTTP\Server($this);
   }

   public function construct () : bool
   {
      // @ Extract variables
      // TODO extract dinamically
      $Server = $this->Server;

      $Request = $this->Request = &$Server->Request;
      $Response = $this->Response = &$Server->Response;
      $Router = $this->Router = &$Server->Router;

      // @ Load CLI constructor
      $file = 'Web.constructor.php';
      $vars = [
         'Server' => $Server,
         'Request' => $Request,
         'Response' => $Response,
         'Router' => $Router
      ];

      // Multi projects || Single project
      $projects = Project::PROJECTS_DIR . $file;
      $project = Project::PROJECT_DIR . $file;

      return Bootgly::extract($projects, $vars) || Bootgly::extract($project, $vars);
   }
}
