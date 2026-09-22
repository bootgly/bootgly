<?php

namespace Bootgly\API\Environment;


use const PHP_BINARY;
use function assert;
use function fclose;
use function function_exists;
use function is_resource;
use function json_encode;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function var_export;

use Bootgly\ACI\Tests\Suite\Test;


/**
 * `Workspaces::detect()` from the constants a launcher defines: the framework
 * checkout, a platform checkout developing itself, and a kit — also a kit that
 * booted its platforms, whose roots are submodules, never the working base.
 */

return new Test(
   description: 'Workspaces::detect() tells the framework checkout, a platform checkout and a kit apart from the launcher constants',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }

      // ! One interpreter per case: the constants are process-wide
      $Detect = static function (string $root, string $base, array $platforms = []): string {
         $code = 'define("BOOTGLY_ROOT_DIR", ' . var_export("{$root}/", true) . ');'
            . 'define("BOOTGLY_WORKING_BASE", ' . var_export($base, true) . ');'
            . 'define("BOOTGLY_WORKING_DIR", ' . var_export("{$base}/", true) . ');';
         foreach ($platforms as $constant => $value) {
            $code .= 'define(' . var_export($constant, true) . ', ' . var_export($value, true) . ');';
         }
         $code .= 'require ' . var_export(__DIR__ . '/../Workspaces.php', true) . ';'
            . 'echo Bootgly\API\Environment\Workspaces::detect()->name;';

         $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
         if (is_resource($process) === false) {
            return '';
         }
         /** @var array<int,resource> $pipes */
         $output = (string) stream_get_contents($pipes[1]);
         fclose($pipes[1]);
         fclose($pipes[2]);
         proc_close($process);

         return $output;
      };

      $framework = '/srv/bootgly';
      $kit = '/srv/bootgly.kit';

      // @ The working base IS the framework
      $name = $Detect($framework, $framework);
      yield assert(
         assertion: $name === 'Author',
         description: 'the framework checkout is the Author workspace, got: ' . json_encode($name)
      );

      // @ A platform root that IS the working base, each platform on its own
      foreach (['CONSOLE_ROOT_BASE', 'WEB_ROOT_BASE'] as $constant) {
         $name = $Detect("{$kit}/Bootgly", '/srv/bootgly-platform', [$constant => '/srv/bootgly-platform']);
         yield assert(
            assertion: $name === 'Platform',
            description: "{$constant} equal to the working base is a Platform workspace, got: " . json_encode($name)
         );
      }

      // @ A kit — bare, and with its platforms booted as submodules
      $name = $Detect("{$kit}/Bootgly", $kit);
      yield assert(
         assertion: $name === 'Kit',
         description: 'a working base that is neither the framework nor a platform is a Kit, got: ' . json_encode($name)
      );
      $name = $Detect("{$kit}/Bootgly", $kit, ['CONSOLE_ROOT_BASE' => "{$kit}/Console", 'WEB_ROOT_BASE' => "{$kit}/Web"]);
      yield assert(
         assertion: $name === 'Kit',
         description: 'platform roots under the kit (booted submodules) keep it a Kit, got: ' . json_encode($name)
      );
   }
);
