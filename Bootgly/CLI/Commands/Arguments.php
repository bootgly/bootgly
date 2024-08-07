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


class Arguments
{
   /**
    * Parse the command arguments to extract options and arguments
    * 
    * @param array<string> $args 
    *
    * @return array<array<string>|array<string,bool|string>>
    */
   public function parse (array $args): array
   {
      // !
      $options = [];
      $arguments = [];

      // @ Remove the command from the arguments
      $args = \array_slice($args, 2);

      // @
      foreach ($args as $arg) {
         if (\strpos($arg, '--') === 0) {
            // Option (--op1[=val1])
            $option_parts = \explode('=', \substr($arg, 2), 2);
            $option_name = $option_parts[0];
            $option_value = isSet($option_parts[1]) ? $option_parts[1] : true;

            $options[$option_name] = $option_value;
         }
         elseif (\strpos($arg, '-') === 0) {
            // Short Option (-opt1)
            $option_names = \str_split(\substr($arg, 1));

            foreach ($option_names as $option_name) {
               $options[$option_name] = true;
            }
         }
         else {
            // Argument
            $arguments[] = $arg;
         }
      }

      return [$arguments, $options];
   }
}
