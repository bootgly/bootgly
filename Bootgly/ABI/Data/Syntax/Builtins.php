<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\Syntax;


use function array_fill_keys;
use function array_filter;
use function array_keys;
use function array_merge;
use function get_declared_classes;
use function get_defined_constants;
use function get_defined_functions;
use function str_contains;
use function strtolower;


class Builtins
{
   // * Data
   /** @var null|array<string,true> */
   private static null|array $functions = null;
   /** @var null|array<string,true> */
   private static null|array $constants = null;
   /** @var null|array<string,true> */
   private static null|array $classes = null;


   public static function load (): void
   {
      // @ Functions (case-insensitive → stored lowercase)
      if (self::$functions === null) {
         $internal = get_defined_functions()['internal'];
         self::$functions = array_fill_keys($internal, true); // already lowercase
      }

      // @ Constants (case-sensitive)
      if (self::$constants === null) {
         $grouped = get_defined_constants(true);
         $all = [];
         foreach ($grouped as $constants) {
            $all = array_merge($all, $constants);
         }
         self::$constants = array_fill_keys(
            array_keys($all),
            true
         );
      }

      // @ Classes (case-insensitive → stored lowercase, root namespace only)
      if (self::$classes === null) {
         $declared = get_declared_classes();
         $global = array_filter($declared, function (string $class): bool {
            return !str_contains($class, '\\');
         });
         $lowered = [];
         foreach ($global as $class) {
            $lowered[strtolower($class)] = true;
         }
         self::$classes = $lowered;
      }
   }

   public static function check (string $name, string $kind): bool
   {
      if (self::$functions === null) {
         self::load();
      }

      return match ($kind) {
         'function' => isset(self::$functions[strtolower($name)]),
         'const'    => isset(self::$constants[$name]),
         'class'    => isset(self::$classes[strtolower($name)]),
         default    => false,
      };
   }
}
