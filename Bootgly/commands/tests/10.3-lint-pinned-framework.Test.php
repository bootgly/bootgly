<?php

namespace Bootgly\commands;


use function assert;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function putenv;
use function rmdir;
use function str_contains;
use function unlink;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Environment\Workspaces;
use Bootgly\CLI\Terminal\Output;


/**
 * The framework's own launcher reached from inside a kit (`cd Bootgly &&
 * bootgly lint …`) runs as the author — but the checkout is still the kit's
 * pinned submodule: `--fix` is refused there, checks still run.
 */

return new Test(
   description: '`lint --fix` refuses the framework checkout when a kit pins it as a submodule; checks still run',
   test: function () {
      // ! A framework root pinned by a miniature kit: a gitfile, and the
      //   kit's `.gitmodules` and launcher one level up
      $base = Temporaries::reserve('lint-pinned-framework');
      $framework = "{$base}/kit/Bootgly";
      mkdir("{$framework}/Bootgly", 0775, true);
      file_put_contents("{$framework}/.git", "gitdir: ../.git/modules/Bootgly\n");
      file_put_contents("{$base}/kit/.gitmodules", "[submodule \"Bootgly\"]\n\tpath = Bootgly\n");
      file_put_contents("{$base}/kit/bootgly", "<?php\n");
      $file = "{$framework}/Bootgly/Probe.php";
      $source = "<?php\n\nnamespace Bootgly;\n\n\nuse function count;\n\n\n"
         . "class Probe\n{\n   public function run (?array \$a): int { return count(\$a); }\n}\n";
      file_put_contents($file, $source);
      // ! A platform pinned beside it
      mkdir("{$base}/kit/Console", 0775, true);
      $platform = "{$base}/kit/Console/Probe.php";
      file_put_contents($platform, $source);

      // ! The command, bound to that root
      $Lint = new class ($framework) extends LintCommand {
         public function __construct (string $framework)
         {
            parent::__construct();
            $this->framework = $framework;
         }
      };
      $Probe = static function (array $options, null|string $target = null) use ($Lint, $file): array {
         $Host = new Output('php://memory');
         $Terminal = CLI->Terminal;
         $Restore = $Terminal->Output;
         $Terminal->Output = $Host;
         $previous = getenv('AI_AGENT');
         putenv('AI_AGENT=1');
         ob_start();
         try {
            $result = $Lint->run(['nullables', $target ?? $file], $options);
         }
         finally {
            $output = (string) ob_get_clean();
            putenv($previous === false ? 'AI_AGENT' : "AI_AGENT={$previous}");
            $Terminal->Output = $Restore;
         }

         return [$result, json_decode($output, true)];
      };

      // @ --fix on the pinned checkout: refused, nothing written
      [$result, $report] = $Probe(['fix' => true]);
      yield assert(
         assertion: $result === false
            && str_contains((string) ($report['message'] ?? ''), '--fix never rewrites a pinned tree')
            && file_get_contents($file) === $source,
         description: '`lint nullables --fix` on a framework checkout pinned by a kit is refused and the file stays byte-identical, got: '
            . json_encode($report)
      );

      // @ ...and so is a platform the kit pins beside it
      [$result, $report] = $Probe(['fix' => true], $platform);
      yield assert(
         assertion: $result === false
            && str_contains((string) ($report['message'] ?? ''), '--fix never rewrites a pinned tree')
            && file_get_contents($platform) === $source,
         description: '`lint nullables --fix` on the kit\'s Console/ from the framework\'s own launcher is refused, got: '
            . json_encode($report)
      );

      // @ A check never writes: it still runs there
      [$result, $report] = $Probe([]);
      yield assert(
         assertion: $result === false && ($report['mode'] ?? null) === 'check'
            && ($report['files']['scanned'] ?? null) === 1,
         description: 'a check-mode `lint nullables` still scans the pinned checkout, got: ' . json_encode($report)
      );

      // @@ Controls — one of the three signs missing: an ordinary framework
      //    checkout keeps `--fix` (only meaningful where this process runs as
      //    the author)
      if (Workspaces::detect() === Workspaces::Author) {
         $controls = [
            'a .git directory, not a gitfile' => static function () use ($framework): void {
               unlink("{$framework}/.git");
               mkdir("{$framework}/.git", 0775);
            },
            'no kit launcher above' => static function () use ($base): void {
               unlink("{$base}/kit/bootgly");
            },
            'no .gitmodules above' => static function () use ($base): void {
               unlink("{$base}/kit/.gitmodules");
            },
         ];
         $Restore = static function () use ($base, $framework): void {
            if (is_dir("{$framework}/.git") === true) {
               rmdir("{$framework}/.git");
            }
            file_put_contents("{$framework}/.git", "gitdir: ../.git/modules/Bootgly\n");
            file_put_contents("{$base}/kit/.gitmodules", "[submodule \"Bootgly\"]\n\tpath = Bootgly\n");
            file_put_contents("{$base}/kit/bootgly", "<?php\n");
         };
         foreach ($controls as $sign => $Break) {
            $Restore();
            file_put_contents($file, $source);
            $Break();

            [$result, $report] = $Probe(['fix' => true]);
            yield assert(
               assertion: $result === true && ($report['files']['fixed'] ?? null) === 1
                  && str_contains((string) file_get_contents($file), 'run (null|array $a)'),
               description: "with {$sign}, the framework checkout keeps `--fix`, got: " . json_encode($report)
            );
         }
      }
   }
);
