<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L1 follow-up — the rolling HTTP-01 helper handoff must DEGRADE, never take
 * the service down with it.
 *
 * The handoff authenticates an inherited helper across `pcntl_exec` and fails
 * closed when it cannot. Failing closed is right on the safe side of the drain:
 * `export()`'s poison name aborts `relay()` before a single worker is drained,
 * and the old image survives intact.
 *
 * `import()` is NOT on the safe side. It runs on the fresh image, after the old
 * one drained its workers, closed its listeners and `pcntl_exec`ed itself away.
 * A false return there reaches `TCP_Server_CLI::start()`:
 *
 *     $handoff = $this->receive();
 *     if ($handoff === false) { ...; exit(1); }
 *
 * There is no image left to reject back into, so the rejection IS the outage —
 * ports 80 and 443 released, nothing serving, while the operator's console
 * printed "Reload signal sent". The pre-fix code degraded here instead: it
 * adopted the Gate and forked a fresh helper.
 *
 * Both rejections are avoidable, because the fresh image holds the inherited
 * helper as a live DIRECT CHILD of its own PID — `pcntl_exec` preserved the
 * parentage — so it can retire it deterministically and continue.
 *
 * The legs drive the production methods directly through reflection on an
 * uninitialized instance: `import()`, `export()` and `terminate()` touch only
 * `$AutoTLS`, `$Gate`, `$helper`, `$helperReady` and /proc, so no server is
 * started and no global state is disturbed.
 *
 * Mutation matrix, measured against the patched source:
 *
 *   refuse again on a spool mismatch ............... leg A
 *   use `reap()` instead of `terminate()` .......... legs A and B
 *   restore the bare blocking `pcntl_waitpid()` .... NOTHING — see below
 *
 * The second row is worth reading twice: `reap()` sends no signal and polls for
 * 10 ms, so against a LIVE child it can never succeed. That is why it fails
 * both legs, not just the one it was written for.
 *
 * Leg C is a CONTROL, not a discriminator, and the third row is why. The third
 * defect this file accompanies is that `terminate()`'s final blocking
 * `pcntl_waitpid()` had no EINTR retry while every master signal is installed
 * with `restart_syscalls = false`, so a worker exiting at the wrong microsecond
 * made it report lost ownership and the callers stopped the server. Forcing
 * EINTR into the window between SIGKILL and reaping is not something a test can
 * do deterministically — this leg cannot kill that mutation and does not claim
 * to. The remedy is instead structural: reuse `await()`, which already loops on
 * EINTR, so the hole cannot be reintroduced by writing the wait again. What leg
 * C does lock is the escalation ladder around it — a child that ignores SIGTERM
 * is still retired, and reported as retired, with no zombie left behind.
 */
$probe = [
   'error' => '',
   'legs' => [],
];

return new Test(
   description: 'An unauthenticated HTTP-01 helper handoff must degrade, not exit the fresh image',
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $Children = [];
      $Sockets = [];

      // ! A child that outlives the leg unless retired: it must still be alive
      //   when import() decides, so the decision is about a LIVE responder.
      $Fork = static function (bool $stubborn = false) use (&$Children): int {
         $PID = pcntl_fork();
         if ($PID === -1) {
            throw new RuntimeException('Could not fork the helper stand-in.');
         }
         if ($PID === 0) {
            // # Child
            // ! Release the harness's stdio FIRST. A forked child keeps the
            //   suite's stdout pipe open, so the runner's own output never
            //   reaches EOF and the whole case reports nothing at all.
            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);

            pcntl_signal(SIGQUIT, SIG_DFL);
            if ($stubborn) {
               // Ignore the polite signal so terminate() must escalate.
               pcntl_signal(SIGTERM, SIG_IGN);
            }
            // ! Bounded on purpose: a stand-in that outlives a crashed leg
            //   must not survive the suite.
            $ticks = 0;
            while ($ticks++ < 1500) {
               pcntl_signal_dispatch();
               usleep(20_000);
            }
            exit(0);
         }

         $Children[] = $PID;

         return $PID;
      };

      // ! `identify()`'s own source of truth, read the same way, so a leg can
      //   build a name whose generation is deliberately wrong.
      $Started = static function (int $PID): string {
         $stat = @file_get_contents("/proc/{$PID}/stat");
         $separator = is_string($stat) ? strrpos($stat, ') ') : false;
         if ($separator === false) {
            throw new RuntimeException("Could not read /proc/{$PID}/stat.");
         }
         $fields = preg_split('/\s+/', trim(substr($stat, $separator + 2)));

         return (string) $fields[19];
      };

      $Gone = static function (int $PID): bool {
         // Reaped by the routine under test, or never a process again.
         $status = 0;
         $reaped = pcntl_waitpid($PID, $status, WNOHANG);
         if ($reaped === $PID || $reaped === -1) {
            return true;
         }
         // Still an unreaped child: a zombie left behind is a failure.
         return false;
      };

      try {
         if (function_exists('pcntl_fork') === false) {
            throw new RuntimeException('pcntl is required.');
         }

         // ! An uninitialized instance: no constructor, no Process, no Logger
         //   touched by any method under test.
         $Build = static function (
            string $challenges,
            int $port,
            mixed $Gate,
            int $helper
         ): HTTP_Server_CLI {
            $Server = new ReflectionClass(HTTP_Server_CLI::class)
               ->newInstanceWithoutConstructor();

            $Secure = new ReflectionClass(AutoTLS::class)->newInstanceWithoutConstructor();
            new ReflectionProperty(AutoTLS::class, 'challenges')->setValue($Secure, $challenges);
            new ReflectionProperty(AutoTLS::class, 'port')->setValue($Secure, $port);

            new ReflectionProperty(HTTP_Server_CLI::class, 'AutoTLS')->setValue($Server, $Secure);
            new ReflectionProperty(HTTP_Server_CLI::class, 'Gate')->setValue($Server, $Gate);
            new ReflectionProperty(HTTP_Server_CLI::class, 'helper')->setValue($Server, $helper);
            new ReflectionProperty(HTTP_Server_CLI::class, 'helperReady')->setValue($Server, true);

            return $Server;
         };

         $Field = static function (HTTP_Server_CLI $Server, string $name): mixed {
            return new ReflectionProperty(HTTP_Server_CLI::class, $name)->getValue($Server);
         };
         $Call = static function (HTTP_Server_CLI $Server, string $method, array $args): mixed {
            return new ReflectionMethod(HTTP_Server_CLI::class, $method)->invoke($Server, ...$args);
         };

         $Listen = static function () use (&$Sockets): array {
            $code = 0;
            $message = '';
            $Socket = @stream_socket_server('tcp://127.0.0.1:0', $code, $message);
            if (is_resource($Socket) === false) {
               throw new RuntimeException("Could not bind the stand-in gate: {$code} {$message}");
            }
            $Sockets[] = $Socket;
            $address = (string) stream_socket_get_name($Socket, false);

            return [$Socket, (int) substr($address, strrpos($address, ':') + 1)];
         };

         // # Leg A — the operator changed the challenge spool and reloaded.
         //   The old image embedded the OLD hash; only the fresh image knows
         //   the new one, so no guard before the drain could have caught it.
         [$GateA, $portA] = $Listen();
         $helperA = $Fork();
         $Old = $Build('/tmp/bootgly-l1-spool-old/', $portA, $GateA, $helperA);
         $exported = $Call($Old, 'export', []);
         $nameA = (string) array_key_first($exported);

         $Fresh = $Build('/tmp/bootgly-l1-spool-new/', $portA, null, 0);
         $adoptedA = $Call($Fresh, 'import', [[$nameA => $GateA]]);
         usleep(120_000);
         $probe['legs']['A'] = [
            'name' => $nameA,
            'encoded' => str_contains($nameA, ".{$helperA}."),
            'adopted' => $adoptedA,
            'retired' => $Gone($helperA),
            'helper' => $Field($Fresh, 'helper'),
            'gate' => is_resource($Field($Fresh, 'Gate')),
         ];

         // # Leg B — the helper is alive and healthy, but one /proc read came
         //   back empty, so its generation cannot be proved. `reap()` sends no
         //   signal, so against a LIVE child it can never succeed.
         [$GateB, $portB] = $Listen();
         $helperB = $Fork();
         $spoolB = '/tmp/bootgly-l1-spool-same/';
         $wrong = (string) (((int) $Started($helperB)) + 1);
         $hashB = substr(hash('sha256', $spoolB), 0, 20);
         $nameB = "http01.gate.{$helperB}.{$wrong}.{$hashB}";

         $FreshB = $Build($spoolB, $portB, null, 0);
         $adoptedB = $Call($FreshB, 'import', [[$nameB => $GateB]]);
         usleep(120_000);
         $probe['legs']['B'] = [
            'adopted' => $adoptedB,
            'retired' => $Gone($helperB),
            'helper' => $Field($FreshB, 'helper'),
            'gate' => is_resource($Field($FreshB, 'Gate')),
         ];

         // # Leg C — CONTROL. A child that ignores SIGTERM must still be
         //   retired, and reported as retired, through the escalation ladder.
         [$GateC, $portC] = $Listen();
         $helperC = $Fork(stubborn: true);
         usleep(150_000); // let the child install SIG_IGN
         $ServerC = $Build('/tmp/bootgly-l1-spool-c/', $portC, $GateC, $helperC);
         $started = microtime(true);
         $retiredC = $Call($ServerC, 'terminate', [$helperC, false, '']);
         $probe['legs']['C'] = [
            'reported' => $retiredC,
            'gone' => $Gone($helperC),
            'seconds' => round(microtime(true) - $started, 2),
         ];
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($Children as $PID) {
            if ($PID > 1) {
               @posix_kill($PID, SIGKILL);
               $status = 0;
               @pcntl_waitpid($PID, $status);
            }
         }
         foreach ($Sockets as $Socket) {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }
      }

      return "GET /l1-degradation-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route(
         '/l1-degradation-harness',
         static function (Request $Request, Response $Response): Response {
            return $Response(code: 200, body: 'L1-DEGRADATION-OK');
         },
         GET
      );

      yield $Router->route('/*', static function (Request $Request, Response $Response): Response {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         return 'L1 degradation fixture failed: ' . $probe['error'];
      }
      if (! str_contains($response, 'L1-DEGRADATION-OK')) {
         return 'L1 degradation fixture failed: harness route was not selected.';
      }

      $L = $probe['legs'];
      $Evidence = static function () use ($L): void {
         Vars::$labels = ['L1 handoff degradation'];
         dump(json_encode($L, JSON_UNESCAPED_SLASHES));
      };

      // @ Harness sanity: export() must actually have embedded the identity,
      //   or leg A is testing the plain-name path and proves nothing.
      if (($L['A']['encoded'] ?? false) !== true) {
         $Evidence();

         return 'L1 degradation fixture failed: export() did not embed the helper identity, so '
            . 'the spool hash was never carried and leg A is vacuous.';
      }

      // @ Control first — the escalation ladder must retire a stubborn child
      //   and say so. If this is broken, the degrade paths below cannot rely
      //   on terminate() either.
      $C = $L['C'] ?? [];
      if (($C['reported'] ?? false) !== true || ($C['gone'] ?? false) !== true) {
         $Evidence();

         return 'L1 degradation control failed: terminate() did not retire a SIGTERM-ignoring '
            . 'child and report it (reported=' . json_encode($C['reported'] ?? null)
            . ', gone=' . json_encode($C['gone'] ?? null) . ').';
      }

      $findings = [];
      foreach ([
         'A' => 'the challenge spool path changed across the reload',
         'B' => 'the inherited helper was alive but its /proc generation could not be proved',
      ] as $leg => $what) {
         $case = $L[$leg] ?? [];
         if (($case['adopted'] ?? null) !== true) {
            $findings[] = "when {$what}, import() refused the handoff — on the fresh image that "
               . 'reaches exit(1) with no old image left, so the whole service goes down';
            continue;
         }
         if (($case['retired'] ?? false) !== true) {
            $findings[] = "when {$what}, import() adopted the Gate but left the inherited helper "
               . 'live and untracked';
         }
         if (($case['gate'] ?? false) !== true || ($case['helper'] ?? -1) !== 0) {
            $findings[] = "when {$what}, import() left inconsistent state (gate="
               . json_encode($case['gate'] ?? null) . ', helper='
               . json_encode($case['helper'] ?? null) . ')';
         }
      }

      if ($findings !== []) {
         $Evidence();

         return 'CONFIRMED: the HTTP-01 handoff fails closed where there is nothing left to fail '
            . 'back into — ' . implode('; ', $findings) . '.';
      }

      return true;
   }
);
