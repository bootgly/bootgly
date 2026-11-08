<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment\Configs;


use function array_key_exists;
use function array_slice;
use function count;
use function implode;
use function is_string;
use function preg_match;
use function property_exists;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use Bootgly\ADI\Validation;
use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validation\Path;
use Bootgly\API\Environment\Configs\Schema\Failure;
use Bootgly\API\Environment\Configs\Schema\Field;


/**
 * The declared shape of one config scope.
 *
 * A schema lists every key of a scope as a path of node names
 * (`Connections.MySQL.Port`) mapped to a `Field`. `build()` resolves each
 * field exactly like `Config::bind()` — process environment, scope-local
 * `.env`, then the default; strict `Types` casts; required fields ignore the
 * default — and runs the fields' ADI validation rules, but instead of
 * stopping at the first problem it collects **every** failure of the scope
 * and throws them as one `Schema\Failure` report. A `<scope>.Config.php`
 * file returns `new Schema(...)->build()`, so an invalid scope fails its
 * load before the application uses it.
 *
 * The report never contains a configured value.
 */
class Schema
{
   // * Config
   /**
    * The scope the built `Config` root declares.
    */
   public private(set) string $scope;
   /**
    * The declared fields, keyed by node path, in declaration order.
    *
    * @var array<string,Field>
    */
   public private(set) array $Fields;

   // * Data
   // ...

   // * Metadata
   /**
    * The node names of each field path.
    *
    * @var array<string,array<int,string>>
    */
   private array $paths = [];


   /**
    * @param string $scope The scope name (the `Config::$scope` of the built root).
    * @param array<string,Field> $Fields The fields, keyed by a path of node names
    *   (`Host`, `Connections.MySQL.Port`). Each name is a PHP identifier that is
    *   not a `Config` property (`scope`, `name`, `parent`, …); no wildcards.
    *
    * @throws InvalidArgumentException When the scope is not a valid scope name
    *   (`[A-Za-z0-9_-]+`), a key or a field is invalid, or one field path is the
    *   container of another.
    */
   public function __construct (string $scope, array $Fields)
   {
      // ? The scope names a config directory (`Configs` accepts nothing else)
      if (preg_match('/\A[A-Za-z0-9_-]+\z/', $scope) !== 1) {
         throw new InvalidArgumentException('Invalid config schema scope.');
      }

      // * Config
      $this->scope = $scope;

      // @@ Parse every path
      $paths = [];
      /** @var array<mixed,mixed> $raw Runtime-checked below */
      $raw = $Fields;
      foreach ($raw as $key => $Field) {
         // ?
         if (is_string($key) === false || $key === '') {
            throw new InvalidArgumentException('Config schema keys must be non-empty node paths.');
         }
         if ($Field instanceof Field === false) {
            throw new InvalidArgumentException("Config schema key {$key} must map to a Field.");
         }

         $Path = new Path($key);

         if ($Path->wildcard) {
            throw new InvalidArgumentException("Config schema key {$key} cannot hold a wildcard.");
         }

         $names = [];
         foreach ($Path->segments as $segment) {
            $segment = (string) $segment;

            if (
               preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $segment) !== 1
               || property_exists(Config::class, $segment)
            ) {
               throw new InvalidArgumentException("Config schema key {$key} has an invalid node name.");
            }

            $names[] = $segment;
         }

         $paths[$key] = $names;
      }

      // ? A node holds a value or children, never both
      foreach ($paths as $key => $names) {
         for ($depth = 1; $depth < count($names); $depth++) {
            $prefix = implode('.', array_slice($names, 0, $depth));

            if (array_key_exists($prefix, $paths)) {
               throw new InvalidArgumentException("Config schema key {$prefix} cannot hold both a value and {$key}.");
            }
         }
      }

      // * Config
      $this->Fields = $Fields;

      // * Metadata
      $this->paths = $paths;
   }

   /**
    * Set a value at a node path inside a nested array.
    *
    * @param array<string,mixed> $node
    * @param array<int,string> $names
    *
    * @return array<string,mixed>
    */
   private function place (array $node, array $names, int $index, mixed $value): array
   {
      $name = $names[$index];

      // ?: The leaf
      if ($index === count($names) - 1) {
         $node[$name] = $value;

         return $node;
      }

      // @ The container
      $child = $node[$name] ?? [];
      /** @var array<string,mixed> $child Containers only hold arrays (no value-and-children paths) */
      $node[$name] = $this->place($child, $names, $index + 1, $value);

      // :
      return $node;
   }

   /**
    * Resolve every field into a new `Config` tree and validate it.
    *
    * Every field is bound and checked before anything is reported: absent
    * required values, values a strict cast refuses and rule failures of the
    * whole scope are thrown together. Rules see every resolved value of the
    * scope (cross-field conditions such as `Confirmed`); a rule that throws
    * fails its field without its exception.
    *
    * @throws Failure When any field fails; it lists every failure, keyed by path.
    */
   public function build (): Config
   {
      // !
      $Root = new Config($this->scope);
      /** @var array<string,array<int,string>> $failures */
      $failures = [];
      /** @var array<string,mixed> $source */
      $source = [];
      /** @var array<string,array<int,Condition>> $rules */
      $rules = [];

      // @@ Bind every field
      foreach ($this->Fields as $key => $Field) {
         $names = $this->paths[$key];

         $Node = $Root;
         foreach ($names as $name) {
            $Node = $Node->{$name};
         }

         try {
            $Node->bind(
               key: $Field->key,
               default: $Field->default,
               cast: $Field->cast,
               required: $Field->required
            );
         }
         catch (RuntimeException | InvalidArgumentException $Exception) {
            // ? Bind and cast messages never carry the value
            $failures[$key][] = $Exception->getMessage();

            continue;
         }

         // @ Every resolved value is visible to the rules (cross-field conditions)
         $source = $this->place($source, $names, 0, $Node->get());

         if ($Field->Rules !== []) {
            $rules[$key] = $Field->Rules;
         }
      }

      // @@ Validate each ruled field over every resolved value
      foreach ($rules as $key => $Rules) {
         try {
            $Validation = new Validation($source, [$key => $Rules]);
         }
         catch (Throwable) {
            // ? A rule that throws is a failure of its field; its exception
            //   (and the values in its trace) is dropped, never chained
            $failures[$key][] = 'A rule of this field could not run.';

            continue;
         }

         foreach ($Validation->errors as $path => $messages) {
            foreach ($messages as $message) {
               $failures[$path][] = $message;
            }
         }
      }

      // ?: Report every failure, in declaration order
      if ($failures !== []) {
         $ordered = [];
         foreach ($this->Fields as $key => $Field) {
            if (isset($failures[$key])) {
               $ordered[$key] = $failures[$key];
            }
         }

         throw new Failure($this->scope, $ordered);
      }

      // :
      return $Root;
   }
}
