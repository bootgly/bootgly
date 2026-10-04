<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * The Interactive prompt never blocks the master's supervision: worker deaths
 * are reforked and stop/resume signals are honoured without a keystroke — on
 * libedit and on GNU readline — Ctrl-D stops, TAB completes, and a
 * non-terminal stdin is read as plain lines instead of being spun on.
 */
return new Test(
   description: 'UDP-24: the Interactive prompt reforks, stops and resumes without a keystroke on libedit and GNU readline, and reads a non-terminal stdin as lines',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false
      || is_dir('/proc/self/task') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('CONSOLE_AUTOBOOT');

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;

final class ConsoleProbe extends UDP_Server_CLI
{
   public static function boot (mixed $Environment): void
   {
   }

   public function instance ()
   {
      // @ One line per worker boot; an armed boot fails, as a refused socket would
      $arm = (string) getenv('CONSOLE_ARM');
      if ($arm !== '') {
         file_put_contents("{$arm}.boots", hrtime(true) . "\n", FILE_APPEND);
         clearstatcache();
         if (is_file($arm)) {
            exit(1);
         }
         file_put_contents("{$arm}.ready", "1\n", FILE_APPEND);
      }

      return parent::instance();
   }
}

$Server = new ConsoleProbe(Modes::Interactive);
$Server->configure(new Configs(
   host: '127.0.0.1',
   port: (int) getenv('CONSOLE_PORT'),
   workers: 4,
));
$Server->on(Events::DatagramReceive, static function (string $input): string {
   return (string) getmypid();
});
$Server->start();
PHP;

      /** Live and zombie children of $master (Linux /proc). */
      $Children = static function (int $master): array {
         $Live = [];
         $Zombies = [];
         $raw = @file_get_contents("/proc/{$master}/task/{$master}/children");
         foreach (preg_split('/\s+/', trim((string) $raw)) ?: [] as $child) {
            $stat = $child === '' ? false : @file_get_contents("/proc/{$child}/stat");
            if (is_string($stat) === false) {
               continue;
            }
            $state = substr($stat, (int) strrpos($stat, ')') + 2, 1);
            $state === 'Z' ? $Zombies[] = (int) $child : $Live[] = (int) $child;
         }
         sort($Live);

         return [$Live, $Zombies];
      };
      /** CPU seconds (user + system) $PID used so far. */
      $CPU = static function (int $PID): float {
         $stat = (string) @file_get_contents("/proc/{$PID}/stat");
         $Fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));

         return ((int) ($Fields[11] ?? 0) + (int) ($Fields[12] ?? 0)) / 100;
      };

      /**
       * Start one session-isolated console server on $stdio ('pty', 'null'
       * or 'pipe') and wait for its 4 workers.
       *
       * @return array{0:resource|false,1:array<int,resource>,2:int,3:int,4:string}
       */
      $Start = static function (string $stdio, array $Extra = []) use ($Script, $Children): array {
         $Reservation = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         $port = (int) substr($name, (int) strrpos($name, ':') + 1);
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }

         $Environment = (array) getenv();
         $Environment['CONSOLE_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $Environment['CONSOLE_PORT'] = (string) $port;
         $Process = proc_open(
            [PHP_BINARY, '-r', $Script],
            match ($stdio) {
               'pty' => [0 => ['pty'], 1 => ['pty'], 2 => ['pty']],
               'pipe' => [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
               default => [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            },
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $Extra + $Environment,
         );
         $master = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
         foreach ($Pipes as $index => $Pipe) {
            if ($index !== 0 || $stdio === 'pty') {
               stream_set_blocking($Pipe, false);
            }
         }

         $output = '';
         $deadline = hrtime(true) + 10_000_000_000;
         while (hrtime(true) < $deadline) {
            [$Live, $Zombies] = $Children($master);
            if (count($Live) === 4 && $Zombies === []) {
               break;
            }
            usleep(5_000);
         }

         return [$Process, $Pipes, $master, $port, $output];
      };
      /** Drain the server's output into $output. */
      $Drain = static function (array $Pipes, string &$output): void {
         foreach ([1, 2] as $index) {
            if (isSet($Pipes[$index]) === false || is_resource($Pipes[$index]) === false) {
               continue;
            }
            while (is_string($chunk = @fread($Pipes[$index], 65_536)) && $chunk !== '') {
               $output .= $chunk;
            }
         }
      };
      /** Whether $Condition holds within $seconds, draining meanwhile. */
      $Until = static function (callable $Condition, float $seconds, array $Pipes, string &$output) use ($Drain): bool {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            $Drain($Pipes, $output);
            if ($Condition()) {
               return true;
            }
            usleep(1_000);
         } while (hrtime(true) < $deadline);

         return false;
      };
      /** Whether the master process still runs. */
      $Running = static fn ($Process): bool => is_resource($Process) && proc_get_status($Process)['running'];
      /** Tear the session group down and remove this run's state inodes. */
      $Finish = static function ($Process, array $Pipes, int $master, int $port): bool {
         if ($master > 0) {
            @posix_kill(-$master, SIGKILL);
         }
         foreach ($Pipes as $Pipe) {
            @fclose($Pipe);
         }
         if (is_resource($Process)) {
            proc_close($Process);
         }
         $cleanup = hrtime(true) + 1_000_000_000;
         while ($master > 0 && @posix_kill(-$master, 0) && hrtime(true) < $cleanup) {
            usleep(10_000);
         }
         foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
            if (str_starts_with((string) $file, "ConsoleProbe.{$port}.")) {
               @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
            }
         }

         return $master > 0 && @posix_kill(-$master, 0) === false;
      };

      // @ A non-terminal stdin at EOF: supervised headless, never spun on
      $Observed = [];
      [$Process, $Pipes, $master, $port, $output] = $Start('null');
      try {
         $Until(static fn (): bool => false, 0.5, $Pipes, $output);
         $spent = $CPU($master);
         $Until(static fn (): bool => false, 2.0, $Pipes, $output);
         $Observed['idle at EOF'] = $CPU($master) - $spent < 0.2;
         [$Before] = $Children($master);
         // ! Never PID 0: that is this runner's own process group
         $Before !== [] && posix_kill($Before[0], SIGKILL);
         $Observed['a death is reforked at EOF'] = $Until(static function () use ($Children, $master, $Before): bool {
            [$Live, $Zombies] = $Children($master);

            return count($Live) === 4 && $Zombies === [] && array_diff($Live, $Before) !== [];
         }, 1.5, $Pipes, $output);
      }
      finally {
         $Observed['group clean'] = $Finish($Process, $Pipes, $master, $port);
      }
      yield new Assertion(description: 'with stdin at EOF (/dev/null) the master stays idle and keeps reforking')
         ->expect($Observed, Op::Identical, [
            'idle at EOF' => true,
            'a death is reforked at EOF' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ Piped commands are executed, line by line
      $Observed = [];
      [$Process, $Pipes, $master, $port, $output] = $Start('pipe');
      try {
         fwrite($Pipes[0], "status\nstop\n");
         $Observed['piped stop exits 0'] = $Until(static fn (): bool => $Running($Process) === false, 4.0, $Pipes, $output)
            && proc_get_status($Process)['exitcode'] === 0;
         $Observed['piped status printed'] = str_contains($output, 'Worker #01');
      }
      finally {
         $Observed['group clean'] = $Finish($Process, $Pipes, $master, $port);
      }
      yield new Assertion(description: 'piped status and stop are executed as lines')
         ->expect($Observed, Op::Identical, [
            'piped stop exits 0' => true,
            'piped status printed' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ Piped lines read before a `monitor` run once the console is back
      $Observed = [];
      [$Process, $Pipes, $master, $port, $output] = $Start('pipe');
      try {
         fwrite($Pipes[0], "monitor\nstop\n");
         $Until(static fn (): bool => false, 1.0, $Pipes, $output);
         // @ Monitor → Interactive
         posix_kill($master, SIGTSTP);
         $Observed['the piped stop after monitor exits 0'] = $Until(static fn (): bool => $Running($Process) === false, 4.0, $Pipes, $output)
            && proc_get_status($Process)['exitcode'] === 0;
      }
      finally {
         $Observed['group clean'] = $Finish($Process, $Pipes, $master, $port);
      }
      yield new Assertion(description: 'piped lines read before a monitor round trip still run')
         ->expect($Observed, Op::Identical, [
            'the piped stop after monitor exits 0' => true,
            'group clean' => true,
         ])
         ->assert();

      // ? A pseudo-terminal
      try {
         $Probe = proc_open(['true'], [0 => ['pty'], 1 => ['pty'], 2 => ['pty']], $Ends);
         $terminal = is_resource($Probe) && proc_close($Probe) === 0;
      }
      catch (Throwable) {
         $terminal = false;
      }
      if ($terminal === false || function_exists('readline_callback_handler_install') === false) {
         yield (new Assertion(description: 'terminal legs: no pseudo-terminal or no readline here'))->skip();

         return;
      }

      // @ Terminal: worker deaths and stop/resume signals need no keystroke
      $Observed = [];
      [$Process, $Pipes, $master, $port, $output] = $Start('pty');
      try {
         // # Every worker killed 2 ms apart
         [$Before] = $Children($master);
         foreach ($Before as $PID) {
            posix_kill($PID, SIGKILL);
            usleep(2_000);
         }
         $Observed['a burst of deaths is reforked'] = $Until(static function () use ($Children, $master, $Before): bool {
            [$Live, $Zombies] = $Children($master);

            return count($Live) === 4 && $Zombies === [] && array_intersect($Live, $Before) === [];
         }, 1.5, $Pipes, $output);

         // # A worker reforked under the armed prompt exits through PHP: the
         //   operator's terminal stays in the prompt's raw mode
         [$Revived] = $Children($master);
         $Revived !== [] && posix_kill($Revived[0], SIGTERM);
         $Until(static fn (): bool => false, 0.5, $Pipes, $output);
         $tty = (string) @readlink("/proc/{$master}/fd/0");
         $Observed['a revived worker exiting keeps the terminal raw'] = $tty !== ''
            && str_contains((string) shell_exec('stty -F ' . escapeshellarg($tty) . ' -a 2>/dev/null'), '-icanon');

         // # A resume after a typed pause
         fwrite($Pipes[0], "pause\n");
         $Until(static fn (): bool => false, 1.0, $Pipes, $output);
         $mark = strlen($output);
         posix_kill($master, SIGCONT);
         $Observed['SIGCONT resumes'] = $Until(static function () use (&$output, $mark): bool {
            return str_contains(substr($output, $mark), 'Resuming');
         }, 1.5, $Pipes, $output);

         // # A stop that lands while the master reforks
         [$Before] = $Children($master);
         $Before !== [] && posix_kill($Before[0], SIGKILL);
         $replaced = $Until(static function () use ($Children, $master, $Before): bool {
            [$Live] = $Children($master);

            return array_diff($Live, $Before) !== [];
         }, 1.5, $Pipes, $output);
         posix_kill($master, SIGTERM);
         $Observed['a stop during a refork is honoured at once'] = $replaced
            && $Until(static fn (): bool => $Running($Process) === false, 0.4, $Pipes, $output);
      }
      finally {
         $Observed['group clean'] = $Finish($Process, $Pipes, $master, $port);
      }
      yield new Assertion(description: 'on a terminal, deaths are reforked and stop/resume signals are honoured without a keystroke')
         ->expect($Observed, Op::Identical, [
            'a burst of deaths is reforked' => true,
            'a revived worker exiting keeps the terminal raw' => true,
            'SIGCONT resumes' => true,
            'a stop during a refork is honoured at once' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ Terminal: TAB completes in the prompt's scope; Ctrl-D stops
      $Observed = [];
      [$Process, $Pipes, $master, $port, $output] = $Start('pty');
      try {
         // ! Keys typed before the prompt is armed meet the kernel's own
         //   line discipline: wait for the prompt
         $Until(static function () use (&$output): bool {
            return str_contains($output, '>_: ');
         }, 5.0, $Pipes, $output);
         fwrite($Pipes[0], "\tsta\t");
         $Until(static fn (): bool => false, 0.5, $Pipes, $output);
         $Observed['TAB keeps the master'] = $Running($Process);
         // # A unique completion, then TAB on the empty word after it; then a
         //   regular-expression metacharacter in the completed text
         fwrite($Pipes[0], "\x15sto\t\t");
         $Until(static fn (): bool => false, 0.5, $Pipes, $output);
         fwrite($Pipes[0], "\x15s/\t");
         $Until(static fn (): bool => false, 0.5, $Pipes, $output);
         $Observed['TAB after a unique completion keeps the master'] = $Running($Process);
         // # TAB still completes: `hel` becomes `help`, run by Enter
         fwrite($Pipes[0], "\x15hel\t\n");
         $Observed['TAB completes a command'] = $Until(static function () use (&$output): bool {
            return str_contains($output, 'Stop the Server and all workers');
         }, 2.0, $Pipes, $output);

         // # Ctrl-V makes libedit read the next key inside the call: a worker
         //   death then is reforked — never taken for a Ctrl-D
         [$Before] = $Children($master);
         fwrite($Pipes[0], "\x16");
         $Until(static fn (): bool => false, 0.5, $Pipes, $output);
         // ! Never PID 0: that is this runner's own process group
         $Before !== [] && posix_kill($Before[0], SIGKILL);
         $Observed['a death after Ctrl-V is reforked, the master kept'] = $Until(static function () use ($Children, $master, $Before): bool {
            [$Live, $Zombies] = $Children($master);

            return count($Live) === 4 && $Zombies === [] && array_diff($Live, $Before) !== [];
         }, 2.0, $Pipes, $output) && $Running($Process);
         // ! The prompt is re-armed on the next turn: keys typed before that
         //   meet the kernel's line discipline
         $Until(static fn (): bool => false, 1.0, $Pipes, $output);

         // # Clear the line (Ctrl-U), then Ctrl-D on the empty line
         fwrite($Pipes[0], "\x15\x04");
         $Observed['Ctrl-D stops with exit 0'] = $Until(static fn (): bool => $Running($Process) === false, 2.0, $Pipes, $output)
            && proc_get_status($Process)['exitcode'] === 0;
         $Observed['Ctrl-D stopped the workers'] = str_contains($output, 'worker(s) stopped!');
      }
      finally {
         $Observed['group clean'] = $Finish($Process, $Pipes, $master, $port);
      }
      yield new Assertion(description: 'on a terminal, TAB keeps the master and Ctrl-D stops it cleanly')
         ->expect($Observed, Op::Identical, [
            'TAB keeps the master' => true,
            'TAB after a unique completion keeps the master' => true,
            'TAB completes a command' => true,
            'a death after Ctrl-V is reforked, the master kept' => true,
            'Ctrl-D stops with exit 0' => true,
            'Ctrl-D stopped the workers' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ Terminal: a slot whose boots keep failing is backed off while the
      //   prompt idles — never spun on, never waiting for a keystroke — and
      //   serves again once its boot succeeds (TCP-24 behind the prompt)
      $Observed = [];
      $arm = BOOTGLY_STORAGE_DIR . 'console-arm-' . getmypid() . '-' . hrtime(true);
      [$Process, $Pipes, $master, $port, $output] = $Start('pty', ['CONSOLE_ARM' => $arm]);
      try {
         // ! Every initial boot past its arm check first: a boot still before
         //   it would fail too and put a second slot in the crash loop
         $Until(static fn (): bool => count(@file("{$arm}.ready") ?: []) >= 4, 5.0, $Pipes, $output);
         [$Before] = $Children($master);
         touch($arm);
         $since = (int) hrtime(true);
         $spent = $CPU($master);
         // ! Never PID 0: that is this runner's own process group
         $Before !== [] && posix_kill($Before[0], SIGKILL);
         $Until(static fn (): bool => false, 3.0, $Pipes, $output);
         $Boots = array_filter(
            @file("{$arm}.boots", FILE_IGNORE_NEW_LINES) ?: [],
            static fn (string $line): bool => (int) $line >= $since
         );
         $Observed['backed off without a keystroke (4 to 7 boots in 3 s)'] = count($Boots) >= 4 && count($Boots) <= 7;
         $Observed['idle while backing off'] = $CPU($master) - $spent < 0.3;
         @unlink($arm);
         $Full = static function () use ($Children, $master): bool {
            [$Live, $Zombies] = $Children($master);

            return count($Live) === 4 && $Zombies === [];
         };
         $Observed['the slot serves again without a keystroke'] = $Until($Full, 6.0, $Pipes, $output)
            && $Until(static fn (): bool => false, 0.3, $Pipes, $output) === false
            && $Full();
      }
      finally {
         $Observed['group clean'] = $Finish($Process, $Pipes, $master, $port);
         @unlink($arm);
         @unlink("{$arm}.boots");
         @unlink("{$arm}.ready");
      }
      yield new Assertion(description: 'on a terminal, a slot whose boots keep failing is backed off without a spin or a keystroke')
         ->expect($Observed, Op::Identical, [
            'backed off without a keystroke (4 to 7 boots in 3 s)' => true,
            'idle while backing off' => true,
            'the slot serves again without a keystroke' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ Terminal on GNU readline (preloaded): its blocking read restarts on
      //   every signal but SIGHUP/SIGTERM
      $GNU = null;
      foreach (['/usr/lib64/libreadline.so.8', '/usr/lib/x86_64-linux-gnu/libreadline.so.8', '/lib/x86_64-linux-gnu/libreadline.so.8'] as $library) {
         if (is_file($library)) {
            $GNU = $library;
            break;
         }
      }
      if ($GNU === null) {
         yield (new Assertion(description: 'GNU readline leg: no libreadline.so.8 here'))->skip();

         return;
      }
      // ? The preload must actually interpose it in PHP's readline (some builds refuse it)
      $Environment = (array) getenv();
      $Environment['LD_PRELOAD'] = $GNU;
      $Probe = proc_open(
         [PHP_BINARY, '-r', 'echo (int) (function_exists("readline_info") && readline_info("library_version") !== "EditLine wrapper");'],
         [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
         $Ends,
         null,
         $Environment,
      );
      $mapped = is_resource($Probe) && stream_get_contents($Ends[1]) === '1';
      if (is_resource($Probe)) {
         fclose($Ends[1]);
         proc_close($Probe);
      }
      if ($mapped === false) {
         yield (new Assertion(description: 'GNU readline leg: the preload does not interpose libreadline here'))->skip();

         return;
      }
      $Observed = [];
      [$Process, $Pipes, $master, $port, $output] = $Start('pty', ['LD_PRELOAD' => $GNU]);
      try {
         $Observed['GNU readline mapped'] = str_contains((string) @file_get_contents("/proc/{$master}/maps"), 'libreadline.so');
         [$Before] = $Children($master);
         $Before !== [] && posix_kill($Before[0], SIGKILL);
         $Observed['a death is reforked'] = $Until(static function () use ($Children, $master, $Before): bool {
            [$Live, $Zombies] = $Children($master);

            return count($Live) === 4 && $Zombies === [] && array_diff($Live, $Before) !== [];
         }, 1.5, $Pipes, $output);
         posix_kill($master, SIGINT);
         $Observed['SIGINT stops'] = $Until(static fn (): bool => $Running($Process) === false, 1.5, $Pipes, $output)
            && proc_get_status($Process)['exitcode'] === 0;
      }
      finally {
         $Observed['group clean'] = $Finish($Process, $Pipes, $master, $port);
      }
      yield new Assertion(description: 'on GNU readline, a death is reforked and SIGINT stops without a keystroke')
         ->expect($Observed, Op::Identical, [
            'GNU readline mapped' => true,
            'a death is reforked' => true,
            'SIGINT stops' => true,
            'group clean' => true,
         ])
         ->assert();
   }),
);
