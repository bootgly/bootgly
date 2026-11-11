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
use Bootgly\WPI\Interfaces\UDP_Server_CLI;


/**
 * What a server catches from application code and survives — here a handler
 * on the worker's loop that throws — is reported through `Throwables::notify()`.
 * A UDP server logs that report to the `exceptions` channel, as every other
 * server does.
 *
 * The server runs in its own session, in Interactive mode with one worker;
 * the worker traps SIGWINCH on its loop with a handler that throws.
 */
return new Test(
   description: 'A UDP server should log to the exceptions channel a failure it catches and survives',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('REPORTED_AUTOBOOT');

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Events\Signalling;
use Bootgly\ACI\Process;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;

// A handler on the worker's loop that throws: the server catches it, reports
// it and goes on. SIGWINCH is no server signal, and ignored when not trapped.
function arm (object $Loop): void
{
   $kind = (string) getenv('REPORTED_KIND');
   if ($Loop instanceof Signalling) {
      $Loop->trap(SIGWINCH, static function () use ($kind): void {
         throw new RuntimeException("reported-failure-{$kind}");
      });
   }
   file_put_contents((string) getenv('REPORTED_NOTES'), json_encode([
      'boot' => posix_getpid(),
      'reporters' => count(Throwables::$reporters),
   ]) . "\n", FILE_APPEND | LOCK_EX);
}

final class ReportedUDP extends UDP_Server_CLI
{
   public static function boot (mixed $Environment): void
   {
   }

   protected function work (Process $Process, int $index, null|Closure $Publish = null): void
   {
      arm(self::$Event);

      parent::work($Process, $index, $Publish);
   }
}

$Server = new ReportedUDP(Modes::Interactive);
$Server->configure(new UDP_Server_CLI\Configs(host: '127.0.0.1', port: (int) getenv('REPORTED_PORT'), workers: 1));
$Server->on(UDP_Server_CLI\Events::DatagramReceive, static fn (string $input): string => 'ok');
$Server->start();
PHP;

      /**
       * Boot one server of the given kind, make its worker's handler throw,
       * stop it; what was observed.
       *
       * @return array<string,bool>
       */
      $Serve = static function (string $kind) use ($Script): array {
         // @ Reserve a free loopback port.
         $Reservation = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         $port = (int) substr($name, (int) strrpos($name, ':') + 1);
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }
         $notes = (string) tempnam(sys_get_temp_dir(), 'bootgly-reported-failure-');

         $Environment = (array) getenv();
         $Environment['REPORTED_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $Environment['REPORTED_PORT'] = (string) $port;
         $Environment['REPORTED_NOTES'] = $notes;
         $Environment['REPORTED_KIND'] = $kind;
         $Process = proc_open(
            [PHP_BINARY, '-d', 'opcache.jit=0', '-r', $Script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $Environment,
         );
         $master = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
         stream_set_blocking($Pipes[1], false);
         stream_set_blocking($Pipes[2], false);
         $output = '';
         // ! What the server wrote so far, without the terminal's colours
         $Read = static function () use (&$Pipes, &$output): string {
            $output .= (string) stream_get_contents($Pipes[1]) . (string) stream_get_contents($Pipes[2]);

            return (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
         };
         /** The worker's boot note, once it is there. */
         $Boot = static function () use ($notes): null|array {
            foreach ((array) @file($notes, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
               $Record = json_decode((string) $line, true);
               if (is_array($Record) && isset($Record['boot'])) {
                  return $Record;
               }
            }

            return null;
         };
         $record = "exceptions.ERROR: reported-failure-{$kind}";

         $Observed = [];
         $clean = false;
         try {
            $deadline = hrtime(true) + 10_000_000_000;
            while ($Boot() === null && hrtime(true) < $deadline) {
               $Read();
               usleep(20_000);
            }
            $worker = (int) ($Boot()['boot'] ?? 0);
            $Observed['the worker booted'] = $worker > 0;

            // @ The handler throws on the worker's loop: trapped before the
            //   boot note, so one signal is enough — it waits for the loop
            if ($worker > 0) {
               posix_kill($worker, SIGWINCH);
            }
            $deadline = hrtime(true) + 5_000_000_000;
            while ($worker > 0 && str_contains($Read(), $record) === false && hrtime(true) < $deadline) {
               usleep(20_000);
            }
            $Observed['the failure is logged on the exceptions channel'] = str_contains($Read(), $record);
            $Observed['the worker went on'] = $worker > 0 && posix_kill($worker, 0);

            // @ A fast stop from the operator ends the master and its worker
            posix_kill($master, SIGTERM);
            $deadline = hrtime(true) + 8_000_000_000;
            $status = proc_get_status($Process);
            while ($status['running'] && hrtime(true) < $deadline) {
               usleep(20_000);
               $status = proc_get_status($Process);
            }
            $written = $Read();
            // ! One record for the one throw, and nothing else: no crash, no
            //   uncaught
            $Observed['one record for the one throw'] = substr_count($written, $record) === 1
               && substr_count($written, 'reported-failure-') === 1;
            $Observed['no crash logged'] = str_contains($written, 'crashed') === false
               && str_contains($written, 'Uncaught') === false;
         }
         finally {
            // ! The whole session group; never PID 0/-1 (this runner's group).
            if ($master > 0) {
               posix_kill(-$master, SIGKILL);
            }
            foreach ($Pipes as $Pipe) {
               @fclose($Pipe);
            }
            if (is_resource($Process)) {
               proc_close($Process);
            }
            $cleanup = hrtime(true) + 1_000_000_000;
            while ($master > 0 && posix_kill(-$master, 0) && hrtime(true) < $cleanup) {
               usleep(10_000);
            }
            $clean = $master > 0 && posix_kill(-$master, 0) === false;
            foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
               if (str_starts_with((string) $file, 'Reported') && str_contains((string) $file, ".{$port}.")) {
                  @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
               }
            }
            @unlink($notes);
         }
         $Observed['group clean'] = $clean;

         return $Observed;
      };
      $Expected = [
         'the worker booted' => true,
         'the failure is logged on the exceptions channel' => true,
         'the worker went on' => true,
         'one record for the one throw' => true,
         'no crash logged' => true,
         'group clean' => true,
      ];

      // @ A) A UDP server
      yield new Assertion(description: 'a UDP server logs a failure its worker caught and survived')
         ->expect($Serve('udp'), Op::Identical, $Expected)
         ->assert();

      // @ Pin: start() registers the reporter, outside Test mode only — a
      //   Test-mode server keeps its output byte-stable — outside any
      //   closure and before the fork, so the master holds it and every
      //   worker inherits it
      $Tokens = array_values(array_filter(
         token_get_all((string) file_get_contents((string) new ReflectionClass(UDP_Server_CLI::class)->getFileName())),
         static fn ($Token): bool => is_array($Token) === false
            || in_array($Token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === false
      ));
      $Text = array_map(static fn ($Token): string => is_array($Token) ? $Token[1] : $Token, $Tokens);
      $Guarded = ['if', '(', '$this', '->', 'Mode', '!==', 'Modes', '::', 'Test', ')', '{', 'Reporter', '::', 'register', '(', ')', ';', '}'];
      $guarded = 0;
      $registrations = 0;
      $method = '';
      $Methods = [];
      $depth = 0;
      $pending = false;
      $Closures = [];
      $enclosed = null;
      $guard = -1;
      $fork = -1;
      foreach ($Text as $index => $text) {
         if ($text === 'function') {
            if (is_array($Tokens[$index + 1]) && $Tokens[$index + 1][0] === T_STRING) {
               $method = $Text[$index + 1];
            }
            else {
               // ! A closure: its body opens at the next brace
               $pending = true;
            }
         }
         if ($text === '{' || $text === '${') {
            $depth++;
            if ($pending) {
               $Closures[] = $depth;
               $pending = false;
            }
         }
         else if ($text === '}') {
            if ($Closures !== [] && $Closures[count($Closures) - 1] === $depth) {
               array_pop($Closures);
            }
            $depth--;
         }
         if ($fork === -1 && $method === 'start' && $text === 'fork' && ($Text[$index - 1] ?? '') === '->') {
            $fork = $index;
         }
         if ($text === 'register' && ($Text[$index - 1] ?? '') === '::' && ($Text[$index - 2] ?? '') === 'Reporter') {
            $registrations++;
            $Methods[] = $method;
         }
         if (array_slice($Text, $index, count($Guarded)) === $Guarded) {
            $guarded++;
            $guard = $index;
            $enclosed = $Closures !== [];
         }
      }

      yield new Assertion(description: 'start() registers the exceptions reporter once, before it forks, and only outside Test mode')
         ->expect(
            [
               'registrations' => $registrations,
               'guarded' => $guarded,
               'in' => $Methods,
               'in a closure' => $enclosed,
               'before the fork' => $guard > -1 && $fork > $guard,
            ],
            Op::Identical,
            ['registrations' => 1, 'guarded' => 1, 'in' => ['start'], 'in a closure' => false, 'before the fork' => true]
         )
         ->assert();
   })
);
