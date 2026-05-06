<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment\Configs;


use function array_key_exists;

use Bootgly\API\Environment\Configs\Config;


/**
 * Registry of loaded config scopes.
 *
 * Scope names are keyed by the root `Config::$scope` value.
 */
class Scopes
{
   // * Data
   /** @var array<string,Config> */
   private array $scopes = [];

   // * Metadata
   // ...


   /**
    * Register or replace a loaded scope.
    */
   public function add (Config $Config): void
   {
      $this->scopes[$Config->scope] = $Config;
   }

   /**
    * Fetch a loaded scope by name.
    */
   public function get (string $name): ?Config
   {
      return $this->scopes[$name] ?? null;
   }

   /**
    * Check whether a scope is already registered.
    */
   public function check (string $name): bool
   {
      return array_key_exists($name, $this->scopes);
   }

   /**
      * Return all registered scopes keyed by name.
      *
    * @return array<string,Config>
    */
   public function list (): array
   {
      return $this->scopes;
   }
}
