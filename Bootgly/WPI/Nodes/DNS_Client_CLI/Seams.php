<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI;


use const T_FN;
use const T_FUNCTION;
use function array_key_first;
use function count;
use function file_get_contents;
use function filemtime;
use function get_resource_id;
use function get_resource_type;
use function is_array;
use function is_file;
use function is_int;
use function is_object;
use function is_resource;
use function spl_object_id;
use function str_contains;
use function time;
use function token_get_all;
use Closure;
use ReflectionFunction;
use ReflectionObject;
use ReflectionReference;

use Bootgly\ABI\IO\Resolver;


/**
 * The identity of a configuration's seams — the `Resolver` and the `Probe` —
 * by what they do rather than by which object they are.
 *
 * Two seams built from the same code, bound to the same `$this` (or the same
 * scope and called class), with the same captured values, behave alike and
 * share an identity: a `Configs` rebuilt per request with such a seam finds the
 * shared state of the one before it. Captured scalars and arrays count by
 * value, captured objects by identity, captured closures by this same rule.
 *
 * A seam that cannot be keyed by its code keeps its object identity, as every
 * seam did before: one with a by-reference capture or a `static` variable (its
 * behaviour changes while its captures stay equal), one defined in `eval()`'d
 * code or in a file changed in or after the second the process started (the
 * code it runs may not be the file's), one whose first line starts more than
 * one closure, one capturing an array wider than `WIDTH`, or one nested deeper
 * than `DEPTH`.
 */
final class Seams
{
   /** How deep captured arrays and closures are followed before a seam is keyed by identity. */
   public const int DEPTH = 8;
   /** How many elements a captured array may hold before a seam is keyed by identity. */
   public const int WIDTH = 64;
   /** How many file versions the closure-site table keeps. */
   public const int FILES = 256;

   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   /** @var array<string,array<int,int>> How many closures start on each line, per file version (path and mtime). */
   private static array $sites = [];
   /** When this process started (seconds): a file changed since may not hold the code that runs. */
   private static null|int $started = null;


   /**
    * The identity of a seam: its code and captures where it can be keyed by
    * them, else its object identity.
    *
    * @param null|Closure|Resolver $Seam A `Probe` closure or a `Resolver`.
    *
    * @return null|int|array<int|string,mixed> `null` for no seam, an `int` object id, or the code key.
    */
   public static function identify (null|Closure|Resolver $Seam): null|int|array
   {
      // ?
      if ($Seam === null) {
         return null;
      }

      // @
      $key = $Seam instanceof Resolver
         ? self::inspect($Seam)
         : self::trace($Seam, 0);

      // :
      return $key ?? spl_object_id($Seam);
   }

   /**
    * Key a `Resolver` by every property it holds: its sources and its `nsswitch` path.
    *
    * @return null|array<int|string,mixed> `null` when a property cannot be keyed.
    */
   private static function inspect (Resolver $Resolver): null|array
   {
      // ! The properties are private: read them, never write
      $Object = new ReflectionObject($Resolver);
      $key = ['resolver', $Resolver::class];
      foreach ($Object->getProperties() as $Property) {
         // ? A static property belongs to no seam
         if ($Property->isStatic()) {
            continue;
         }
         $part = $Property->isInitialized($Resolver)
            ? self::weigh($Property->getValue($Resolver), 1)
            : ['unset'];
         if ($part === null) {
            return null;
         }
         $key[$Property->name] = $part;
      }

      // :
      return $key;
   }

   /**
    * Key a closure by its site, its binding and its captures.
    *
    * @return null|array<int,mixed> `null` when it cannot be keyed.
    */
   private static function trace (Closure $Closure, int $depth): null|array
   {
      // ?
      if ($depth > self::DEPTH) {
         return null;
      }

      $Function = new ReflectionFunction($Closure);
      $This = $Function->getClosureThis();
      // ! The binding: `$this`, the scope, and the class it was called on (`static::`)
      $binding = [
         $This === null ? null : spl_object_id($This),
         $Function->getClosureScopeClass()?->name,
         $Function->getClosureCalledClass()?->name,
      ];

      // ? A first-class callable of an internal function: its name is its code
      if ($Function->isInternal()) {
         return ['internal', $Function->getName(), ...$binding];
      }

      // ? A site that cannot tell two closures apart, or that may not hold the code that runs
      $file = (string) $Function->getFileName();
      $line = (int) $Function->getStartLine();
      if (self::check($file, $line) === false) {
         return null;
      }

      // ? A `static` variable or a by-reference capture: equal captures, changing behaviour
      $captures = $Function->getClosureUsedVariables();
      $statics = $Function->getStaticVariables();
      if (count($statics) !== count($captures)) {
         return null;
      }
      $values = [];
      foreach ($captures as $name => $value) {
         if (ReflectionReference::fromArrayElement($captures, $name) !== null) {
            return null;
         }
         $part = self::weigh($value, $depth + 1);
         if ($part === null) {
            return null;
         }
         $values[$name] = $part;
      }

      // :
      return [
         'closure', $file, $line, (int) $Function->getEndLine(), $Function->getName(),
         ...$binding,
         $values,
      ];
   }

   /**
    * Key one captured value.
    *
    * @return null|array<int,mixed> `null` when it cannot be keyed.
    */
   private static function weigh (mixed $value, int $depth): null|array
   {
      // ?
      if ($depth > self::DEPTH) {
         return null;
      }

      // ?: A closure: by this same rule
      if ($value instanceof Closure) {
         return self::trace($value, $depth);
      }
      // ?: Any other object: by identity (the state holds it alive, so the id is never reused meanwhile)
      if (is_object($value)) {
         return ['object', $value::class, spl_object_id($value)];
      }
      // ?: A resource: by identity
      if (is_resource($value)) {
         return ['resource', get_resource_type($value), get_resource_id($value)];
      }
      // ?: An array: each element, refusing references and wide arrays (a key per lookup)
      if (is_array($value)) {
         if (count($value) > self::WIDTH) {
            return null;
         }
         $elements = [];
         foreach ($value as $index => $element) {
            if (ReflectionReference::fromArrayElement($value, $index) !== null) {
               return null;
            }
            $part = self::weigh($element, $depth + 1);
            if ($part === null) {
               return null;
            }
            $elements[] = [$index, $part];
         }

         return ['array', $elements];
      }

      // :
      return ['value', $value];
   }

   /**
    * Whether a closure site can key the closure: a real file (not `eval()`'d
    * code), unchanged since this process started, whose line starts one `fn`
    * or `function` token. Each file version (path and mtime) is read once.
    */
   private static function check (string $file, int $line): bool
   {
      // ? `eval()`'d code, or code without a file: two sources share one name
      if ($file === '' || str_contains($file, "eval()'d code") || is_file($file) === false) {
         return false;
      }

      // ? A file changed since the process started: the compiled closure may be the old code
      $started = $_SERVER['REQUEST_TIME'] ?? null;
      self::$started ??= is_int($started) ? $started : time();
      $mtime = (int) filemtime($file);
      if ($mtime >= self::$started) {
         return false;
      }

      $version = "{$file}|{$mtime}";
      if (isSet(self::$sites[$version]) === false) {
         // ! A bounded table: the oldest file version goes first
         if (count(self::$sites) >= self::FILES) {
            unset(self::$sites[array_key_first(self::$sites)]);
         }

         // @ Count the closures that start on each line
         $source = file_get_contents($file);
         $counts = [];
         foreach (token_get_all($source === false ? '' : $source) as $Token) {
            if (is_array($Token) && ($Token[0] === T_FN || $Token[0] === T_FUNCTION)) {
               $counts[$Token[2]] = ($counts[$Token[2]] ?? 0) + 1;
            }
         }
         self::$sites[$version] = $counts;
      }

      // :
      return (self::$sites[$version][$line] ?? 0) === 1;
   }
}
