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
use function strpos;
use function sys_get_temp_dir;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should scan directories with filters, icons and ordering',
   test: function () {
      // ! Filesystem fixture
      $root = sys_get_temp_dir() . '/bootgly-fp-scan-' . getmypid();
      if (is_dir($root) === false) {
         mkdir("{$root}/src", 0777, true);
         mkdir("{$root}/zeta");
         mkdir("{$root}/.git");
      }
      file_put_contents("{$root}/src/a.php", '<?php');
      file_put_contents("{$root}/readme.md", '# read');
      file_put_contents("{$root}/notes.txt", 'notes');
      file_put_contents("{$root}/.env", 'secret');

      // ! Filepicker with in-memory streams
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, BOOTGLY_TTY === true ? "\e" : "{$root}/readme.md\n");
      rewind($stream);

      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Filepicker = new Filepicker($Input, $Output);
      $Filepicker->root = $root;
      $Filepicker->extensions = ['md'];

      $picked = $Filepicker->pick();

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      if (BOOTGLY_TTY === true) {
         // @ Valid — interactive browse canceled with Esc
         yield assert(
            assertion: $picked === null && $Filepicker->picked === null,
            description: 'Esc cancels the browse: pick() returns null'
         );
         yield assert(
            assertion: str_contains($output, '📁 src') === true
               && str_contains($output, '📄 readme.md') === true,
            description: 'Directories and files render with their icons'
         );
         yield assert(
            assertion: str_contains($output, '.git') === false && str_contains($output, '.env') === false,
            description: 'Hidden entries are not listed by default'
         );
         yield assert(
            assertion: str_contains($output, 'notes.txt') === false,
            description: 'The extension filter hides non-matching files'
         );
         yield assert(
            assertion: strpos($output, '📁 zeta') < strpos($output, '📄 readme.md'),
            description: 'Directories sort before files'
         );
      }
      else {
         // @ Valid — non-interactive input degrades to a typed path
         yield assert(
            assertion: $picked === realpath("{$root}/readme.md"),
            description: 'Pipes read the path as a typed line (Question semantics)'
         );
      }

      // ! Cleanup
      unlink("{$root}/src/a.php");
      unlink("{$root}/readme.md");
      unlink("{$root}/notes.txt");
      unlink("{$root}/.env");
      rmdir("{$root}/src");
      rmdir("{$root}/zeta");
      rmdir("{$root}/.git");
      rmdir($root);
   }
);
