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
use Bootgly\CLI\Commands\Arguments;


class Commands
{
   // * Config
   // ...

   // * Data
   protected ? string $banner;
   // # Command
   /** @var array<string,array<Command>|Command> */
   protected array $commands;
   // # Arguments
   protected ? \Closure $Helper;
   // ...

   // * Metadata
   // # Command
   private string $script;
   // ...


   public function __construct (
      public Arguments $Arguments = new Arguments
   )
   {
      // * Config
      // ...

      // * Data
      $this->banner = null;
      $this->commands = [
         'help' => new class extends Command
         {
            public string $name = 'help';
            public string $description = 'Show this help message';


            public function run (array $arguments = [], array $options = []): bool
            {
               return true;
            }
         }
      ];
      $this->Helper = null;

      // * Metadata
      // ...
   }
   public function __set (string $name, mixed $value)
   {
      switch ($name) {
         case 'Helper':
            if ($value instanceof \Closure) {
               $Helper = $value;

               $Helper = $Helper->bindTo($this, $this);

               $this->Helper = $Helper;
            }

            break;
      }
   }

   /**
    * Load commands from a location
    *
    * @param string $location
    * @param object|null $Context
    * @param object|null $Script
    *
    * @return bool
    */
   public function autoload (string $location, ? object $Context = null, ? object $Script = null): bool
   {
      // !?
      $commands = require Path::normalize(\BOOTGLY_ROOT_DIR . $location . '/commands/@.php');
      if ($commands === false || !\is_array($commands) || \count($commands) === 0) {
         return false;
      }

      // @
      foreach ($commands as $namespace => $command) {
         // # Pre command load
         // TODO

         // # Post command load
         $command = require $command;

         if (\is_array($command) === true) {
            $Command = $command['handle'];

            unset($command['handle']);
            $specification = $command;
            $specification['context'] = $Context;

            $this->register($Command, $specification, $Script);
         }
         else if ($command instanceof Command) {
            $command($Context);

            $this->register($command, ['context' => $Context], $Script);
         }
      }

      return true;
   }
   /**
    * Register a command
    * 
    * @param Command|\Closure $Command
    * @param array<string, object|null> $specification
    * @param object|null $Script
    *
    * @return bool
    */
   public function register (Command|\Closure $Command, array $specification = [], ?object $Script = null): bool
   {
      if ($Command instanceof \Closure) {
         /** 
          * @var string|null $name
          * @var string|null $description
          * @var array<string> $arguments
          * @var object|null $context
          */
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
            public function run (array $arguments = [], array $options = []): bool
            {
               return ($this->Command)($arguments, $options);
            }
         };
      }

      if ($Script === null) {
         $Script = $this;
      }

      $this->commands[$Script::class] ??= [
         $this->commands['help']
      ];
      $this->commands[$Script::class][] = $Command;

      return true;
   }

   /**
    * List all commands
    *
    * @param object|null $From
    *
    * @return array<Command>
    */
   public function list (? object $From = null): array
   {
      // ?!
      if ($From === null) {
         return $this->commands;
      }

      $commands = [];

      foreach ($this->commands as $Script => $Commands) {
         if ($Script !== $From::class) {
            continue;  
         }

         foreach ($Commands as $Command) {
            $commands[] = $Command;
         }
      }

      return $commands;
   }
   /**
    * Find a command by its name
    *
    * @param string $command
    * @param object|null $From
    *
    * @return Command|null
    */
   public function find (string $command, ? object $From = null): Command|null
   {
      foreach ($this->list($From) as $Command) {
         if ($Command->name === $command) {
            return $Command;
         }
      }

      return null;
   }
   /**
    * Show the help message
    *
    * @param string|null $message
    * @param object|null $From
    *
    * @return bool
    */
   public function help (? string $message = null, ? object $From = null): bool
   {
      // !
      // * Data
      $banner = $this->banner;
      $Helper = $this->Helper;
      // * Metadata
      // # Command
      $script = $this->script;

      $script = match ($script[0]) {
         '/'     => (new Path($script))->current,
         '.'     => $script,
         default => 'php ' . $script
      };

      // @
      if ($Helper) {
         $Helper($banner, $message, $script, $From);
      }

      return true;
   }

   /**
    * Route a command by its signature to the corresponding command.
    * 
    * The command signature is an array containing the script name, the command name
    * and the command arguments (used to programmaticaly command route).
    * 
    * If no command is provided, the command signature is taken from native PHP CLI arguments.
    * If no command is found, the help message is shown.
    *
    * @param array<string>|null $command
    * @param object|null $From
    *
    * @return bool
    */
   public function route (? array $command = null, ? object $From = null): bool
   {
      // # Command
      // ?!
      $signature = $command ?? $_SERVER['argv'] ?? [];
      // !
      $this->script = $signature[0];
      $name = $signature[1];

      // @
      // ? Verify if a command was provided
      if (\count($signature) < 2) {
         $this->help(null, $From);
         return false;
      }

      // @ Search for the corresponding command
      $Command = $this->find($name, $From);
      if ($Command === null) {
         $this->help("Unknown command: @#Yellow:$name@;", $From);
         return false;
      }

      // ## Arguments
      // !
      // @ Parse arguments and options
      [$arguments, $options] = $this->Arguments->parse($signature);

      // @ Run the command
      $Command->run($arguments, $options);

      return true;
   }
}
