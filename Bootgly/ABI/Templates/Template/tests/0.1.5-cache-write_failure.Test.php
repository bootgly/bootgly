<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ABI\Templates\Template;
use Bootgly\ABI\Templates\Template\Exceptions\TemplateException;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * The compiled cache is written through `File->replace()`: a compiled file that
 * cannot land (here a directory planted at its path, which `rename()` cannot
 * replace with a file) fails the render with `Unwritable template cache file`
 * — the template is never rendered from a cache that was not written — and
 * leaves no temporary sibling in the cache directory.
 */
return new Test(
   description: 'A compiled template that cannot be written fails the render loudly and leaves no temporary behind',
   test: function () {
      // ! The cache path of a fresh template, then a directory in its place
      $raw = 'Hello @> $name; ' . uniqid('unwritable-', true);
      $Template = new Template($raw);
      $Template->render(['name' => 'world']);
      /** @var string $cache */
      $cache = (fn (): string => $this->cache)->call($Template);
      $directory = dirname($cache);
      /** The temporaries in the cache directory — scandir(), no pattern built from the path. */
      $Temporaries = static function () use ($directory): array {
         return array_values(preg_grep('/\.tmp\z/', @scandir($directory) ?: []) ?: []);
      };
      $before = $Temporaries();

      try {
         unlink($cache);
         mkdir($cache, 0o700);

         // @
         $message = null;
         try {
            new Template($raw)->render(['name' => 'world']);
         }
         catch (TemplateException $Exception) {
            $message = $Exception->getMessage();
         }

         yield assert(
            assertion: $message === "Unwritable template cache file `{$cache}`.",
            description: 'a compiled file that cannot replace what is at its path fails the render (' . var_export($message, true) . ')'
         );
         $left = array_values(array_diff($Temporaries(), $before));
         yield assert(
            assertion: $left === [] && is_dir($cache),
            description: 'the failed write leaves no temporary sibling and the planted entry as it was (left ' . json_encode($left) . ')'
         );
      }
      finally {
         @rmdir($cache);
         @unlink($cache);
      }
   }
);
