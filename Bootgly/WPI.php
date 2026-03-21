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

use const PHP_SAPI;
use function is_dir;
use function is_file;
use Exception;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\API\Projects;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


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
      // ?
      switch (PHP_SAPI) {
         case 'cli':
            break;
         default:
            // Debugging Vars
            Vars::$debug = false;
            Vars::$exit = true;

            if (@$_SERVER['REDIRECT_URL'] === NULL) {
               throw new Exception('Missing Rewrite!');
            }

            // ---

            // @ Boot WPI for web SAPI
            // @ Discover default WPI project
            $config = @include(Projects::CONSUMER_DIR . '@.php');
            if ($config === false) {
               $config = @include(Projects::AUTHOR_DIR . '@.php');
            }

            $default = $config['default'] ?? null;
            if ($default === null) {
               throw new Exception('No default project configured.');
            }

            // @ Look for WPI.project.php or Web.project.php
            $projectDir = Projects::CONSUMER_DIR . $default . '/';
            if (is_dir($projectDir) === false) {
               $projectDir = Projects::AUTHOR_DIR . $default . '/';
            }

            $autobootFile = $projectDir . 'WPI.project.php';
            if (is_file($autobootFile) === false) {
               $autobootFile = $projectDir . 'Web.project.php';
            }

            if (is_file($autobootFile)) {
               $result = require $autobootFile;
               if ($result instanceof Project) {
                  $result->boot();
               }
            }
      }
   }
}
