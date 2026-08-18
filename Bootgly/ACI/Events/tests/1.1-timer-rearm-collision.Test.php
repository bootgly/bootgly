<?php


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Timer: a persistent task re-armed into a later due bucket survives the tick',
   skip: function_exists('pcntl_alarm') === false,
   test: new Assertions(Case: function (): Generator {
      // ! A no-op SIGALRM handler — `Timer` arms `pcntl_alarm(1)` on its own,
      //   and an unhandled SIGALRM terminates the process.
      $Previous = pcntl_signal_get_handler(SIGALRM);
      Timer::init(static function (): void {});
      Timer::del();

      // ! Signal-safe waits — a pending SIGALRM cuts `sleep()`/`usleep()` short.
      $spin = static function (float $seconds): void {
         $deadline = microtime(true) + $seconds;
         while (microtime(true) < $deadline) {
            usleep(2000);
         }
      };
      $until = static function (int $second): void {
         while (time() < $second) {
            usleep(2000);
         }
      };

      $Tasks = new ReflectionProperty(Timer::class, 'tasks');

      // ! The scenario: A (persistent, fast) and B (one-shot, blocking) share
      //   bucket T+1, while D pre-populates bucket T+2 so that bucket is
      //   already in the tick's by-value snapshot. A re-arms into the LIVE
      //   bucket T+2 and B then blocks past it, so the loop reaches T+2 with a
      //   stale snapshot — dropping the bucket wholesale would delete A.
      $observe = static function () use ($spin, $until, $Tasks): array {
         Timer::del();

         // --- Align on a fresh second so every runtime bucket is deterministic.
         $second = time();
         while (time() === $second) {
            usleep(2000);
         }
         $T = time();

         $fired = ['A' => 0, 'B' => 0, 'D' => 0];
         $stamp = 0;

         $A = Timer::add(interval: 1, handler: static function () use (&$fired, &$stamp): void {
            $fired['A']++;
            $stamp = $stamp === 0 ? time() : $stamp;
         });
         Timer::add(
            interval: 1,
            handler: static function () use (&$fired, $spin): void {
               $fired['B']++;
               $spin(1.3);
            },
            persistent: false
         );
         Timer::add(
            interval: 2,
            handler: static function () use (&$fired): void {
               $fired['D']++;
            },
            persistent: false
         );

         // @@ The colliding tick.
         $until($T + 1);
         Timer::tick();

         $armed = [];
         foreach ($Tasks->getValue() as $runtime => $tasks) {
            $armed[$runtime] = array_keys($tasks);
         }

         $collided = $fired;

         // @@ Bucket T+2 is due the instant the colliding tick returns, so a
         //    second tick observes the survivor directly.
         Timer::tick();

         // ?: The scenario only reproduces when A fired inside second T+1 —
         //    that is what puts its re-armed bucket inside the snapshot.
         return [
            'aligned' => $stamp === $T + 1,
            'id' => $A,
            'armed' => $armed,
            'collided' => $collided,
            'fired' => $fired,
            'T' => $T,
         ];
      };

      // @ Retry a misaligned run instead of asserting on a scenario that never
      //   collided — an unaligned run passes with or without the fix.
      $attempts = 0;
      do {
         $observed = $observe();
         $attempts++;
      }
      while ($observed['aligned'] === false && $attempts < 3);

      Timer::del();
      pcntl_signal(SIGALRM, $Previous === false ? SIG_DFL : $Previous);

      $armed = $observed['armed'];
      $T = $observed['T'];

      yield new Assertion(
         description: 'The scenario collided — the persistent task fired inside its own bucket second',
      )
         ->expect($observed['aligned'])
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The re-armed persistent task survives the tick that drained its new bucket',
      )
         ->expect($armed[$T + 2] ?? [])
         ->to->be([$observed['id']])
         ->assert();

      yield new Assertion(
         description: 'The drained bucket is dropped so a later add() still arms the alarm',
      )
         ->expect(isSet($armed[$T + 1]))
         ->to->be(false)
         ->assert();

      yield new Assertion(
         description: 'The colliding tick runs the persistent task exactly once',
      )
         ->expect($observed['collided']['A'])
         ->to->be(1)
         ->assert();

      yield new Assertion(
         description: 'The surviving persistent task fires again on the next tick',
      )
         ->expect($observed['fired']['A'])
         ->to->be(2)
         ->assert();

      yield new Assertion(
         description: 'Detaching tasks one by one runs each of them exactly once',
      )
         ->expect([$observed['fired']['B'], $observed['fired']['D']])
         ->to->be([1, 1])
         ->assert();
   })
);
