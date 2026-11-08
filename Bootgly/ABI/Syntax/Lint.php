<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax;


use function array_is_list;
use function dirname;
use function get_debug_type;
use function in_array;
use function is_dir;
use function is_file;
use function is_string;
use function realpath;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strpbrk;
use InvalidArgumentException;
use UnexpectedValueException;

use Bootgly\ABI\Syntax\Analyzers\Rules;
use Bootgly\ABI\Syntax\Lint\Exemption;
use Bootgly\ABI\Syntax\Lint\Glob;


/**
 * The configuration of `bootgly lint check` — what a `bootgly.Lint.php`
 * file at the root of a tree returns.
 *
 * ```php
 * return new Lint(
 *    paths: ['Bootgly/'],
 *    excludes: ['vendor/', '**\/tests/fixtures/**'],
 *    rules: [Rules::Imports, Rules::Nullables, Rules::Methods],
 *    Exemptions: [
 *       new Exemption(
 *          rule: Rules::Methods,
 *          path: 'Bootgly/ACI/Tests/Assertion.php',
 *          reason: 'Public API until 2.0',
 *          symbol: 'Assertion::assertTrue'
 *       ),
 *    ]
 * );
 * ```
 *
 * Every path and pattern is relative to the directory of the file. The
 * configuration is checked when it is built: anything malformed throws, and
 * the command fails loud instead of falling back to its defaults.
 */
class Lint
{
   // * Config
   /** @var array<int,string> The trees checked — relative files or directories (`.` for the file's own directory) */
   public readonly array $paths;
   /** @var array<int,string> Glob patterns of the paths never checked */
   public readonly array $excludes;
   /** @var array<int,Rules> The rules enforced, in order */
   public readonly array $rules;
   /** @var array<int,Exemption> The recorded waivers */
   public readonly array $Exemptions;

   // * Metadata
   /** The `bootgly.Lint.php` this configuration was loaded from — null when built in code */
   public private(set) null|string $file = null;
   /** @var array<int,Glob> */
   private array $Excludes;
   /** @var array<int,Glob> The path glob of each exemption, by the exemption's index */
   private array $Globs;


   /**
    * Every entry is checked at runtime — a `bootgly.Lint.php` is user input.
    *
    * @param array<mixed> $paths The trees to check (strings), relative to the file's directory
    * @param array<mixed> $excludes Glob patterns (strings) of paths never checked
    * @param array<mixed> $rules The rules to enforce (`Rules` cases) — at least one, no repeats
    * @param array<mixed> $Exemptions The waivers (`Exemption`), each for an enabled rule
    *
    * @throws InvalidArgumentException When any entry is malformed
    */
   public function __construct (
      array $paths,
      array $excludes = [],
      array $rules = [Rules::Imports, Rules::Nullables, Rules::Promotions, Rules::Methods],
      array $Exemptions = []
   )
   {
      // ? Lists only — a keyed array hides what was meant
      foreach (['paths' => $paths, 'excludes' => $excludes, 'rules' => $rules, 'Exemptions' => $Exemptions] as $key => $list) {
         if (array_is_list($list) === false) {
            throw new InvalidArgumentException("Lint {$key} must be a list.");
         }
      }

      // ? Paths: at least one, each a relative path inside the tree
      if ($paths === []) {
         throw new InvalidArgumentException('Lint paths must name at least one file or directory.');
      }
      $plains = [];
      foreach ($paths as $index => $path) {
         if (is_string($path) === false) {
            $type = get_debug_type($path);
            throw new InvalidArgumentException("Lint paths[{$index}] must be a string, got {$type}.");
         }
         // ! The same tree rules as a pattern (relative, no `..`, no backslash), minus the wildcards
         if ($path !== '.' && $path !== './') {
            try {
               new Glob($path);
            }
            catch (InvalidArgumentException $Exception) {
               $reason = str_replace("Lint pattern '{$path}' ", '', $Exception->getMessage());
               throw new InvalidArgumentException("Lint paths[{$index}] '{$path}' {$reason}");
            }
            if (strpbrk($path, '*?') !== false) {
               throw new InvalidArgumentException("Lint paths[{$index}] '{$path}' must be a plain path, not a pattern.");
            }
         }
         $plains[] = $path;
      }

      // ? Excludes: valid patterns
      $patterns = [];
      $Excludes = [];
      foreach ($excludes as $index => $exclude) {
         if (is_string($exclude) === false) {
            $type = get_debug_type($exclude);
            throw new InvalidArgumentException("Lint excludes[{$index}] must be a string, got {$type}.");
         }
         $Excludes[] = new Glob($exclude);
         $patterns[] = $exclude;
      }

      // ? Rules: at least one, each once
      if ($rules === []) {
         throw new InvalidArgumentException('Lint rules must enable at least one rule.');
      }
      $seen = [];
      foreach ($rules as $index => $rule) {
         if ($rule instanceof Rules === false) {
            $type = get_debug_type($rule);
            throw new InvalidArgumentException("Lint rules[{$index}] must be a Rules case, got {$type}.");
         }
         if (in_array($rule, $seen, true)) {
            throw new InvalidArgumentException("Lint rules[{$index}] repeats {$rule->name}.");
         }
         $seen[] = $rule;
      }

      // ? Exemptions: each for an enabled rule, with a valid path glob
      $Globs = [];
      $Waivers = [];
      foreach ($Exemptions as $index => $Exemption) {
         if ($Exemption instanceof Exemption === false) {
            $type = get_debug_type($Exemption);
            throw new InvalidArgumentException("Lint Exemptions[{$index}] must be an Exemption, got {$type}.");
         }
         if (in_array($Exemption->rule, $seen, true) === false) {
            throw new InvalidArgumentException(
               "Lint Exemptions[{$index}] waives {$Exemption->rule->name}, which is not an enabled rule."
            );
         }
         $Globs[] = new Glob($Exemption->path);
         $Waivers[] = $Exemption;
      }

      // * Config
      $this->paths = $plains;
      $this->excludes = $patterns;
      $this->rules = $seen;
      $this->Exemptions = $Waivers;

      // * Metadata
      $this->Excludes = $Excludes;
      $this->Globs = $Globs;
   }

   /**
    * Find the `bootgly.Lint.php` that governs a directory: the nearest one
    * walking up from it, never above the workspace root.
    *
    * @param string $directory Where the search starts (the working directory)
    * @param string $root The workspace root — the highest directory searched
    *
    * @return null|string The absolute path of the file, or null when there is none
    */
   public static function discover (string $directory, string $root): null|string
   {
      $top = realpath($root);
      if ($top === false) {
         return null;
      }
      $top = rtrim($top, '/');

      // ! Start inside the workspace — from anywhere else, only its root is searched
      $current = realpath($directory);
      if ($current === false || is_dir($current) === false
         || ($current !== $top && str_starts_with("{$current}/", "{$top}/") === false)
      ) {
         $current = $top;
      }

      // @@ Up to the root, inclusive
      while (true) {
         $file = "{$current}/bootgly.Lint.php";
         if (is_file($file)) {
            return $file;
         }
         if ($current === $top || $current === '' || $current === '/') {
            return null;
         }
         $current = dirname($current);
      }
   }

   /**
    * Load a `bootgly.Lint.php`. The file must return a `Lint`; anything else —
    * a parse error, a malformed entry, another value — throws, so a broken
    * configuration is never mistaken for the defaults.
    *
    * @param string $file The absolute path of the file
    *
    * @throws UnexpectedValueException When the file does not return a Lint
    * @throws \Throwable Whatever building the configuration threw
    *
    * @return self
    */
   public static function load (string $file): self
   {
      // ? A regular, readable file
      if (is_file($file) === false) {
         throw new UnexpectedValueException('The file is missing or not a regular file.');
      }

      // @ Isolated scope — the file sees only its own variables
      $Lint = (static function (string $__file__): mixed {
         return require $__file__;
      })($file);

      // ? It must return the configuration
      if ($Lint instanceof self === false) {
         $type = get_debug_type($Lint);
         throw new UnexpectedValueException("The file must return a Bootgly\\ABI\\Syntax\\Lint, got {$type}.");
      }

      // * Metadata
      $Lint->file = $file;

      // :
      return $Lint;
   }

   /**
    * Tell whether a path is excluded.
    *
    * @param string $path A path relative to the configuration's directory, `/`-separated
    *
    * @return bool
    */
   public function exclude (string $path): bool
   {
      foreach ($this->Excludes as $Glob) {
         if ($Glob->match($path)) {
            return true;
         }
      }

      return false;
   }

   /**
    * Select the exemptions of a rule whose path glob matches a path.
    *
    * @param Rules $rule The rule being checked
    * @param string $path A path relative to the configuration's directory, `/`-separated
    *
    * @return array<int,Exemption> The matching exemptions, keyed by their index in `$Exemptions`
    */
   public function select (Rules $rule, string $path): array
   {
      $selected = [];
      foreach ($this->Exemptions as $index => $Exemption) {
         if ($Exemption->rule === $rule && $this->Globs[$index]->match($path)) {
            $selected[$index] = $Exemption;
         }
      }

      return $selected;
   }
}
