<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const PHP_BINARY;
use function assert;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getenv;
use function is_array;
use function is_file;
use function is_resource;
use function json_decode;
use function json_encode;
use function mkdir;
use function proc_close;
use function proc_open;
use function str_contains;
use function stream_get_contents;
use function symlink;
use function var_export;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * `bootgly lint` inside a kit: paths follow the working directory, no path
 * means the project directory the caller stands in (and nothing at the kit
 * root), and `--fix` never rewrites the pinned `Bootgly/`, `Console/` or `Web/`.
 */

return new Test(
   description: '`lint` in a kit: cwd-relative paths, the project as the default scope, and --fix refused on the pinned submodules',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }
      $autoboot = BOOTGLY_ROOT_BASE . '/autoboot.php';
      if (is_file($autoboot) === false) {
         yield assert(assertion: true, description: 'Skipped: the framework autoboot is not at the root');
         return;
      }

      // ! A miniature kit: a launcher whose working base is the kit, one
      //   project, and stand-ins for the three pinned submodules carrying the
      //   same violation (the autoloader falls back to the real framework)
      $kit = Temporaries::reserve('lint-kit-scope');
      $project = "{$kit}/projects/Alpha";
      $pinned = ['Bootgly', 'Console', 'Web'];
      mkdir("{$project}/Models", 0775, true);
      foreach ($pinned as $submodule) {
         mkdir("{$kit}/{$submodule}", 0775, true);
      }
      file_put_contents(
         "{$kit}/bootgly",
         "<?php\n"
            . "define('BOOTGLY_WORKING_BASE', __DIR__);\n"
            . "define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);\n"
            . 'require ' . var_export($autoboot, true) . ";\n"
      );
      file_put_contents("{$kit}/projects/Bootgly.projects.php", "<?php\n\nreturn [];\n");
      $source = "<?php\n\nnamespace Alpha\\Models;\n\n\nuse function count;\n\n\n"
         . "class Probe\n{\n   public function run (?array \$a): int { return count(\$a); }\n}\n";
      file_put_contents("{$project}/Models/Probe.php", $source);
      foreach ($pinned as $submodule) {
         file_put_contents("{$kit}/{$submodule}/Probe.php", $source);
      }
      // ! A link inside the project that reaches a pinned submodule
      symlink("{$kit}/Console", "{$project}/Vendored");
      $Untouched = static function () use ($kit, $pinned, $source): bool {
         foreach ($pinned as $submodule) {
            if (file_get_contents("{$kit}/{$submodule}/Probe.php") !== $source) {
               return false;
            }
         }

         return true;
      };

      // ! Agent environment — the JSON contract is what the assertions read
      $environment = getenv();
      $environment['AI_AGENT'] = '1';

      $Run = static function (string $cwd, string ...$arguments) use ($kit, $environment): array {
         $process = proc_open(
            [PHP_BINARY, '-d', 'opcache.jit=0', "{$kit}/bootgly", 'lint', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $environment
         );
         if (is_resource($process) === false) {
            return ['status' => -1, 'report' => null];
         }
         /** @var array<int,resource> $pipes */
         $output = (string) stream_get_contents($pipes[1]);
         fclose($pipes[1]);
         fclose($pipes[2]);
         $status = proc_close($process);

         $report = json_decode($output, true);

         return ['status' => $status, 'report' => is_array($report) ? $report : null];
      };

      // @ A relative path follows the caller, not the kit root
      $result = $Run($project, 'nullables', 'Models');
      yield assert(
         assertion: $result['status'] !== 0
            && ($result['report']['files']['scanned'] ?? null) === 1
            && ($result['report']['report'][0]['file'] ?? null) === 'projects/Alpha/Models/Probe.php',
         description: 'from projects/Alpha, `lint nullables Models` scans projects/Alpha/Models, got: ' . json_encode($result)
      );

      // @ No path inside a project: that project
      $result = $Run($project, 'nullables');
      yield assert(
         assertion: $result['status'] !== 0
            && ($result['report']['files']['scanned'] ?? null) === 1
            && ($result['report']['report'][0]['file'] ?? null) === 'projects/Alpha/Models/Probe.php',
         description: 'from projects/Alpha, a bare `lint nullables` scans the project, got: ' . json_encode($result)
      );

      // @ No path at the kit root: refused — never the pinned framework
      $result = $Run($kit, 'imports', '--fix');
      yield assert(
         assertion: $result['status'] !== 0
            && ($result['report']['files']['scanned'] ?? null) === 0
            && str_contains((string) ($result['report']['message'] ?? ''), 'No default path outside projects/'),
         description: 'at the kit root, a bare `lint imports --fix` is refused, got: ' . json_encode($result)
      );

      // @@ --fix on each pinned submodule, on a path that holds them, through
      //    `..` and through a link: refused, nothing written
      $targets = [
         [$kit, 'Bootgly'],
         [$kit, 'Console'],
         [$kit, 'Web/Probe.php'],
         [$kit, '.'],
         [$kit, 'projects/../Console/'],
         [$project, 'Vendored'],
         // # The framework this launcher runs (a clean file: nothing to write even unguarded)
         [$kit, BOOTGLY_ROOT_BASE . '/Bootgly/API/Environment/Workspaces.php'],
      ];
      foreach ($targets as [$cwd, $target]) {
         $result = $Run($cwd, 'nullables', $target, '--fix');
         yield assert(
            assertion: $result['status'] !== 0
               && ($result['report']['files']['scanned'] ?? null) === 0
               && str_contains((string) ($result['report']['message'] ?? ''), '--fix never rewrites a pinned tree')
               && $Untouched() === true,
            description: "`lint nullables {$target} --fix` is refused and every pinned stand-in stays byte-identical, got: "
               . json_encode($result)
         );
      }

      // @ A check never writes: allowed on the pinned submodule
      $result = $Run($kit, 'nullables', 'Console');
      yield assert(
         assertion: $result['status'] !== 0
            && ($result['report']['mode'] ?? null) === 'check'
            && ($result['report']['files']['scanned'] ?? null) === 1,
         description: 'a check-mode `lint nullables Console` still runs, got: ' . json_encode($result)
      );

      // @ --fix inside the project rewrites the project — and only the project
      $result = $Run($project, 'nullables', 'Models', '--fix');
      yield assert(
         assertion: $result['status'] === 0
            && ($result['report']['files']['fixed'] ?? null) === 1
            && str_contains((string) file_get_contents("{$project}/Models/Probe.php"), 'run (null|array $a)')
            && $Untouched() === true,
         description: 'from projects/Alpha, `lint nullables Models --fix` fixes the project file and leaves the pinned trees alone, got: '
            . json_encode($result)
      );
   }
);
