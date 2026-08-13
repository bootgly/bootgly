<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment;


use const BOOTGLY_ROOT_BASE;
use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_VERSION;
use const DIRECTORY_SEPARATOR;
use function dirname;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_string;
use function preg_match;
use function preg_quote;
use function realpath;
use function str_replace;
use function str_starts_with;
use function substr;
use function trim;


/**
 * Build — the identity of the installed framework: its version plus the
 * commit it was installed from. Version strings alone cannot tell two
 * installs apart (every `dev-main` install reports the same one), so the
 * commit is what answers "which code am I actually running" on install
 * screens, bug reports and support threads.
 *
 * The commit is read from the installation itself — the git metadata of a
 * clone, submodule or worktree, or the resolved reference Composer records.
 * Unknown sources (release archives) degrade to the version alone.
 */
class Build
{
   /** Abbreviated commit width — git's own short-hash default */
   public const int ABBREVIATION = 8;

   // * Data
   /** The framework version */
   public readonly string $version;
   /** The installed commit (full hash) — null when the source is unknown */
   public readonly null|string $commit;
   /** Where the commit came from (`git`, `composer`) — null when unknown */
   public readonly null|string $source;

   // * Metadata
   /** The abbreviated commit — null when there is no commit */
   public null|string $abbreviation {
      get => $this->commit !== null
         ? substr($this->commit, 0, self::ABBREVIATION)
         : null;
   }


   public function __construct (string $version, null|string $commit = null, null|string $source = null)
   {
      // * Data
      $this->version = $version;
      $this->commit = $commit;
      $this->source = $source;
   }


   /**
    * Detects the running build — the framework version and the commit the
    * installation came from (git metadata first, Composer's recorded
    * reference next).
    *
    * @return self
    */
   public static function detect (): self
   {
      // @ Git — a clone, a submodule or a worktree of the framework
      $commit = self::inspect(BOOTGLY_ROOT_DIR);
      if ($commit !== null) {
         // :
         return new self(BOOTGLY_VERSION, $commit, 'git');
      }

      // @ Composer — the framework sits at `<vendor>/bootgly/bootgly`, and the
      //   vendor manifest records the reference each package resolved to
      $manifest = dirname(BOOTGLY_ROOT_BASE, 2)
         . DIRECTORY_SEPARATOR . 'composer'
         . DIRECTORY_SEPARATOR . 'installed.php';

      if (is_file($manifest) === true) {
         /** @var mixed $installed */
         $installed = include $manifest;

         if (is_array($installed) === true) {
            /** @var array<string,mixed> $versions */
            $versions = is_array($installed['versions'] ?? null) === true
               ? $installed['versions']
               : [];
            /** @var array<string,mixed> $package */
            $package = is_array($versions['bootgly/bootgly'] ?? null) === true
               ? $versions['bootgly/bootgly']
               : [];

            $reference = $package['reference'] ?? null;

            if (is_string($reference) === true && self::check($reference) === true) {
               // :
               return new self(BOOTGLY_VERSION, $reference, 'composer');
            }
         }
      }

      // : An unidentifiable source (release archive, vendored copy, ...)
      return new self(BOOTGLY_VERSION);
   }

   /**
    * Identifies the build in one line — `v1.0.0 (a1b2c3d4)`, or the version
    * alone when the commit is unknown.
    *
    * @return string
    */
   public function identify (): string
   {
      // ?:
      if ($this->abbreviation === null) {
         return "v{$this->version}";
      }

      // :
      return "v{$this->version} ({$this->abbreviation})";
   }

   /**
    * Inspects the git metadata of an installation directory, resolving the
    * commit its HEAD points at. Handles the three shapes git writes: a `.git`
    * directory (clone), a `.git` file pointing elsewhere (submodule,
    * worktree), a detached HEAD (how submodules are pinned) and a symbolic
    * ref resolved through a loose ref file or `packed-refs`.
    *
    * @param string $base The installation directory (trailing separator).
    *
    * @return null|string The commit hash, or null when git tells nothing.
    */
   private static function inspect (string $base): null|string
   {
      $directory = "{$base}.git";

      // ? A `.git` file points at the real git directory
      if (is_file($directory) === true) {
         $pointer = trim((string) file_get_contents($directory));

         // ?
         if (str_starts_with($pointer, 'gitdir:') === false) {
            return null;
         }

         $pointer = trim(substr($pointer, 7));
         // ! Relative pointers resolve from the installation directory
         $directory = (string) realpath("{$base}{$pointer}") ?: (string) realpath($pointer);
      }

      // ---

      $HEAD = $directory . DIRECTORY_SEPARATOR . 'HEAD';

      // ?
      if (is_file($HEAD) === false) {
         return null;
      }

      $head = trim((string) file_get_contents($HEAD));

      // ?: A detached HEAD holds the commit itself — how submodules are pinned
      if (self::check($head) === true) {
         return $head;
      }

      // ?
      if (str_starts_with($head, 'ref:') === false) {
         return null;
      }

      $reference = trim(substr($head, 4));

      // ---

      // @ The loose ref file
      $loose = $directory
         . DIRECTORY_SEPARATOR
         . str_replace('/', DIRECTORY_SEPARATOR, $reference);

      if (is_file($loose) === true) {
         $commit = trim((string) file_get_contents($loose));

         // ?:
         return self::check($commit) === true ? $commit : null;
      }

      // @ The packed refs (git packs loose refs away on `gc`)
      $packed = $directory . DIRECTORY_SEPARATOR . 'packed-refs';

      if (is_file($packed) === true) {
         $found = preg_match(
            '#^([0-9a-f]{40,64}) ' . preg_quote($reference, '#') . '$#m',
            (string) file_get_contents($packed),
            $matches
         );

         // ?:
         return $found === 1 ? $matches[1] : null;
      }

      // :
      return null;
   }

   /**
    * Checks whether a string is a commit hash (SHA-1 or SHA-256).
    *
    * @param string $commit The candidate hash.
    *
    * @return bool
    */
   private static function check (string $commit): bool
   {
      // :
      return preg_match('#^[0-9a-f]{40}(?:[0-9a-f]{24})?$#', $commit) === 1;
   }
}
