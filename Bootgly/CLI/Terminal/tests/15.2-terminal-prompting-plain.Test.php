<?php

namespace Bootgly\CLI\Terminal;


use const BOOTGLY_ROOT_BASE;
use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use const SIGKILL;
use function array_merge;
use function fclose;
use function fread;
use function function_exists;
use function fwrite;
use function getenv;
use function hrtime;
use function implode;
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
use function substr_count;
use function usleep;
use Throwable;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * Without ext-readline a terminal is still prompted: `prompting()` writes
 * the `>_: ` prompt once per line and reads the lines the terminal edits;
 * Ctrl-D on an empty line ends it. `$editing` tells whether readline edits
 * the line: only on a terminal with ext-readline loaded.
 */
return new Test(
   description: 'It should prompt a terminal without readline once per line and report whether readline edits',
   test: function () {
      // ?
      if (function_exists('proc_open') === false || function_exists('posix_kill') === false) {
         yield (new Assertion(description: 'plain terminal legs: proc_open/posix unavailable here'))->skip();

         return;
      }
      // ? A pseudo-terminal
      try {
         $Probe = proc_open(['true'], [0 => ['pty'], 1 => ['pty'], 2 => ['pty']], $Ends);
         $terminal = is_resource($Probe) && proc_close($Probe) === 0;
      }
      catch (Throwable) {
         $terminal = false;
      }
      if ($terminal === false) {
         yield (new Assertion(description: 'plain terminal legs: no pseudo-terminal here'))->skip();

         return;
      }

      // ! A child driving prompting() on its own stdin; it reports on stderr
      $Script = <<<'PHP'
require getenv('PROMPT_AUTOBOOT');

use Bootgly\CLI\Terminal;

$Terminal = new Terminal;
$editing = $Terminal->editing ? 1 : 0;
fwrite(STDERR, "EDITING={$editing}\n");
$turns = 0;
$Supervise = static function () use (&$turns): int|false {
   $turns++;

   return $turns > 200 ? false : 100_000;
};
foreach ($Terminal->prompting($Supervise) as $line) {
   fwrite(STDERR, "LINE[{$line}]\n");
}
$armed = $Terminal->armed ? 1 : 0;
fwrite(STDERR, "END armed={$armed}\n");
PHP;
      // ! Every readline function the extension provides, so the child takes
      //   the branch a PHP without ext-readline takes
      $disabled = implode(',', [
         'readline', 'readline_add_history', 'readline_callback_handler_install',
         'readline_callback_handler_remove', 'readline_callback_read_char',
         'readline_completion_function', 'readline_info',
      ]);
      /** Run the child on $stdio, feed it, collect its output until END. */
      $Run = static function (string $stdio, bool $readline, callable $Feed) use ($Script, $disabled): string {
         $Environment = (array) getenv();
         $Environment['PROMPT_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $Process = proc_open(
            array_merge([PHP_BINARY], $readline ? [] : ['-d', "disable_functions={$disabled}"], ['-r', $Script]),
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
         /** Drain until $needle shows up (bounded). */
         $Until = static function (string $needle, int $count = 1) use ($Drain, &$output): void {
            $deadline = hrtime(true) + 5_000_000_000;
            while (substr_count($output, $needle) < $count && hrtime(true) < $deadline) {
               $Drain();
               usleep(10_000);
            }
         };
         try {
            $Feed($Pipes, $Until);
            $Until('END ');
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

      // @ A terminal without readline: one prompt per line, then Ctrl-D
      $output = $Run('pty', false, static function (array $Pipes, callable $Until): void {
         $Until('>_: ');
         @fwrite($Pipes[0], "one\n");
         $Until('LINE[one]');
         $Until('>_: ', 2);
         @fwrite($Pipes[0], "two\n");
         $Until('LINE[two]');
         $Until('>_: ', 3);
         @fwrite($Pipes[0], "\x04");
      });
      preg_match_all('/LINE\[([^\]]*)\]/', $output, $Lines);
      yield new Assertion(description: 'without readline a terminal is prompted once per line and Ctrl-D ends the prompt')
         ->expect([
            'editing' => str_contains($output, 'EDITING=0'),
            'lines' => $Lines[1],
            'prompts' => substr_count($output, '>_: '),
            'ended disarmed' => str_contains($output, 'END armed=0'),
            'Ctrl-D ends the prompt line' => preg_match('/>_: \r?\nEND /', $output),
         ], Op::Identical, [
            'editing' => true,
            'lines' => ['one', 'two'],
            'prompts' => 3,
            'ended disarmed' => true,
            'Ctrl-D ends the prompt line' => 1,
         ])
         ->assert();

      // @ A pipe is never edited nor prompted
      $output = $Run('pipe', true, static function (array $Pipes): void {
         @fwrite($Pipes[0], "one\n");
         fclose($Pipes[0]);
      });
      // ! The pipe's EOF leaves the prompt supervising until its turns run out
      yield new Assertion(description: 'a pipe is read without readline editing and without a prompt')
         ->expect([
            'editing' => str_contains($output, 'EDITING=0'),
            'line' => str_contains($output, 'LINE[one]'),
            'prompts' => substr_count($output, '>_: '),
         ], Op::Identical, [
            'editing' => true,
            'line' => true,
            'prompts' => 0,
         ])
         ->assert();

      // ? ext-readline loaded here
      if (function_exists('readline_callback_handler_install') === false) {
         yield (new Assertion(description: 'readline leg: ext-readline is not loaded here'))->skip();

         return;
      }
      // @ A terminal with readline is edited
      $output = $Run('pty', true, static function (array $Pipes, callable $Until): void {
         $Until('>_: ');
         @fwrite($Pipes[0], "\x04");
      });
      yield new Assertion(description: 'a terminal with ext-readline is edited through readline')
         ->expect(preg_match('/EDITING=1/', $output), Op::Identical, 1)
         ->assert();
   }
);
