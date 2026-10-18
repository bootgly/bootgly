<?php
namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use function assert;
use function chdir;
use function chmod;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function getenv;
use function is_resource;
use function proc_close;
use function proc_open;
use function putenv;
use function rmdir;
use function str_contains;
use function stream_get_contents;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Environment\Container;


/**
 * The tips name the launcher that will actually run this kit: bare `bootgly`
 * only when the global on PATH is the walk-up wrapper.
 */
return new Test(
   description: 'tips say `php bootgly` unless the global wrapper on PATH is the walk-up one — or the launcher is the image\'s own',
   // ? Inside a container every answer is the bare `bootgly` — nothing to distinguish
   skip: Container::check(),
   test: function () {
      $suggest = static fn (): string => KitCommand::suggest();

      // ! A PATH of our own, so the lookup sees only what we put there
      $bin = Temporaries::reserve('tips-launcher');
      $shadow = Temporaries::reserve('tips-launcher-shadow');
      $previous = (string) getenv('PATH');
      $contained = getenv('BOOTGLY_DOCKER');
      $directory = (string) getcwd();
      putenv("PATH={$bin}");
      putenv('BOOTGLY_DOCKER');

      try {
         // @ No global at all
         yield assert(
            assertion: $suggest() === 'php ',
            description: 'without a global `bootgly`, the tip says `php bootgly`'
         );

         // @ A stale wrapper — pinned to a launcher, no walk-up marker
         file_put_contents("{$bin}/bootgly", "#!/bin/bash\nexec /usr/bin/php /elsewhere/bootgly \"\$@\"\n");
         chmod("{$bin}/bootgly", 0755);

         yield assert(
            assertion: $suggest() === 'php ',
            description: 'a global that pins another kit is not what the tip recommends'
         );

         // @ A copy of the kit launcher on PATH — pinned to its own kit by __DIR__
         file_put_contents("{$bin}/bootgly", (string) file_get_contents(BOOTGLY_ROOT_BASE . '/bootgly'));
         chmod("{$bin}/bootgly", 0755);

         yield assert(
            assertion: $suggest() === 'php ',
            description: 'a launcher copied or linked onto PATH is not the walk-up wrapper'
         );

         // @ The walk-up wrapper — the one `setup` stamps
         file_put_contents("{$bin}/bootgly", "#!/bin/bash -p\n" . KitCommand::WRAPPER_STAMP . "\nexec php \"\$SCRIPT\" \"\$@\"\n");
         chmod("{$bin}/bootgly", 0755);

         yield assert(
            assertion: $suggest() === '',
            description: 'the stamped walk-up wrapper earns the bare `bootgly`'
         );

         // @ No shell to ask — process spawning disabled, as on php-wasm or a
         //   hardened host: the tip is computed, never a fatal error
         $Script = <<<'PHP'
require getenv('SUGGEST_AUTOBOOT');

$tip = Bootgly\commands\KitCommand::suggest();
fwrite(STDERR, "TIP[{$tip}]\n");
PHP;
         $Environment = (array) getenv();
         unset($Environment['BOOTGLY_DOCKER']);
         $Environment['PATH'] = $bin;
         $Environment['SUGGEST_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $Process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=exec,passthru,popen,proc_open,shell_exec,system', '-r', $Script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $Environment
         );
         $report = '';
         $code = -1;
         if (is_resource($Process) === true) {
            stream_get_contents($pipes[1]);
            $report = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($Process);
         }

         yield assert(
            assertion: $code === 0 && str_contains($report, 'TIP[]'),
            description: "without process spawning the PATH walk still finds the wrapper (exit {$code}: {$report})"
         );

         // @ A non-executable `bootgly` earlier on PATH is skipped — the shell
         //   would never run it — so the executable wrapper after it decides
         file_put_contents("{$shadow}/bootgly", "#!/bin/bash\nexec /usr/bin/php /elsewhere/bootgly \"\$@\"\n");
         chmod("{$shadow}/bootgly", 0644);
         putenv("PATH={$shadow}:{$bin}");

         yield assert(
            assertion: $suggest() === '',
            description: 'a non-executable `bootgly` earlier on PATH is not the global'
         );

         // @ A relative entry means the working directory — never consulted,
         //   even when it holds the stamped wrapper
         chdir($bin);
         putenv('PATH=.');

         yield assert(
            assertion: $suggest() === 'php ',
            description: 'a relative PATH entry is never taken for the global'
         );
         chdir($directory);
         putenv("PATH={$bin}");

         // @ Inside a container the launcher on PATH is the image's own — bare,
         //   whatever else PATH holds (the stale wrapper is still there)
         file_put_contents("{$bin}/bootgly", "#!/bin/bash\nexec /usr/bin/php /elsewhere/bootgly \"\$@\"\n");
         putenv('BOOTGLY_DOCKER=1');

         yield assert(
            assertion: $suggest() === '',
            description: 'inside a container the tip is the bare `bootgly` — the image ships the launcher on PATH'
         );
         putenv('BOOTGLY_DOCKER');

         // @ The stamp is what setup writes
         $template = (string) file_get_contents(BOOTGLY_ROOT_BASE . '/Bootgly/commands/templates/bootgly.wrapper.bash');

         yield assert(
            assertion: str_contains($template, KitCommand::WRAPPER_STAMP . "\n"),
            description: 'the wrapper template carries the stamp `suggest()` looks for'
         );
      }
      finally {
         chdir($directory);
         putenv("PATH={$previous}");
         putenv($contained === false ? 'BOOTGLY_DOCKER' : "BOOTGLY_DOCKER={$contained}");
         @unlink("{$bin}/bootgly");
         @unlink("{$shadow}/bootgly");
         @rmdir($bin);
         @rmdir($shadow);
      }
   }
);
