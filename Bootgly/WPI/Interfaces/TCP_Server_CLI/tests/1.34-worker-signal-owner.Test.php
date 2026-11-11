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
use Bootgly\WPI\Interfaces\TCP_Server_CLI;


/**
 * Who owns a worker's signals — live, on a one-worker Interactive HTTP server
 * (stdin at EOF) whose node overrides `work()` and `handle()` to leave notes:
 * the worker raises SIGUSR1 on itself on the first line of `work()`, so the
 * signal is pending across the whole boot, and every `handle()` call records
 * its process, how it was reached and who holds each signal of the list.
 *
 * - On the built-in loop the worker traps the master's whole list on it: each
 *   signal reaches the (virtual) `handle()` from the loop's dispatch point,
 *   the pending one once and after the boot; the master keeps its own
 *   handlers; SIGPIPE stays ignored.
 * - On a loop without the capability the worker registers nothing: every
 *   disposition is the one inherited from the master and signals still reach
 *   `handle()` through it.
 * - The worker reads the server's handler when a signal is dispatched, so one
 *   assigned after the takeover still runs; and a stop is honoured even when
 *   an application's shutdown listener throws — reported, never swallowed
 *   with the stop.
 *
 * The source pins: the registration sits under the capability guard, before
 * the worker's mask is lifted and before its boot event, and `work()`
 * installs no handler by itself.
 */
return new Test(
   description: 'A worker should take the master\'s signals over on a loop that dispatches signals, and keep the inherited handlers on one that does not',
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

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Process;
use Bootgly\ACI\Process\Events as Worker;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events as Hooks;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

// A loop written before the capability existed: it serves nothing, it only
// dispatches signals the way such a loop always did.
final class Plain implements Events, Loops, Scheduler
{
   public bool $loop = true;

   public function add ($Socket, int $flag, $payload): bool { return true; }
   public function del ($Socket, int $flag): bool { return true; }
   public function loop (): void
   {
      while ($this->loop) {
         pcntl_signal_dispatch();
         usleep(5_000);
      }
   }
   public function destroy (): void { $this->loop = false; }
   public function schedule (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): bool { return false; }
   public function defer (float|int $deadline, Closure $Callback): int { return 0; }
   public function cancel (int $ID): bool { return false; }
   public function interrupt (Fiber $Fiber, Throwable $Throwable): bool { return false; }
}

final class Probe extends HTTP_Server_CLI
{
   private const array LIST = [
      SIGALRM, SIGUSR1, SIGURG, SIGHUP, SIGINT, SIGQUIT, SIGTERM,
      SIGTSTP, SIGCONT, SIGUSR2, SIGCHLD, SIGIOT, SIGIO,
   ];

   /** @param array<string,mixed> $Record */
   private static function note (array $Record): void
   {
      file_put_contents((string) getenv('OWNER_NOTES'), json_encode($Record) . "\n", FILE_APPEND | LOCK_EX);
   }

   protected function work (Process $Process, int $index): void
   {
      // Pending from the first line of the worker: the whole boot window
      posix_kill(posix_getpid(), SIGUSR1);
      self::note(['boot' => posix_getpid()]);

      parent::work($Process, $index);
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
   // The application puts its own handler in front of the server's one
   public function wrap (): void
   {
      $Previous = $this->Process->Signals->handler;
      $this->Process->Signals->handler = static function (int $signal) use ($Previous): void {
         self::note(['wrapped' => $signal, 'pid' => posix_getpid()]);
         $Previous($signal);
      };
   }
}

$Server = new Probe(Modes::Interactive);
$Server->configure(new HTTP_Server_CLI\Configs(
   host: '127.0.0.1',
   port: (int) getenv('OWNER_PORT'),
   workers: 1,
));
$Server->on(Hooks::RequestReceived, static fn (Request $Request, Response $Response): Response => $Response(body: (string) getmypid()));
if (getenv('OWNER_LOOP') === 'plain') {
   Probe::$Event = new Plain;
}
if (getenv('OWNER_LOOP') === 'hooked') {
   $Master = posix_getpid();
   // After the worker took its signals over
   Emitter::$Instance->listen(Worker::Boot, static function (mixed ...$arguments) use ($Server): void {
      $Server->wrap();
   });
   // What the server reports, as an application's reporter sees it
   \Bootgly\ABI\Debugging\Data\Throwables::$reporters[] = static function (Throwable $Throwable, array $context): void {
      file_put_contents((string) getenv('OWNER_NOTES'), json_encode([
         'reported' => $Throwable->getMessage(),
         'origin' => $context['origin'] ?? null,
         'pid' => posix_getpid(),
      ]) . "\n", FILE_APPEND | LOCK_EX);
   };
   // An application teardown that fails, in the worker only
   Emitter::$Instance->listen(Worker::Shutdown, static function (mixed ...$arguments) use ($Master): void {
      if (posix_getpid() !== $Master) {
         throw new RuntimeException('application teardown failed');
      }
   });
}
$Server->start();
PHP;

      /**
       * Boot one server on the given loop, run $Drive against it, tear it
       * down; what $Drive observed, plus the teardown facts.
       *
       * @param Closure(int,Closure,Closure):array<string,mixed> $Drive
       *
       * @return array<string,mixed>
       */
      $Serve = static function (string $loop, Closure $Drive) use ($Script): array {
         // @ Reserve a free loopback port.
         $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
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
         $Environment['OWNER_LOOP'] = $loop;
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
            $Observed = $Drive($master, $Notes, $Await);

            // @ A fast stop from the operator ends the master and its worker
            posix_kill($master, SIGTERM);
            $asked = hrtime(true);
            $deadline = $asked + 8_000_000_000;
            $status = proc_get_status($Process);
            while ($status['running'] && hrtime(true) < $deadline) {
               usleep(20_000);
               $status = proc_get_status($Process);
            }
            // ! Well under the 5 s after which the master kills a worker
            //   that did not stop
            $Observed['stopped'] = $status['running'] === false
               && (hrtime(true) - $asked) < 3_000_000_000;
         }
         finally {
            // ! The whole session group; never PID 0/-1 (this runner's group).
            if ($master > 0) {
               posix_kill(-$master, SIGKILL);
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
               if (str_starts_with((string) $file, "Probe.{$port}.")) {
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

      // @ A) The built-in loop: the worker traps the list on it
      $Built = $Serve('select', static function (int $master, Closure $Notes, Closure $Await) use ($Of, $Boots): array {
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
         foreach ([SIGUSR2, SIGCHLD, SIGURG] as $signal) {
            posix_kill($worker, $signal);
            $Await(static fn (array $Records): bool => $Of($Records, $worker, $signal) !== [], 3.0);
         }
         $Records = $Notes();
         $Observed['master-only and node signals reach the virtual handle() through the loop'] = array_map(
            static fn (int $signal): array => array_map(
               static fn (array $Record): array => [$Record['level'], $Record['relay']],
               $Of($Records, $worker, $signal)
            ),
            [SIGUSR2, SIGCHLD, SIGURG]
         ) === [[['child', true]], [['child', true]], [['child', true]]];
         usleep(200_000);
         $Observed['the worker lives on, never reforked'] = posix_kill($worker, 0) && $Boots($Notes()) === [$worker];

         return $Observed;
      });

      yield new Assertion(description: 'on the built-in loop a worker handles its signals as handlers of the loop')
         ->expect($Built, Op::Identical, [
            'the pending signal is handled once, by the worker' => true,
            'from the loop, not from an admission' => true,
            'the loop holds the whole list in the worker' => true,
            'SIGPIPE stays ignored' => true,
            'the master is not on the loop' => true,
            'master-only and node signals reach the virtual handle() through the loop' => true,
            'the worker lives on, never reforked' => true,
            'stopped' => true,
            'no crash logged' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ B) A loop without the capability: nothing registered, nothing changed
      $Legacy = $Serve('plain', static function (int $master, Closure $Notes, Closure $Await) use ($Of, $Boots): array {
         $Observed = [];

         $booted = $Await(static fn (array $Records): bool => $Boots($Records) !== []
            && $Of($Records, (int) $Boots($Records)[0], SIGUSR1) !== [], 10.0);
         $worker = (int) ($Boots($Notes())[0] ?? 0);
         usleep(300_000);
         $Pending = $Of($Notes(), $worker, SIGUSR1);
         $Observed['the pending signal is handled once, by the worker'] = $booted && count($Pending) === 1
            && ($Pending[0]['level'] ?? '') === 'child';
         $Observed['through the inherited handler'] = ($Pending[0]['relay'] ?? null) === false;
         $Observed['every disposition is the one the master installed'] = ($Pending[0]['owners'] ?? null) === [Signals::class];
         $Observed['SIGPIPE stays ignored'] = ($Pending[0]['pipe'] ?? null) === SIG_IGN;

         // @ The static Timer still drives this worker: its alarm reaches handle()
         $alarmed = $Await(static fn (array $Records): bool => $Of($Records, $worker, SIGALRM) !== [], 3.0);
         $Observed['the alarm still reaches handle()'] = $alarmed;
         $Observed['the worker lives on, never reforked'] = posix_kill($worker, 0) && $Boots($Notes()) === [$worker];

         return $Observed;
      });

      yield new Assertion(description: 'on a loop without the capability a worker boot registers nothing and changes no disposition')
         ->expect($Legacy, Op::Identical, [
            'the pending signal is handled once, by the worker' => true,
            'through the inherited handler' => true,
            'every disposition is the one the master installed' => true,
            'SIGPIPE stays ignored' => true,
            'the alarm still reaches handle()' => true,
            'the worker lives on, never reforked' => true,
            'stopped' => true,
            'no crash logged' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ C) The application around the takeover: a handler assigned after
      //    it still runs, and a stop survives a shutdown listener that throws
      $Hooked = $Serve('hooked', static function (int $master, Closure $Notes, Closure $Await) use ($Of, $Boots): array {
         $Observed = [];

         $booted = $Await(static fn (array $Records): bool => $Boots($Records) !== []
            && $Of($Records, (int) $Boots($Records)[0], SIGUSR1) !== [], 10.0);
         $worker = (int) ($Boots($Notes())[0] ?? 0);
         $Wrapped = array_values(array_filter(
            $Notes(),
            static fn (array $Record): bool => ($Record['wrapped'] ?? 0) === SIGUSR1 && ($Record['pid'] ?? 0) === $worker
         ));
         $Observed['a handler assigned after the takeover runs, then the server\'s'] = $booted
            && count($Wrapped) === 1 && count($Of($Notes(), $worker, SIGUSR1)) === 1;

         // @ A stop sent to the worker itself: its shutdown listener throws
         posix_kill($worker, SIGTERM);
         $deadline = hrtime(true) + 2_000_000_000;
         while (posix_kill($worker, 0) && hrtime(true) < $deadline) {
            usleep(20_000);
         }
         $Observed['the worker stops although its shutdown listener throws'] = posix_kill($worker, 0) === false;
         $Reported = array_values(array_filter(
            $Notes(),
            static fn (array $Record): bool => isset($Record['reported']) && ($Record['pid'] ?? 0) === $worker
         ));
         $Observed['the listener\'s failure is reported once, as the stop\'s'] = count($Reported) === 1
            && $Reported[0]['reported'] === 'application teardown failed'
            && $Reported[0]['origin'] === 'server.stop';

         return $Observed;
      });

      yield new Assertion(description: 'a handler assigned after the takeover is honoured, and a throwing shutdown listener never swallows a stop')
         ->expect(
            array_intersect_key($Hooked, [
               'a handler assigned after the takeover runs, then the server\'s' => 0,
               'the worker stops although its shutdown listener throws' => 0,
               'the listener\'s failure is reported once, as the stop\'s' => 0,
               'stopped' => 0,
               'group clean' => 0,
            ]),
            Op::Identical,
            [
               'a handler assigned after the takeover runs, then the server\'s' => true,
               'the worker stops although its shutdown listener throws' => true,
               'the listener\'s failure is reported once, as the stop\'s' => true,
               'stopped' => true,
               'group clean' => true,
            ]
         )
         ->assert();

      // @ D) Pins: the registration sits under the capability guard, uses the
      //    recorded list, reads the handler at dispatch, comes before the
      //    unmask and the boot event, and work() installs nothing by itself
      $source = (string) file_get_contents((string) new ReflectionClass(TCP_Server_CLI::class)->getFileName());
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
         }
         // ? `pcntl_signal(` — every method that installs a handler itself
         else if ($text === 'pcntl_signal' && ($Tokens[$index + 1] ?? null) === '('
            && ($Tokens[$index - 1][0] ?? null) !== T_FUNCTION
         ) {
            $installers[$method] = true;
         }
      }
      $joined = implode('', array_map(static fn ($Token): string => is_array($Token) ? $Token[1] : $Token, $Tokens));
      // ! Offsets inside work(): the trap, the unmask, the boot event
      $work = strpos($joined, 'protectedfunctionwork(');
      $trapped = $work === false ? false : strpos($joined, '$Loop->trap(', $work);
      $unmasked = $work === false ? false : strpos($joined, 'pcntl_sigprocmask(SIG_SETMASK,[]);', $work);
      $emitted = $work === false ? false : strpos($joined, '$Emitter->emit(Worker::Boot,', $work);
      $Pins = [
         'traps' => $traps,
         'work() installs a handler itself' => isset($installers['work']),
         'the list is the one the master installed' => str_contains(
            $joined,
            'foreach($Signals->installedas$signal){$Loop->trap($signal,$Handler);}'
         ),
         'the handler is read at dispatch' => str_contains(
            $joined,
            '$Handler=staticfunction(int$signal)use($Signals):void{($Signals->handler)($signal);};'
         ),
         'trapped before the unmask and the boot event' => $trapped !== false && $unmasked !== false && $emitted !== false
            && $trapped < $unmasked && $unmasked < $emitted,
         'Select offers the capability' => is_subclass_of(Select::class, Signalling::class),
      ];

      yield new Assertion(description: 'the worker registration is guarded by the capability and is the only signal installation of work()')
         ->expect($Pins, Op::Identical, [
            'traps' => [['work', true, '$Loop', '$signal,$Handler']],
            'work() installs a handler itself' => false,
            'the list is the one the master installed' => true,
            'the handler is read at dispatch' => true,
            'trapped before the unmask and the boot event' => true,
            'Select offers the capability' => true,
         ])
         ->assert();
   })
);
