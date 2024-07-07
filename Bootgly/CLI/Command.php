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


abstract class Command
{
   // * Config
   // Signature
   public string $name;
   /** @var array<string> */
   public array $arguments = [];
   // Display
   public string $description;
   public bool $separate;
   public int $group;
   // Runtime
   public ?object $context;

   // * Data
   protected ?\Closure $Command = null;

   // * Metadata
   // ...


   /**
    * Create a new command instance.
    *
    * @param string|null $name The name of the command.
    * @param string|null $description The description of the command.
    * @param array<string>|null $arguments The arguments of the command.
    * @param object|null $context The context of the command.
    * @param \Closure|null $Command The command to run.
    */
   public function __construct
   (
      // * Config
      ?string $name = null,
      ?string $description = null,
      ?array $arguments = null,

      ?object $context = null,

      // * Data
      ?\Closure $Command = null,
   )
   {
      // * Config
      $this->name = $name ?? $this->name;
      $this->description = $description ?? $this->description;
      $this->arguments = $arguments ?? $this->arguments;

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
   public function __invoke (? object $context = null): void
   {
      if ($context !== null) {
         $Closure = function (\Closure $Callback) use ($context) {
            $Callback = $Callback->bindTo($context, $context);
            $Callback();
         };

         $this->context = $Closure;
      }
   }
   /**
    * Run the command with the given arguments and options.
    *
    * @param array<string> $arguments The arguments passed to the command.
    * @param array<string> $options The options passed to the command.
    *
    * @return bool True if the command was successful, false otherwise.
    */
   abstract public function run (array $arguments = [], array $options = []): bool;
}
