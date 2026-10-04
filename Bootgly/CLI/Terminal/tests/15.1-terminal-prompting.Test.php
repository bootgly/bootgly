<?php

namespace Bootgly\CLI\Terminal;


use const BOOTGLY_ROOT_BASE;
use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use const SIGKILL;
use function assert;
use function fclose;
use function fread;
use function function_exists;
use function fwrite;
use function getenv;
use function hrtime;
use function is_resource;
use function is_string;
use function posix_kill;
use function preg_match;
use function preg_match_all;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function str_contains;
use function stream_set_blocking;
use function strpos;
use function substr;
use function substr_count;
use function usleep;
use Throwable;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `Terminal::prompting()` yields whole lines and never blocks its caller:
 * `$Supervise` runs before every wait and bounds it; a plain stdin is read
 * as lines (a split write and an unterminated last line included) and its
 * EOF keeps the prompt supervising; `false` from `$Supervise` ends it; on a
 * terminal, Ctrl-D ends it and `disarm()` leaves the handler removed.
 */
return new Test(
   description: 'It should prompt for lines without blocking the supervision between waits',
   test: function () {
      // ?
      if (function_exists('proc_open') === false || function_exists('posix_kill') === false) {
         yield (new Assertion(description: 'prompting legs: proc_open/posix unavailable here'))->skip();

         return;
      }

      // ! A child driving prompting() on its own stdin; it reports on stderr
      $Script = <<<'PHP'
require getenv('PROMPT_AUTOBOOT');

use Bootgly\CLI\Terminal;

$Terminal = new Terminal;
$turns = 0;
$limit = (int) getenv('PROMPT_TURNS');
$started = hrtime(true);
$Supervise = static function () use (&$turns, $limit): int|false {
   $turns++;
   fwrite(STDERR, "TURN\n");

   return $turns > $limit ? false : 100_000;
};
foreach ($Terminal->prompting($Supervise) as $line) {
   fwrite(STDERR, "LINE[{$line}]\n");
}
$elapsed = (int) ((hrtime(true) - $started) / 1_000_000);
$armed = $Terminal->armed ? 1 : 0;
fwrite(STDERR, "END turns={$turns} armed={$armed} ms={$elapsed}\n");
PHP;
      /** Run the child on $stdio, feed it, collect its stderr until END. */
      $Run = static function (string $stdio, int $turns, callable $Feed) use ($Script): string {
         $Environment = (array) getenv();
         $Environment['PROMPT_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $Environment['PROMPT_TURNS'] = (string) $turns;
         $Process = proc_open(
            [PHP_BINARY, '-r', $Script],
            $stdio === 'pty'
               ? [0 => ['pty'], 1 => ['pty'], 2 => ['pty']]
               : [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $Environment
         );
         if (is_resource($Process) === false) {
            return '';
         }
         $PID = (int) proc_get_status($Process)['pid'];
         stream_set_blocking($Pipes[1], false);
         stream_set_blocking($Pipes[2], false);
         $output = '';
         $Drain = static function () use ($Pipes, &$output): void {
            foreach ([1, 2] as $index) {
               while (is_string($chunk = @fread($Pipes[$index], 65_536)) && $chunk !== '') {
                  $output .= $chunk;
               }
            }
         };
         try {
            $Feed($Pipes, $Drain, $output);
            $deadline = hrtime(true) + 8_000_000_000;
            while (str_contains($output, 'END ') === false && hrtime(true) < $deadline) {
               $Drain();
               usleep(10_000);
            }
            $Drain();
         }
         finally {
            if ($PID > 0) {
               @posix_kill($PID, SIGKILL);
            }
            foreach ($Pipes as $Pipe) {
               // ? A feed may have closed stdin already
               if (is_resource($Pipe)) {
                  fclose($Pipe);
               }
            }
            proc_close($Process);
         }

         return $output;
      };

      // @ Plain stdin: a line split across writes, an unterminated last line, then EOF
      $output = $Run('pipe', 30, static function (array $Pipes, callable $Drain, string &$output): void {
         // ! The child is prompting before the first write (its start-up
         //   time must not eat the half line's wait)
         $deadline = hrtime(true) + 5_000_000_000;
         while (str_contains($output, 'TURN') === false && hrtime(true) < $deadline) {
            $Drain();
            usleep(5_000);
         }
         @fwrite($Pipes[0], "one\ntw");
         usleep(300_000);
         @fwrite($Pipes[0], "o\nthree");
         usleep(300_000);
         fclose($Pipes[0]);
      });
      preg_match_all('/LINE\[([^\]]*)\]/', $output, $Lines);
      yield assert(
         assertion: $Lines[1] === ['one', 'two', 'three'],
         description: 'A plain stdin is yielded as whole lines, the unterminated last one included'
      );
      // ! The half line ('tw') must never block the supervision: $Supervise
      //   keeps running while it waits for the rest
      $start = strpos($output, 'LINE[one]');
      $end = strpos($output, 'LINE[two]');
      yield assert(
         assertion: $start !== false && $end !== false
            && substr_count(substr($output, $start, $end - $start), 'TURN') >= 3,
         description: 'A half line never blocks $Supervise while the rest is awaited'
      );
      yield assert(
         assertion: str_contains($output, 'END turns=31 armed=0'),
         description: 'After EOF the prompt keeps calling $Supervise until it returns false'
      );

      // @ No input at all: $Supervise bounds every wait and ends the prompt
      $output = $Run('pipe', 3, static function (): void {});
      $bounded = false;
      if (preg_match('/END turns=4 armed=0 ms=(\d+)/', $output, $Match) === 1) {
         $bounded = (int) $Match[1] >= 250 && (int) $Match[1] < 1_500;
      }
      yield assert(
         assertion: $bounded,
         description: 'Each wait lasts what $Supervise allows, and false ends the prompt'
      );

      // @ Terminal: readline edits the line; Ctrl-D on an empty line ends the prompt
      try {
         $Probe = proc_open(['true'], [0 => ['pty'], 1 => ['pty'], 2 => ['pty']], $Ends);
         $terminal = is_resource($Probe) && proc_close($Probe) === 0;
      }
      catch (Throwable) {
         $terminal = false;
      }
      if ($terminal === false || function_exists('readline_callback_handler_install') === false) {
         yield (new Assertion(description: 'terminal leg: no pseudo-terminal or no readline here'))->skip();

         return;
      }
      $output = $Run('pty', 100, static function (array $Pipes, callable $Drain, string &$output): void {
         $deadline = hrtime(true) + 5_000_000_000;
         while (str_contains($output, '>_: ') === false && hrtime(true) < $deadline) {
            $Drain();
            usleep(10_000);
         }
         @fwrite($Pipes[0], "help\n");
         usleep(300_000);
         @fwrite($Pipes[0], "\x04");
      });
      yield assert(
         assertion: str_contains($output, 'LINE[help]'),
         description: 'A typed line is yielded on a terminal'
      );
      yield assert(
         assertion: preg_match('/END turns=(\d+) armed=0/', $output, $Match) === 1 && (int) $Match[1] < 100,
         description: 'Ctrl-D on an empty terminal line ends the prompt, disarmed'
      );
   }
);
