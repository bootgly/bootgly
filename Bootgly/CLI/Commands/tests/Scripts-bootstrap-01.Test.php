<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use function array_unique;
use function assert;
use function count;
use function fclose;
use function file_put_contents;
use function function_exists;
use function in_array;
use function is_array;
use function is_dir;
use function is_resource;
use function json_decode;
use function mkdir;
use function preg_replace;
use function proc_close;
use function proc_open;
use function rmdir;
use function scandir;
use function stream_get_contents;
use function substr;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * A consumer's own `scripts/autoboot.php` reaches the registry.
 *
 * The framework bootstrap declares every group (`built-in`, `imported`,
 * `user`) and is included first, so merging the consumer's bootstrap with
 * `+=` — which never overwrites an existing key — dropped the consumer's
 * groups whole: the `user` slot the file documents ("Define your scripts
 * filenames here") could never register anything. Registering from the
 * accumulated map once per resource directory also stored every entry twice.
 */
return new Test(
   description: 'Scripts merges the consumer bootstrap instead of discarding it',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }

      // ! A consumer working directory with a script of its own
      $working = Temporaries::reserve('scripts-bootstrap');
      mkdir("{$working}/scripts", 0o700);
      file_put_contents(
         "{$working}/scripts/autoboot.php",
         <<<'BOOTSTRAP'
         <?php
         return [
            'scripts' => [
               'built-in' => [],
               'imported' => [],
               'user' => ['my-report.php']
            ]
         ];
         BOOTSTRAP
      );

      // ! The registry is built at construction, from the constants of the boot
      $root = BOOTGLY_ROOT_DIR;
      $probe = <<<PROBE
      define('BOOTGLY_WORKING_BASE', '{$working}');
      define('BOOTGLY_WORKING_DIR', '{$working}/');
      require '{$root}autoboot.php';
      \$Scripts = new \\Bootgly\\CLI\\Scripts();
      \$Property = new \\ReflectionProperty(\$Scripts, 'scripts');
      \$Property->setAccessible(true);
      echo json_encode(\$Property->getValue(\$Scripts));
      PROBE;

      $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
      $Process = proc_open(
         [PHP_BINARY, '-r', $probe],
         $descriptors,
         $pipes,
         BOOTGLY_ROOT_DIR
      );

      $output = '';
      $errors = '';
      if (is_resource($Process)) {
         $output = (string) stream_get_contents($pipes[1]);
         $errors = (string) stream_get_contents($pipes[2]);
         $errors = substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $errors) ?? '', 0, 400);
         fclose($pipes[1]);
         fclose($pipes[2]);
         proc_close($Process);
      }

      $registered = json_decode($output, true);

      // @
      $purge = static function (string $path) use (&$purge): void {
         foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
               continue;
            }

            $child = "{$path}/{$entry}";
            is_dir($child) ? $purge($child) : unlink($child);
         }

         rmdir($path);
      };
      $purge($working);

      yield assert(
         assertion: is_array($registered) && $registered !== [],
         description: "the probe returned the registry ({$errors})"
      );

      if (is_array($registered) === false) {
         return;
      }

      // ? The `user` group resolves against the CONSUMER `scripts/` directory
      yield assert(
         assertion: in_array("{$working}/scripts/my-report.php", $registered, true),
         description: "the consumer's `user` script is registered, resolved against its own scripts/"
      );

      // ? `validate()` matches an absolute SCRIPT_FILENAME against this entry
      yield assert(
         assertion: in_array(BOOTGLY_ROOT_DIR . 'bootgly', $registered, true)
            && in_array('bootgly', $registered, true),
         description: 'the bootstrap group still registers the `bootgly` entry point, absolute and relative'
      );

      yield assert(
         assertion: count($registered) === count(array_unique($registered)),
         description: 'each script is registered once, not once per resource directory'
      );
   }
);
