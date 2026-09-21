<?php
namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use function assert;
use function chmod;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function putenv;
use function rmdir;
use function str_contains;
use function unlink;
use ReflectionMethod;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * The tips name the launcher that will actually run this kit: bare `bootgly`
 * only when the global on PATH is the walk-up wrapper.
 */
return new Test(
   description: 'tips say `php bootgly` unless the global wrapper on PATH is the walk-up one',
   test: function () {
      $Method = new ReflectionMethod(ProjectsCommand::class, 'suggest');
      $suggest = static fn (): string => (string) $Method->invoke(null);

      // ! A PATH of our own, so `command -v bootgly` sees only what we put there
      $bin = Temporaries::reserve('tips-launcher');
      $previous = (string) getenv('PATH');
      putenv("PATH={$bin}");

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
         file_put_contents("{$bin}/bootgly", "#!/bin/bash -p\n" . ProjectsCommand::WRAPPER_STAMP . "\nexec php \"\$SCRIPT\" \"\$@\"\n");
         chmod("{$bin}/bootgly", 0755);

         yield assert(
            assertion: $suggest() === '',
            description: 'the stamped walk-up wrapper earns the bare `bootgly`'
         );

         // @ The stamp is what setup writes
         $template = (string) file_get_contents(BOOTGLY_ROOT_BASE . '/Bootgly/commands/templates/bootgly.wrapper.bash');

         yield assert(
            assertion: str_contains($template, ProjectsCommand::WRAPPER_STAMP . "\n"),
            description: 'the wrapper template carries the stamp `suggest()` looks for'
         );
      }
      finally {
         putenv("PATH={$previous}");
         @unlink("{$bin}/bootgly");
         @rmdir($bin);
      }
   }
);
