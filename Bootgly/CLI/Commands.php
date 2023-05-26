<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


use Bootgly\CLI\components\Header;
use Closure;
use Bootgly\templates\ANSI\Escaped;


class Commands
{
   // * Config
   // ...

   // * Data
   public array $args;
   public array $commands;
   // ...

   // * Meta
   // ...


   public function __construct ()
   {
      // * Data
      $this->args = $_SERVER['argv'] ?? [];
      $this->commands = [
         'help' => new class extends Command
         {
            public string $name = 'help';
            public string $description = 'Show this help message';


            public function run (array $arguments, array $options) : bool
            {
               // TODO
               return true;
            }
         }
      ];
   }

   public function register (Commanding|Command|Closure $Command, string $name = '', string $description = '') : bool
   {
      if ($Command instanceof Command || $Command instanceof Commanding) {
         $this->commands[] = $Command;
     } elseif ($Command instanceof Closure) {
         $Command = new class ($Command, $name, $description) extends Command
         {
            public Closure $Command;

            public string $name;
            public string $description;


            public function __construct (Closure $Command, string $name, string $description)
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
      $values = $this->args;

      // @ Verify if a command was provided
      if (count($values) < 2) {
         $this->help();
         return false;
      }

      // Command
      // @ Get the name of the command to run
      $name = $values[1];

      // @ Search for the corresponding command
      $Command = $this->find($name);
      if ($Command === null) {
         echo "Unknown command: $name" . PHP_EOL;
         $this->help();
         return false;
      }

      // @ Remove the command name from the arguments
      $args = array_slice($values, 2);

      // @ Process options and argments
      $options = [];
      $arguments = [];

      foreach ($args as $arg) {
         if (strpos($arg, '--') === 0) {
            // Option (--op1[=val1])
            $optionParts = explode('=', substr($arg, 2), 2);
            $optionName = $optionParts[0];
            $optionValue = isSet($optionParts[1]) ? $optionParts[1] : true;

            $options[$optionName] = $optionValue;
         } elseif (strpos($arg, '-') === 0) {
            // Short Option (-opt1)
            $optionNames = str_split(substr($arg, 1));

            foreach ($optionNames as $optionName) {
               $options[$optionName] = true;
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

   private function help (bool $scripting = true) : void
   {
      $help = '@.;';

      if ($scripting) {
         $Header = new Header;

         $help .= $Header->generate(word: 'Bootgly', inline: true);
         $help .= '@.;Usage: php ' . $this->args[0] . ' [command] @..;';
         $help .= 'Available commands:';
      }

      $help .= '@.;======================================================================' . PHP_EOL;

      foreach ($this->commands as $Command) {
         // TODO with str_pad
         $help .= '@:i: `' . $Command->name . '` @; = ';
         $help .= $Command->description . PHP_EOL;
      }

      $help .= '======================================================================@.;' . PHP_EOL;

      echo Escaped::render($help);
   }
}
