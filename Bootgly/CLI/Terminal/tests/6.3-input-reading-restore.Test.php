<?php

namespace Bootgly\CLI\Terminal;


use const BOOTGLY_ROOT_BASE;
use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use const SIGKILL;
use function fclose;
use function fread;
use function function_exists;
use function getenv;
use function hrtime;
use function is_resource;
use function is_string;
use function posix_kill;
use function preg_match;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function str_contains;
use function stream_set_blocking;
use function usleep;
use Throwable;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `Input::reading()` forks a Client child that switches the terminal to raw
 * mode without echo; when `reading()` returns, the terminal is back in
 * canonical mode with echo, so the application can keep reading lines.
 */
return new Test(
   description: 'It should leave the terminal canonical with echo when reading() returns',
   test: function () {
      // ?
      if (
         function_exists('proc_open') === false
         || function_exists('posix_kill') === false
         || function_exists('pcntl_fork') === false
      ) {
         yield (new Assertion(description: 'reading() restore: proc_open/posix/pcntl unavailable here'))->skip();

         return;
      }

      // ! A child driving reading() on a pseudo-terminal; it reports the modes on stderr
      $Script = <<<'PHP'
require getenv('READING_AUTOBOOT');

use const Bootgly\CLI;

$Mode = static function (string $tag): void {
   $modes = (string) shell_exec('stty -a 2>&1');
   $canonical = preg_match('/(^|\s)-icanon/', $modes) === 1 ? 'RAW' : 'COOKED';
   $echo = preg_match('/(^|\s)-echo(\s|$)/', $modes) === 1 ? 'NOECHO' : 'ECHO';
   fwrite(STDERR, "MODE[{$tag}] {$canonical} {$echo}\n");
};
$Mode('before');
CLI->Terminal->Input->reading(
   CAPI: static function ($read, $write): void {
      $write('done');
      while (true) {
         usleep(100_000);
      }
   },
   SAPI: static function ($reading): void {
      $deadline = hrtime(true) + 5_000_000_000;
      foreach ($reading(1024, 100_000) as $chunk) {
         if (is_string($chunk) && str_contains($chunk, 'done')) {
            break;
         }
         if (hrtime(true) > $deadline) {
            break;
         }
      }
   }
);
$Mode('after');
fwrite(STDERR, "END\n");
PHP;

      $Environment = (array) getenv();
      $Environment['READING_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
      try {
         $Process = proc_open(
            [PHP_BINARY, '-r', $Script],
            [0 => ['pty'], 1 => ['pty'], 2 => ['pty']],
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $Environment
         );
      }
      catch (Throwable) {
         $Process = false;
      }
      // ? A pseudo-terminal
      if (is_resource($Process) === false) {
         yield (new Assertion(description: 'reading() restore: no pseudo-terminal here'))->skip();

         return;
      }

      // @ Collect the child's report until END
      $PID = (int) proc_get_status($Process)['pid'];
      $output = '';
      try {
         stream_set_blocking($Pipes[1], false);
         $deadline = hrtime(true) + 10_000_000_000;
         while (str_contains($output, 'END') === false && hrtime(true) < $deadline) {
            while (is_string($chunk = @fread($Pipes[1], 65_536)) && $chunk !== '') {
               $output .= $chunk;
            }
            usleep(10_000);
         }
      }
      finally {
         if ($PID > 0) {
            @posix_kill($PID, SIGKILL);
         }
         foreach ($Pipes as $Pipe) {
            @fclose($Pipe);
         }
         proc_close($Process);
      }

      $Modes = [
         'before' => preg_match('/MODE\[before\] (\w+ \w+)/', $output, $Before) === 1 ? $Before[1] : null,
         'after' => preg_match('/MODE\[after\] (\w+ \w+)/', $output, $After) === 1 ? $After[1] : null,
      ];

      yield new Assertion(description: 'the terminal is canonical with echo after reading() returns')
         ->expect($Modes, Op::Identical, [
            'before' => 'COOKED ECHO',
            'after' => 'COOKED ECHO',
         ])
         ->assert();
   }
);
