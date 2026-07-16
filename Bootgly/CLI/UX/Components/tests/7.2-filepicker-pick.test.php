<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function assert;
use function file_put_contents;
use function fopen;
use function fwrite;
use function getmypid;
use function is_dir;
use function mkdir;
use function realpath;
use function rewind;
use function rmdir;
use function str_contains;
use function stream_get_contents;
use function sys_get_temp_dir;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should pick files by drilling and directories in directory mode',
   test: function () {
      // ! Filesystem fixture
      $root = sys_get_temp_dir() . '/bootgly-fp-pick-' . getmypid();
      if (is_dir($root) === false) {
         mkdir("{$root}/src", 0777, true);
         mkdir("{$root}/zeta");
      }
      file_put_contents("{$root}/src/a.php", '<?php');
      file_put_contents("{$root}/src/b.txt", 'b');
      file_put_contents("{$root}/readme.md", '# read');

      // ! Filepicker factory with in-memory streams
      $make = static function (string $keys) use ($root): array {
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, $keys);
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Filepicker = new Filepicker($Input, $Output);
         $Filepicker->root = $root;

         // :
         return [$Filepicker, $Output];
      };

      if (BOOTGLY_TTY === true) {
         // @ File mode — Enter on a directory drills; Enter on a file confirms
         // Rows: root(0), src(1), zeta(2), readme.md(3)
         // Down (src) → Enter (drills: expands src) → Down (a.php) → Enter (confirms)
         [$Filepicker, $Output] = $make("\e[B\n\e[B\n");

         $picked = $Filepicker->pick();

         yield assert(
            assertion: $picked === realpath("{$root}/src/a.php"),
            description: 'Enter drills into the directory and confirms the file'
         );
         yield assert(
            assertion: $Filepicker->picked === $picked,
            description: 'The picked path stays exposed on $picked'
         );

         // @ Directory mode — files are not listed and Enter selects the directory
         [$Directories, $Output] = $make("\e[B\n");
         $Directories->directories = true;

         $picked = $Directories->pick();

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $picked === realpath("{$root}/src"),
            description: 'Directory mode: Enter selects the aimed directory'
         );
         yield assert(
            assertion: str_contains($output, 'readme.md') === false,
            description: 'Directory mode lists no files'
         );

         // @ Unreachable root — warns loud instead of a silent cancel
         [$Missing, $Output] = $make('');
         $Missing->root = "{$root}/nowhere";

         $picked = $Missing->pick();

         rewind($Output->stream);
         $warning = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $picked === null && str_contains($warning, 'unreachable root') === true,
            description: 'An unreachable root picks nothing and warns'
         );
      }
      else {
         // @ Non-interactive — an invalid typed path picks nothing
         [$Filepicker] = $make("nope-does-not-exist\n");

         yield assert(
            assertion: $Filepicker->pick() === null,
            description: 'Pipes: a non-existing typed path returns null'
         );

         // @ Non-interactive — an empty line picks nothing (no cwd leak)
         [$Empty] = $make("\n");

         yield assert(
            assertion: $Empty->pick() === null,
            description: 'Pipes: an empty line returns null instead of the cwd'
         );
      }

      // ! Cleanup
      unlink("{$root}/src/a.php");
      unlink("{$root}/src/b.txt");
      unlink("{$root}/readme.md");
      rmdir("{$root}/src");
      rmdir("{$root}/zeta");
      rmdir($root);
   }
);
