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
use function getenv;
use function is_scalar;
use AllowDynamicProperties;
use RuntimeException;

use Bootgly\API\Environment\Configs\Config\Types;


/**
 * Mutable node in a scope configuration tree.
 *
 * Nodes are created by object navigation (`$Config->Database->Host`) and can
 * hold one scalar/config value with `bind()`. Child navigation returns the
 * parent after binding, which enables fluent config declarations.
 */
#[AllowDynamicProperties]
class Config
{
   // * Config
   public readonly string $scope;

   // * Data
   protected mixed $value = null;
   /** @var array<string,self> */
   protected array $children = [];
   /** @var null|array<string,string> */
   private static null|array $environment = null;

   // * Metadata
   public private(set) null|string $name = null;
   public private(set) null|self $parent = null;
   private null|self $last = null;


   /**
    * Create a root config node for one scope.
    */
   public function __construct (string $scope)
   {
      // * Config
      $this->scope = $scope;
   }

   /**
    * Return or create a child node by object navigation.
    *
    * Reads intentionally create missing nodes; external lookup by string path
    * is not supported by `Configs::get()`.
    */
   public function __get (string $name): self
   {
      if (isset($this->children[$name]) === false) {
         $Child = new self($this->scope);
         $Child->name = $name;
         $Child->parent = $this;

         $this->children[$name] = $Child;
      }

      $this->last = $this->children[$name];

      return $this->children[$name];
   }

   /**
    * Deep-clone child nodes and reconnect parent pointers.
    */
   public function __clone (): void
   {
      $children = [];

      foreach ($this->children as $name => $Child) {
         $Clone = clone $Child;
         $Clone->parent = $this;

         $children[$name] = $Clone;
      }

      $this->children = $children;
      $this->last = null;
   }

   /**
      * Swap the temporary local `.env` context used by `bind()`.
      *
      * This is used by `Configs::include()` around `require` and should be
      * restored with the returned previous value.
      *
    * @param null|array<string,string> $variables
    * @return null|array<string,string>
    */
   public static function swap (null|array $variables): null|array
   {
      $previous = self::$environment;
      self::$environment = $variables;

      return $previous;
   }

   // # Binding
   /**
    * Bind this node to a runtime env key, local `.env` key or default value.
    *
    * Resolution order is process env, local config env, then `$default`. When
    * `$required` is true, missing or empty values throw and defaults are not
    * used. `$cast` applies strict scalar parsing after resolution.
    *
    * @throws RuntimeException when `$required` is true and the value is absent.
    */
   public function bind (string $key = '', mixed $default = null, null|Types $cast = null, bool $required = false): self
   {
      if ($key !== '') {
         $env = getenv($key);

         if ($env !== false) {
            $this->value = $env;
         }
         else if (self::$environment !== null && array_key_exists($key, self::$environment)) {
            $this->value = self::$environment[$key];
         }
         else if ($required) {
            $this->fail($key);
         }
         else {
            $this->value = $default;
         }
      }
      else if ($required) {
         $this->fail($this->name ?? $this->scope);
      }
      else {
         $this->value = $default;
      }

      if ($required && $this->value === '') {
         $this->fail($key);
      }

      if ($cast !== null && is_scalar($this->value)) {
         $this->value = $cast->cast($this->value);
      }

      return $this->parent ?? $this;
   }

   /**
    * Clear this node and throw a required-config failure.
    *
    * The missing value is never included in the exception message.
    *
    * @throws RuntimeException always.
    */
   protected function fail (string $key): never
   {
      $this->value = null;

      throw new RuntimeException("Required config value is missing: {$key}");
   }

   // # Accessing
   /**
    * Return this node's bound value, or `null` when unbound.
    */
   public function get (): mixed
   {
      return $this->value;
   }

   // # Navigating
   /**
    * Move to the parent node, or stay on root.
    */
   public function up (): self
   {
      return $this->parent ?? $this;
   }

   /**
    * Move to the last child navigated from this node, or stay on this node.
    */
   public function down (): self
   {
      return $this->last ?? $this;
   }

   // # Merging
   /**
    * Merge a base config tree under this config tree.
    *
    * Existing project/current values win; missing children and null values are
    * filled from `$Base`.
    */
   public function merge (self $Base): void
   {
      foreach ($Base->children as $name => $Child) {
         if (isset($this->children[$name])) {
            $this->children[$name]->merge($Child);
         }
         else {
            $Clone = clone $Child;
            $Clone->parent = $this;

            $this->children[$name] = $Clone;
         }
      }

      if ($this->value === null && $Base->value !== null) {
         $this->value = $Base->value;
      }
   }
}
