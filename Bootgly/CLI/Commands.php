<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


class Commands
{
   // * Config
   // ...

   // * Data
   public array $args;
   public array $commands;
   protected ? \Closure $Helper;
   // ...

   // * Meta
   // ...


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      $this->args = $_SERVER['argv'] ?? [];
      $this->commands = [
         'help' => new class extends Command
         {
            public string $name = 'help';
            public string $description = 'Show this help message';


            public function run (array $arguments, array $options) : bool
            {
               return true;
            }
         }
      ];
      $this->Helper = null;

      // * Meta
      // ...
   }

   public function register (Commanding|Command|\Closure $Command, string $name = '', string $description = '') : bool
   {
      if ($Command instanceof Command || $Command instanceof Commanding) {
         $this->commands[] = $Command;
     } elseif ($Command instanceof \Closure) {
         // TODO use Closure bind
         $Command = new class ($Command, $name, $description) extends Command
         {
            public \Closure $Command;

            public string $name;
            public string $description;


            public function __construct (\Closure $Command, string $name, string $description)
            {
               $this->Command = $Command;

               $this->name = $name;
               $this->description = $description;
            }
            public function run (array $arguments, array $options) : bool
            {
               return ($this->Command)($arguments, $options);
            }
         };
         $this->commands[] = $Command;
     }

      return true;
   }

   public function route () : bool
   {
      // Argments
      $args = $this->args;

      // @ Verify if a command was provided
      if (count($args) < 2) {
         $this->help();
         return false;
      }

      // Command
      // @ Get the name of the command to run
      $name = $args[1];

      // @ Search for the corresponding command
      $Command = $this->find($name);
      if ($Command === null) {
         echo "Unknown command: $name" . PHP_EOL;
         $this->help();
         return false;
      }
      if ($name === 'help') {
         $this->help();
         return false;
      }

      // @ Remove the command name from the arguments
      $args = array_slice($args, 2);

      // TODO move to Commands\Arguments?
      // @ Process options and argments
      $options = [];
      $arguments = [];

      foreach ($args as $arg) {
         if (strpos($arg, '--') === 0) {
            // Option (--op1[=val1])
            $option_parts = explode('=', substr($arg, 2), 2);
            $option_name = $option_parts[0];
            $option_value = isSet($option_parts[1]) ? $option_parts[1] : true;

            $options[$option_name] = $option_value;
         } elseif (strpos($arg, '-') === 0) {
            // Short Option (-opt1)
            $option_names = str_split(substr($arg, 1));

            foreach ($option_names as $option_name) {
               $options[$option_name] = true;
            }
         } else {
            // Argument
            $arguments[] = $arg;
         }
      }

      // @ Run the command
      $Command->run($arguments, $options);

      return true;
   }

   public function find (string $name) : Commanding|Command|null
   {
      foreach ($this->commands as $Command) {
         if ($Command->name === $name) {
            return $Command;
         }
      }

      return null;
   }

   public function help (? \Closure $Helper = null) : bool
   {
      if ($Helper !== null) {
         $Helper = $Helper->bindTo($this, $this);
         $this->Helper = $Helper;
         return true;
      }

      $Helper = $this->Helper;

      if ($Helper) {
         $Helper();
      }

      return true;
   }
}
