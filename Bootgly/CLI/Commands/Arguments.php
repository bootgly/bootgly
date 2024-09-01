<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Commands;


use function explode;
use function strpos;
use function str_split;
use function substr;


class Arguments
{
   /**
    * Parse the command arguments to extract options and arguments
    * 
    * @param array<string> $args 
    *
    * @return array<array<string>|array<string,bool|int|string>|string>
    */
   public function parse (array $args): array
   {
      // !
      $script = '';
      $arguments = [];
      $options = [];

      // @
      foreach ($args as $index => $arg) {
         if ($index === 0) { // script like `/usr/local/bin/bootgly`
            $script = $arg;
            continue;
         }

         if (strpos($arg, '--') === 0) {
            // Option (--op1[=val1])
            $option_parts = explode('=', substr($arg, 2), 2);
            $option_name = $option_parts[0];
            $option_value = $option_parts[1] ?? true;

            $options[$option_name] = $option_value;
         }
         elseif (strpos($arg, '-') === 0) {
            // Short Option (-opt1)
            $option_names = str_split(substr($arg, 1));

            foreach ($option_names as $option_name) {
               $options[$option_name] ??= 0;
               $options[$option_name] += 1;
            }
         }
         else {
            // Argument
            $arguments[] = $arg;
         }
      }

      return [$script, $arguments, $options];
   }
}
