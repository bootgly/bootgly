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


use Bootgly\ABI\Data\__String\Path;


class Commands
{
   // * Config
   // ...

   // * Data
   public array $args;
   public array $commands;
   protected ? \Closure $Helper;
   // ...

   // * Metadata
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


            public function run (array $arguments = [], array $options = []) : bool
            {
               return true;
            }
         }
      ];
      $this->Helper = null;

      // * Metadata
      // ...
   }

   public function autoload (string $location, ? object $context = null) : bool
   {
      $commands = require Path::normalize(\BOOTGLY_ROOT_DIR . $location . '/commands/@.php');

      if ($commands === false || !is_array($commands) || count($commands) === 0) {
         return false;
      }

      foreach ($commands as $command) {
         $command = require $command;

         if (\is_array($command) === true) {
            $Command = $command['handle'];

            unset($command['handle']);
            $specification = $command;
            $specification['context'] = $context;

            $this->register($Command, $specification);
         }
         else if ($command instanceof Command) {
            $command($context);

            $this->register($command, ['context' => $context]);
         }
      }

      return true;
   }
   public function register (Command|\Closure $Command, array $specification = []) : bool
   {
      if ($Command instanceof Command) {
         $this->commands[] = $Command;
      }
      elseif ($Command instanceof \Closure) {
         [
            'name' => $name,
            'description' => $description,
            'arguments' => $arguments,
            'context' => $context
         ] = $specification;

         $Command = new class (
            // * Config
            $name, $description, $arguments, $context,
            // * Data
            $Command
         ) extends Command
         {
            public function run (array $arguments = [], array $options = []) : bool
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
         }
         elseif (strpos($arg, '-') === 0) {
            // Short Option (-opt1)
            $option_names = str_split(substr($arg, 1));

            foreach ($option_names as $option_name) {
               $options[$option_name] = true;
            }
         }
         else {
            // Argument
            $arguments[] = $arg;
         }
      }

      // @ Run the command
      $Command->run($arguments, $options);

      return true;
   }

   public function find (string $name) : Command|null
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
