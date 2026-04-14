<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use function str_starts_with;
use const BOOTGLY_VERSION;
use const PHP_VERSION;
use Closure;

use Bootgly\API\Environment\Agent;
use const Bootgly\CLI;
use Bootgly\CLI\Command;
use Bootgly\CLI\Commands\Middleware;


class VersionFooterMiddleware implements Middleware
{
   public function process (Command $Command, array $arguments, array $options, Closure $next): bool
   {
      // @ Run the command
      $result = $next($Command, $arguments, $options);

      // @ Skip footer for AI agents
      $Agent = Agent::detect();
      if ($Agent->detected) {
         return $result;
      }

      // @ Render the version footer (only for built-in commands)
      if (str_starts_with($Command::class, 'Bootgly\\commands\\')) {
         $Output = CLI->Terminal->Output;

         $PHP = PHP_VERSION;
         $Bootgly = BOOTGLY_VERSION;

         $Output->render(
            "@#Black:Bootgly @_:v{$Bootgly} @; | @#Black:PHP @_:v{$PHP} @;@..;"
         );
      }

      // :
      return $result;
   }
}
