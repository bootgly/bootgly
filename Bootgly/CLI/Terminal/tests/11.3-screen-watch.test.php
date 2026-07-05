<?php

namespace Bootgly\CLI\Terminal;


use const SIGWINCH;
use function assert;
use function function_exists;
use function pcntl_signal_dispatch;
use function posix_getpid;
use function posix_kill;
use function putenv;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should watch terminal resizes (SIGWINCH) and forward the measured size to the handler',
   test: function () {
      // ? Signal handling requires process control
      if (
         function_exists('pcntl_signal') === false
         || function_exists('posix_kill') === false
      ) {
         yield assert(
            assertion: true,
            description: 'Skipped: pcntl/posix unavailable'
         );

         return;
      }

      // ! Screen with an in-memory Output + deterministic size
      $Output = new Output('php://memory');
      $Screen = new Screen($Output);

      putenv('COLUMNS=101');
      putenv('LINES=42');

      // @ Watch
      $resized = null;
      $installed = $Screen->watch(function (int $columns, int $lines) use (&$resized): void {
         $resized = [$columns, $lines];
      });

      yield assert(
         assertion: $installed === true,
         description: 'Watcher installed'
      );

      // @ Simulate a resize
      posix_kill(posix_getpid(), SIGWINCH);
      pcntl_signal_dispatch();

      yield assert(
         assertion: $resized === [101, 42],
         description: 'Handler received the measured size: '
            . ($resized[0] ?? '?') . '×' . ($resized[1] ?? '?')
      );

      // @ Unwatch (restore the default signal behavior)
      $restored = $Screen->watch(null);

      yield assert(
         assertion: $restored === true,
         description: 'Watcher restored to the default signal behavior'
      );

      // @ Restore environment
      putenv('COLUMNS');
      putenv('LINES');
   }
);
