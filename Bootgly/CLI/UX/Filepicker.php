<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UX;


use const BOOTGLY_TTY;
use function in_array;
use function is_string;
use function realpath;
use function str_starts_with;
use function strcasecmp;
use function strtolower;
use function usort;
use DirectoryIterator;
use UnexpectedValueException;

use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Question;
use Bootgly\CLI\UI\Components\Tree;
use Bootgly\CLI\UI\Components\Tree\Node;


/**
 * Filesystem picker — a Tree preconfigured for the filesystem: lazy
 * directory scans, per-entry icons, extension filtering. pick() opens the
 * interactive browser and returns the chosen absolute path.
 */
class Filepicker
{
   public Input $Input;
   public Output $Output;

   // * Config
   /** Header line above the tree */
   public string $prompt;
   /** Root directory to browse */
   public string $root;
   /** @var array<int,string> Extensions to list (lowercase, no dot) — [] lists every file */
   public array $extensions;
   /** List dotfiles */
   public bool $hidden;
   /** Pick directories — Enter selects a directory and files are not listed */
   public bool $directories;
   /** Max visible rows */
   public null|int $viewport;
   /** Blink the aim marker */
   public bool $blink;
   /** @var array<string,string> Entry icons */
   public array $glyphs;

   // * Data
   /** Last picked absolute path — null after a cancel */
   public private(set) null|string $picked;


   public function __construct (Input $Input, Output $Output)
   {
      $this->Input = $Input;
      $this->Output = $Output;

      // * Config
      $this->prompt = 'Pick a path';
      $this->root = '.';
      $this->extensions = [];
      $this->hidden = false;
      $this->directories = false;
      $this->viewport = 12;
      $this->blink = false;
      $this->glyphs = [
         'directory' => '📁',
         'file'      => '📄'
      ];

      // * Data
      $this->picked = null;
   }

   /**
    * Opens the filesystem browser and returns the chosen absolute path.
    * File mode (default): Enter on a directory drills into it and only files
    * confirm. Directory mode (`directories = true`): Enter selects the aimed
    * directory and `→` keeps drilling. Non-interactive input degrades to a
    * typed path line.
    *
    * @return null|string The picked absolute path — null on cancel.
    */
   public function pick (): null|string
   {
      // ! Root path
      $root = realpath($this->root);

      // ? Unreachable roots cannot be browsed — warn loud: a silent null is
      //   indistinguishable from a user cancel
      if ($root === false) {
         $this->Output->render("@#Red:✖@; Filepicker: unreachable root `{$this->root}`.\n");

         $this->picked = null;

         // :
         return null;
      }

      // ? Non-interactive input delegates to the Question semantics
      if (BOOTGLY_TTY === false) {
         $Question = new Question($this->Input, $this->Output);
         $Question->prompt = $this->prompt;

         $answer = $Question->ask();
         // ? An empty line picks nothing — realpath('') would leak the cwd
         $path = $answer === '' ? false : realpath($answer);
         $this->picked = $path === false ? null : $path;

         // :
         return $this->picked;
      }

      // ! Tree preconfigured for the filesystem
      $Tree = new Tree($this->Input, $this->Output);
      $Tree->prompt = $this->prompt;
      $Tree->viewport = $this->viewport;
      $Tree->blink = $this->blink;

      // ! Root node — expanded upfront so the first level is visible
      $Root = $Tree->add($root, value: $root, resolver: $this->scan(...));
      $this->mount($Root);
      $Root->expand();

      // @ Navigate
      $Selected = $Tree->navigate();

      // @phpstan-ignore-next-line -- node values here are always path strings
      $this->picked = $Selected?->value;

      // :
      return $this->picked;
   }

   /**
    * Scans one directory into its node — the Tree lazy resolver: runs on the
    * first expand only. Unreadable directories resolve empty.
    *
    * @param Node $Node The directory node — its value is the directory path.
    *
    * @return void
    */
   private function scan (Node $Node): void
   {
      // ? Directory nodes always carry their path as the value
      $path = $Node->value;
      if (is_string($path) === false) {
         return;
      }

      // ! Entries: [name, path, directory?]
      $entries = [];

      try {
         $Iterator = new DirectoryIterator($path);
      }
      catch (UnexpectedValueException) {
         // ? Unreadable directory — resolves as an empty branch
         return;
      }

      // @@ Collect
      foreach ($Iterator as $Entry) {
         // ?
         if ($Entry->isDot() === true) {
            continue;
         }

         $name = $Entry->getFilename();

         // ? Hidden entries
         if ($this->hidden === false && str_starts_with($name, '.') === true) {
            continue;
         }

         $directory = $Entry->isDir();

         // ? Directory mode lists only directories
         if ($directory === false && $this->directories === true) {
            continue;
         }
         // ? Extension filter (files only)
         if ($directory === false && $this->extensions !== []) {
            $extension = strtolower($Entry->getExtension());

            if (in_array($extension, $this->extensions, true) === false) {
               continue;
            }
         }

         $entries[] = [$name, $Entry->getPathname(), $directory];
      }

      // @ Sort — directories first, then case-insensitive alphabetical
      usort($entries, static function (array $a, array $b): int {
         if ($a[2] !== $b[2]) {
            return $a[2] === true ? -1 : 1;
         }

         return strcasecmp($a[0], $b[0]);
      });

      // @@ Populate
      foreach ($entries as [$name, $path, $directory]) {
         $Child = $Node->add($name, value: $path);

         if ($directory === true) {
            $Child->resolver = $this->scan(...);

            $this->mount($Child);
         }
         else {
            $Child->glyph = $this->glyphs['file'];
         }
      }
   }

   /**
    * Mounts a directory node — icon and the Enter behavior for the mode:
    * file mode drills (Enter toggles, never confirms), directory mode selects.
    *
    * @param Node $Node The directory node.
    *
    * @return void
    */
   private function mount (Node $Node): void
   {
      $Node->glyph = $this->glyphs['directory'];

      // ? File mode: Enter drills into directories instead of confirming
      if ($this->directories === false) {
         $Node->selectable = false;
         $Node->action = static fn (Node $Directory): Node => $Directory->toggle();
      }
   }
}
