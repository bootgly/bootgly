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

      // ! A PATH of our own, so `command -v bootgly` sees only what we put there
      $bin = Temporaries::reserve('tips-launcher');
      $previous = (string) getenv('PATH');
      $contained = getenv('BOOTGLY_DOCKER');
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
         putenv("PATH={$previous}");
         putenv($contained === false ? 'BOOTGLY_DOCKER' : "BOOTGLY_DOCKER={$contained}");
         @unlink("{$bin}/bootgly");
         @rmdir($bin);
      }
   }
);
