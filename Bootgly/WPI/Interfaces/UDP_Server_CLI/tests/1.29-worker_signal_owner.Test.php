<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Events\Signalling;
use Bootgly\ACI\Process\Signals;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;


/**
 * Who owns a UDP worker's signals — live, on a one-worker Daemon server whose
 * node overrides `work()` and `handle()` to leave notes: the worker raises
 * SIGUSR1 on itself on the first line of `work()` — under the launcher's mask,
 * so the signal is pending across the whole boot — and every `handle()` call
 * records its process, how it was reached and who holds each signal of the
 * list.
 *
 * The worker traps the master's whole list on its loop, before it lifts the
 * mask: each signal reaches `handle()` from the loop's dispatch point, the
 * pending one once and after the boot. The alarm of the static `Timer` — a
 * UDP worker's timers still ride it — is one of them. The master keeps its
 * own handlers; SIGPIPE stays ignored. (A UDP server only starts on the
 * built-in loop, so there is no worker on a loop without the capability.)
 *
 * The source pins: the registration sits under the capability guard, before
 * the mask is lifted, and `work()` installs no handler by itself.
 */
return new Test(
   description: 'A UDP worker should take the master\'s signals over on its loop before it lifts its mask, the static Timer alarm included',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('OWNER_AUTOBOOT');

use Bootgly\ACI\Process;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events as Hooks;

final class SignalProbe extends UDP_Server_CLI
{
   private const array LIST = [
      SIGALRM, SIGUSR1, SIGHUP, SIGINT, SIGQUIT, SIGTERM,
      SIGTSTP, SIGCONT, SIGUSR2, SIGCHLD, SIGIOT, SIGIO,
   ];

   public static function boot (mixed $Environment): void
   {
   }

   /** @param array<string,mixed> $Record */
   private static function note (array $Record): void
   {
      file_put_contents((string) getenv('OWNER_NOTES'), json_encode($Record) . "\n", FILE_APPEND | LOCK_EX);
   }

   protected function work (Process $Process, int $index, null|Closure $Publish = null): void
   {
      // Pending from the first line of the worker: the whole boot window
      posix_kill(posix_getpid(), SIGUSR1);
      self::note(['boot' => posix_getpid()]);

      parent::work($Process, $index, $Publish);
   }
   public function handle (int $signal): void
   {
      $Frames = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function');
      $Owners = [];
      foreach (self::LIST as $listed) {
         $Handler = pcntl_signal_get_handler($listed);
         $Owners[$listed] = $Handler instanceof Closure
            ? get_class(new ReflectionFunction($Handler)->getClosureThis() ?? new stdClass)
            : $Handler;
      }
      self::note([
         'signal' => $signal,
         'pid' => posix_getpid(),
         'level' => $this->Process->level,
         'relay' => in_array('relay', $Frames, true),
         'admission' => in_array('check', $Frames, true) || in_array('add', $Frames, true),
         'owners' => array_values(array_unique($Owners)),
         'pipe' => pcntl_signal_get_handler(SIGPIPE),
      ]);

      parent::handle($signal);
   }
}

$Server = new SignalProbe(Modes::Daemon);
$Server->configure(new Configs(
   host: '127.0.0.1',
   port: (int) getenv('OWNER_PORT'),
   workers: 1,
));
$Server->on(Hooks::DatagramReceive, static fn (string $input): string => (string) getmypid());
$Server->start();
PHP;

      /**
       * Boot one server, run $Drive against it, tear it
       * down; what $Drive observed, plus the teardown facts.
       *
       * @param Closure(int,Closure,Closure):array<string,mixed> $Drive
       *
       * @return array<string,mixed>
       */
      $Serve = static function (Closure $Drive) use ($Script): array {
         // @ Reserve a free loopback port.
         $Reservation = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         $port = (int) substr($name, (int) strrpos($name, ':') + 1);
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }
         $notes = (string) tempnam(sys_get_temp_dir(), 'bootgly-signal-owner-');

         $Environment = (array) getenv();
         $Environment['OWNER_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $Environment['OWNER_PORT'] = (string) $port;
         $Environment['OWNER_NOTES'] = $notes;
         $Process = proc_open(
            [PHP_BINARY, '-r', $Script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $Environment,
         );
         $launcher = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
         $master = 0;
         stream_set_blocking($Pipes[1], false);
         stream_set_blocking($Pipes[2], false);
         $output = '';
         $Read = static function () use (&$Pipes, &$output): string {
            $output .= (string) stream_get_contents($Pipes[1]) . (string) stream_get_contents($Pipes[2]);

            return $output;
         };
         /** @return array<int,array<string,mixed>> Every note so far. */
         $Notes = static function () use ($notes): array {
            $Records = [];
            foreach ((array) @file($notes, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
               $Record = json_decode((string) $line, true);
               if (is_array($Record)) {
                  $Records[] = $Record;
               }
            }

            return $Records;
         };
         /** Wait until $Found answers true over the notes; whether it did. */
         $Await = static function (Closure $Found, float $seconds) use ($Notes, $Read): bool {
            $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
            do {
               $Read();
               if ($Found($Notes()) === true) {
                  return true;
               }
               usleep(20_000);
            } while (hrtime(true) < $deadline);

            return false;
         };

         $Observed = [];
         $clean = false;
         try {
            // @ The launcher returns once the daemon is ready
            $deadline = hrtime(true) + 10_000_000_000;
            while (is_resource($Process) && proc_get_status($Process)['running'] && hrtime(true) < $deadline) {
               usleep(20_000);
            }
            // ! The daemon's PID from the launcher itself, so a daemon that
            //   never serves is still torn down
            if (preg_match('/Daemon started \(PID: (\d+)\)/', $Read(), $Match) === 1) {
               $master = (int) $Match[1];
            }

            $Observed = $Drive($master, $Notes, $Await);

            // @ A fast stop from the operator ends the master and its worker
            if ($master > 0) {
               posix_kill($master, SIGTERM);
            }
            $deadline = hrtime(true) + 5_000_000_000;
            while ($master > 0 && posix_kill($master, 0) && hrtime(true) < $deadline) {
               usleep(20_000);
            }
            $Observed['stopped'] = $master > 0 && posix_kill($master, 0) === false;
         }
         finally {
            // ! The daemon leads its own session; never PID 0/-1 (this runner's group).
            if ($master > 0) {
               posix_kill(-$master, SIGKILL);
            }
            if ($launcher > 0) {
               @posix_kill(-$launcher, SIGKILL);
            }
            $Read();
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
               if (str_starts_with((string) $file, "SignalProbe.{$port}.")) {
                  @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
               }
            }
            @unlink($notes);
         }
         $Observed['no crash logged'] = str_contains($output, 'crashed') === false
            && str_contains($output, 'Uncaught') === false;
         $Observed['group clean'] = $clean;

         return $Observed;
      };
      /**
       * The notes of one process for one signal.
       *
       * @param array<int,array<string,mixed>> $Records
       *
       * @return array<int,array<string,mixed>>
       */
      $Of = static fn (array $Records, int $PID, int $signal): array => array_values(array_filter(
         $Records,
         static fn (array $Record): bool => ($Record['pid'] ?? 0) === $PID && ($Record['signal'] ?? 0) === $signal
      ));
      /** @param array<int,array<string,mixed>> $Records */
      $Boots = static fn (array $Records): array => array_values(array_filter(array_column($Records, 'boot')));

      // @ A) The worker traps the list on its loop
      $Built = $Serve(static function (int $master, Closure $Notes, Closure $Await) use ($Of, $Boots): array {
         $Observed = [];

         // @ The signal pending since the first line of work()
         $booted = $Await(static fn (array $Records): bool => $Boots($Records) !== []
            && $Of($Records, (int) $Boots($Records)[0], SIGUSR1) !== [], 10.0);
         $worker = (int) ($Boots($Notes())[0] ?? 0);
         usleep(300_000);
         $Pending = $Of($Notes(), $worker, SIGUSR1);
         $Observed['the pending signal is handled once, by the worker'] = $booted && count($Pending) === 1
            && ($Pending[0]['level'] ?? '') === 'child';
         $Observed['from the loop, not from an admission'] = ($Pending[0]['relay'] ?? null) === true
            && ($Pending[0]['admission'] ?? null) === false;
         $Observed['the loop holds the whole list in the worker'] = ($Pending[0]['owners'] ?? null) === [Select::class];
         $Observed['SIGPIPE stays ignored'] = ($Pending[0]['pipe'] ?? null) === SIG_IGN;

         // @ The master keeps its own handlers
         posix_kill($master, SIGUSR1);
         $Await(static fn (array $Records): bool => $Of($Records, $master, SIGUSR1) !== [], 3.0);
         $Mastered = $Of($Notes(), $master, SIGUSR1);
         $Observed['the master is not on the loop'] = count($Mastered) === 1
            && ($Mastered[0]['level'] ?? '') === 'master' && ($Mastered[0]['relay'] ?? null) === false
            && ($Mastered[0]['owners'] ?? null) === [Signals::class];

         // @ Signals that mean nothing to a worker reach it and change nothing
         foreach ([SIGUSR2, SIGCHLD] as $signal) {
            posix_kill($worker, $signal);
            $Await(static fn (array $Records): bool => $Of($Records, $worker, $signal) !== [], 3.0);
         }
         $Records = $Notes();
         $Observed['master-only signals reach handle() through the loop'] = array_map(
            static fn (int $signal): array => array_map(
               static fn (array $Record): array => [$Record['level'], $Record['relay']],
               $Of($Records, $worker, $signal)
            ),
            [SIGUSR2, SIGCHLD]
         ) === [[['child', true]], [['child', true]]];

         // @ The static Timer still drives a UDP worker: its alarm is one of
         //   the signals the loop took over
         $Await(static fn (array $Records): bool => $Of($Records, $worker, SIGALRM) !== [], 3.0);
         $Alarms = $Of($Notes(), $worker, SIGALRM);
         $Observed['the alarm reaches handle() through the loop'] = $Alarms !== []
            && array_unique(array_column($Alarms, 'relay')) === [true];
         usleep(200_000);
         $Observed['the worker lives on, never reforked'] = posix_kill($worker, 0) && $Boots($Notes()) === [$worker];

         return $Observed;
      });

      yield new Assertion(description: 'on the built-in loop a UDP worker handles its signals as handlers of the loop')
         ->expect($Built, Op::Identical, [
            'the pending signal is handled once, by the worker' => true,
            'from the loop, not from an admission' => true,
            'the loop holds the whole list in the worker' => true,
            'SIGPIPE stays ignored' => true,
            'the master is not on the loop' => true,
            'master-only signals reach handle() through the loop' => true,
            'the alarm reaches handle() through the loop' => true,
            'the worker lives on, never reforked' => true,
            'stopped' => true,
            'no crash logged' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ B) Pins: the registration sits under the capability guard, uses the
      //    recorded list, reads the handler at dispatch, precedes the unmask,
      //    and work() installs nothing by itself
      $source = (string) file_get_contents((string) new ReflectionClass(UDP_Server_CLI::class)->getFileName());
      $Tokens = array_values(array_filter(
         token_get_all($source),
         static fn ($Token): bool => is_array($Token) === false
            || in_array($Token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === false
      ));
      $method = '';
      $depth = 0;
      $opened = 0;
      $Guards = [];
      $traps = [];
      $installers = [];
      $order = [];
      foreach ($Tokens as $index => $Token) {
         $text = is_array($Token) ? $Token[1] : $Token;
         if ($text === '{' || (is_array($Token) && in_array($Token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
         }
         else if ($text === '}') {
            $depth--;
            if ($method !== '' && $depth < $opened) {
               $method = '';
            }
            // ? A guard ends with its block
            $Guards = array_filter($Guards, static fn (int $level): bool => $level <= $depth);
         }
         else if (is_array($Token) && $Token[0] === T_FUNCTION && $method === '' && $depth >= 1
            && is_array($Tokens[$index + 1]) && $Tokens[$index + 1][0] === T_STRING
         ) {
            $method = $Tokens[$index + 1][1];
            $opened = $depth + 1;
         }
         // ? `if ($Loop instanceof Signalling) {`
         else if (is_array($Token) && $Token[0] === T_INSTANCEOF
            && ($Tokens[$index - 1][1] ?? null) === '$Loop' && ($Tokens[$index - 2] ?? null) === '('
            && ($Tokens[$index - 3][0] ?? null) === T_IF
            && ($Tokens[$index + 1][1] ?? null) === 'Signalling' && ($Tokens[$index + 2] ?? null) === ')'
            && ($Tokens[$index + 3] ?? null) === '{'
         ) {
            $Guards[] = $depth + 1;
         }
         // ? `->trap(` — where, under which guard, with which arguments
         else if (is_array($Token) && $Token[0] === T_OBJECT_OPERATOR
            && ($Tokens[$index + 1][1] ?? null) === 'trap' && ($Tokens[$index + 2] ?? null) === '('
         ) {
            $arguments = '';
            for ($cursor = $index + 3; ($Tokens[$cursor] ?? ')') !== ')'; $cursor++) {
               $arguments .= is_array($Tokens[$cursor]) ? $Tokens[$cursor][1] : $Tokens[$cursor];
            }
            $traps[] = [$method, $Guards !== [], $Tokens[$index - 1][1] ?? '', $arguments];
            if ($method === 'work') {
               $order[] = 'trap';
            }
         }
         // ? `self::unmask(` in work() — the mask must fall after the trap
         else if ($method === 'work' && $text === 'self' && ($Tokens[$index + 1][0] ?? null) === T_DOUBLE_COLON
            && ($Tokens[$index + 2][1] ?? null) === 'unmask'
         ) {
            $order[] = 'unmask';
         }
         // ? `pcntl_signal(` — every method that installs a handler itself
         else if ($text === 'pcntl_signal' && ($Tokens[$index + 1] ?? null) === '('
            && ($Tokens[$index - 1][0] ?? null) !== T_FUNCTION
         ) {
            $installers[$method] = true;
         }
      }
      $joined = implode('', array_map(static fn ($Token): string => is_array($Token) ? $Token[1] : $Token, $Tokens));
      $Pins = [
         'traps' => $traps,
         'work() installs a handler itself' => isset($installers['work']),
         'order in work()' => $order,
         'the list is the one the master installed' => str_contains(
            $joined,
            'foreach($Signals->installedas$signal){$Loop->trap($signal,$Handler);}'
         ),
         'the handler is read at dispatch' => str_contains(
            $joined,
            '$Handler=staticfunction(int$signal)use($Signals):void{($Signals->handler)($signal);};'
         ),
         'Select offers the capability' => is_subclass_of(Select::class, Signalling::class),
      ];

      yield new Assertion(description: 'the UDP worker registration is guarded by the capability, precedes the unmask and is the only signal installation of work()')
         ->expect($Pins, Op::Identical, [
            'traps' => [['work', true, '$Loop', '$signal,$Handler']],
            'work() installs a handler itself' => false,
            'order in work()' => ['trap', 'unmask'],
            'the list is the one the master installed' => true,
            'the handler is read at dispatch' => true,
            'Select offers the capability' => true,
         ])
         ->assert();
   })
);
