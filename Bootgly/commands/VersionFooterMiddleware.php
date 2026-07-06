<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const BOOTGLY_VERSION;
use const PHP_VERSION;
use function is_string;
use function str_starts_with;
use function strtolower;
use Closure;

use const Bootgly\CLI;
use Bootgly\API\Environment\Agent;
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

      // @ Skip footer in machine-readable output mode (e.g. --format=json)
      $format = $options['format'] ?? null;
      if (is_string($format) && strtolower($format) === 'json') {
         return $result;
      }

      // @ Render the version footer (only for built-in commands)
      if (str_starts_with($Command::class, 'Bootgly\\commands\\')) {
         $Output = CLI->Terminal->Output;

         $PHP = PHP_VERSION;
         $Bootgly = BOOTGLY_VERSION;

         $Output->render(
            "@.;@#Black:Bootgly @_:v{$Bootgly} @; | @#Black:PHP @_:v{$PHP} @;@..;"
         );
      }

      // :
      return $result;
   }
}
