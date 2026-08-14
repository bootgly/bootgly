<?php

namespace Bootgly\commands;


use const PHP_BINARY;
use function assert;
use function bin2hex;
use function escapeshellarg;
use function file_put_contents;
use function random_bytes;
use function rewind;
use function stream_get_contents;
use function substr_count;
use function sys_get_temp_dir;
use function unlink;
use ReflectionMethod;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\Terminal\Output\Region;


return new Test(
   description: 'It should nest child-process output inside the current Output region',
   test: function () {
      // ! A child that prints three lines and exits with a known status —
      //   a PHP script keeps the case portable (no shell builtins involved)
      $script = sys_get_temp_dir() . '/bootgly-execute-' . bin2hex(random_bytes(4)) . '.php';
      file_put_contents(
         $script,
         '<?php echo "first\nsecond\n"; fwrite(STDERR, "third\n"); exit(3);'
      );

      $binary = escapeshellarg(PHP_BINARY);
      $file = escapeshellarg($script);
      $command = "{$binary} {$file}";

      $Execute = new ReflectionMethod(ProjectCommand::class, 'execute');
      $Command = new ProjectCommand;

      // ! The wizard region: a gutter every row re-enters after
      $Host = new Output('php://memory');
      $Region = new Region($Host->stream, '@#Black:│@;', 3);

      // ! The helper resolves the Output at call time (the Wizard swaps this
      //   very property while a step handler runs)
      $Terminal = CLI->Terminal;
      $Restore = $Terminal->Output;
      $Terminal->Output = $Region;

      try {
         $status = $Execute->invoke($Command, $command);
      }
      finally {
         $Terminal->Output = $Restore;
      }

      rewind($Host->stream);
      $painted = (string) stream_get_contents($Host->stream);

      unlink($script);

      yield assert(
         assertion: $status === 3,
         description: 'The child exit status comes back to the caller'
      );

      yield assert(
         assertion: substr_count($painted, 'first') === 1
            && substr_count($painted, 'second') === 1
            && substr_count($painted, 'third') === 1,
         description: 'Both child streams reach the Output — stderr joins stdout'
      );

      yield assert(
         assertion: substr_count($painted, '│') >= 3,
         description: 'Every child line carries the region guide instead of escaping the frame'
      );
   }
);
