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


use Closure;


abstract class Command
{
   // * Config
   // # Signature
   /** @var array<string> */
   public array $arguments = [];

   /** @var array<string,array<string>> */
   public array $options = [
      // Global options
      'Increase the verbosity of the command' => ['-v', '-vv', '-vvv'],
      // Local options
      // ...
   ];
   // # Display
   public bool $separate;
   public int $group;
   public int $verbosity = 0;
   // # Runtime
   public null|object $context;
   public null|string $input = null;

   // * Data
   // # Display
   public string $description;
   // # Signature
   public string $name;
   // # Runtime
   protected null|Closure $Command = null;

   // * Metadata
   public string $script {
      get => $this->script ?? '';
      set => $this->script ??= $value;
   }


   /**
    * Define a new command instance.
    *
    * @param null|string $name The name of the command.
    * @param null|string $description The description of the command.
    * @param null|array<string> $arguments The arguments of the command.
    * @param null|array<string,array<string>> $options The options of the command.
    * @param null|object $context The context of the command.
    * @param null|Closure $Command The command to run.
    */
   public function __construct
   (
      // * Config
      null|string $name = null,
      null|string $description = null,
      null|array $arguments = null,
      null|array $options = null,

      null|object $context = null,

      // * Data
      null|Closure $Command = null,
   )
   {
      // * Config
      $this->name = $name ?? $this->name;
      $this->description = $description ?? $this->description;
      $this->arguments = $arguments ?? $this->arguments;
      $this->options = array_merge($this->options, $options ?? []);

      $this->context = $context;
      // * Data
      $this->Command = $Command;

      // @
      if ($context !== null) {
         $this($context);
      }

      if ($Command !== null) {
         $this->Command = $Command->bindTo($this, $this);
      }
   }
   /**
    * Set the context of the command.
    *
    * @param null|object $context The context of the command.
    *
    * @return void
    */
   public function __invoke (null|object $context = null): void
   {
      if ($context !== null) {
         $input = $this->input; // pipe the input to the context
         $Closure = function (Closure $Callback)
         use ($context, $input) {
            $Callback = $Callback->bindTo($context, $context);
            $Callback($input);
         };

         $this->context = $Closure;
      }
   }

   /**
    * Run the command with the given arguments and options.
    *
    * @param array<string> $arguments The arguments passed to the command.
    * @param array<string,bool|int|string> $options The options passed to the command.
    *
    * @return bool True if the command was successful, false otherwise.
    */
   abstract public function run (array $arguments = [], array $options = []): bool;
}
