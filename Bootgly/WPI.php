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
use function basename;
use function is_dir;
use function is_file;
use Exception;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\API\Projects;
use Bootgly\API\Projects\Project;
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
      // ?
      switch (PHP_SAPI) {
         case 'cli':
            break;
         default:
            // Debugging Vars
            Vars::$debug = false;
            Vars::$exit = true;

            // ---

            // @ Boot WPI for web SAPI
            // @ Pick the default WPI project (flagged `default`, not by file order)
            $default = Projects::pick('WPI');
            if ($default === null) {
               throw new Exception('No WPI projects configured.');
            }
            // ? Jail the web SAPI entrypoint against the security boundary
            if (Projects::validate($default) === false) {
               throw new Exception('Invalid default WPI project.');
            }

            $leaf = basename($default);
            $projectDir = Projects::CONSUMER_DIR . $default . '/';
            if (is_dir($projectDir) === false) {
               $projectDir = Projects::AUTHOR_DIR . $default . '/';
            }

            $projectFile = $projectDir . $leaf . '.project.php';

            if (is_file($projectFile)) {
               $result = require $projectFile;
               if ($result instanceof Project) {
                  $result->boot();
               }
            }
      }
   }
}
