<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Events\Timer\Registry as TimerRegistry;
use Bootgly\ACI\Events\Timer\Reset as TimerReset;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


/** A timer capture whose destructor may throw. */
final class T13Capture
{
   // * Metadata
   /** Number of destructor executions. */
   public static int $destructions = 0;

   // * Config
   public private(set) bool $throw;


   public function __construct (bool $throw)
   {
      $this->throw = $throw;
   }

   /** Count the release; throw when asked to. */
   public function __destruct ()
   {
      self::$destructions++;
      if ($this->throw) {
         throw new RuntimeException('expected timer capture destructor failure');
      }
   }
}


/** An exception whose own release throws. */
final class T13Poison extends RuntimeException
{
   // * Metadata
   /** Number of releases. */
   public static int $released = 0;

   /** Throw while the exception itself is released. */
   public function __destruct ()
   {
      self::$released++;
      throw new LogicException('expected poisoned exception destructor failure');
   }
}


/** An exception whose release throws another poisoned exception. */
final class T13Poison2 extends RuntimeException
{
   // * Metadata
   /** Number of releases. */
   public static int $released = 0;

   /** Throw a poisoned exception while this one is released. */
   public function __destruct ()
   {
      self::$released++;
      throw new T13Poison('expected second-level poison');
   }
}


/** An exception whose release throws a two-level poisoned exception. */
final class T13Poison3 extends RuntimeException
{
   // * Metadata
   /** Number of releases. */
   public static int $released = 0;

   /** Throw a two-level poisoned exception while this one is released. */
   public function __destruct ()
   {
      self::$released++;
      throw new T13Poison2('expected third-level poison');
   }
}


/** A capture that owns the closure capturing it: freed only by the cycle collector. */
final class T13Cycle
{
   // * Data
   public null|Closure $Handler = null;

   // * Config
   public private(set) bool $throw;


   public function __construct (bool $throw)
   {
      $this->throw = $throw;
   }

   /** Count the release; throw when asked to. */
   public function __destruct ()
   {
      T13Capture::$destructions++;
      if ($this->throw) {
         throw new RuntimeException('expected cyclic capture destructor failure');
      }
   }
}


/** An exception whose release throws a new one while armed: an endless chain, capped. */
final class T13Chain extends RuntimeException
{
   // * Metadata
   public static int $made = 0;
   public static bool $armed = true;


   /** Throw the next link while armed and under the cap. */
   public function __destruct ()
   {
      if (self::$armed && self::$made < 1000) {
         self::$made++;
         throw new self('expected chain link');
      }
   }
}


/** A reference-cycle capture whose release throws a two-level poisoned exception. */
final class T13PoisonCycle
{
   // * Data
   public null|Closure $Handler = null;


   /** Throw a two-level poison while the cycle collector releases it. */
   public function __destruct ()
   {
      throw new T13Poison2('expected cyclic poison');
   }
}


/**
 * Regression (BG-H7-4, BG-H7-9, ACI EVENTS-3) — `Timer::tick()` runs each due
 * task at most once and never a task an earlier handler deleted; values a
 * handler leaves behind are released contained, so a throwing destructor never
 * escapes into the SIGALRM handler (the worker died); a handler failure is
 * reported through `Throwables::notify()` instead of swallowed. Every leg drives
 * the real signal path synchronously — `posix_kill()` of this process with
 * SIGALRM, then `pcntl_signal_dispatch()` — on buckets moved into the past, so
 * nothing sleeps.
 */

return new Test(
   description: 'Timer: cancel, contain and report inside tick()',
   skip: function_exists('pcntl_alarm') === false || function_exists('posix_kill') === false,
   test: new Assertions(Case: function (): Generator {
      $Previous = pcntl_signal_get_handler(SIGALRM);
      $reporters = count(Throwables::$reporters);
      $Tasks = new ReflectionProperty(Timer::class, 'tasks');
      $Parked = new ReflectionProperty(Timer::class, 'Parked');
      $Reports = [];
      // ! Escaped throwables are kept until the end, then released contained:
      //   a poisoned one would throw again from its catch frame
      static $Escapes = [];
      $outcomes = [];

      // ! Move every live task into a past bucket: `$offsets` maps id => seconds ago
      $due = static function (array $offsets) use ($Tasks): int {
         $now = time();
         $moved = [];
         foreach ($Tasks->getValue() as $tasks) {
            foreach ($tasks as $id => $Task) {
               $moved[$now - ($offsets[$id] ?? -30)][$id] = $Task;
            }
         }
         $Tasks->setValue(null, $moved);

         return $now;
      };
      // ! One SIGALRM through the real dispatch path; the escaped class, or null
      $alarm = static function () use (&$Escapes): null|string {
         pcntl_alarm(0);
         try {
            posix_kill(posix_getpid(), SIGALRM);
            pcntl_signal_dispatch();
         }
         catch (Throwable $Escaped) {
            $Escapes[] = $Escaped;
            return $Escaped::class;
         }
         finally {
            pcntl_alarm(0);
         }

         return null;
      };
      $wheel = static function (int $id) use ($Tasks): bool {
         foreach ($Tasks->getValue() as $tasks) {
            if (isSet($tasks[$id])) {
               return true;
            }
         }

         return false;
      };

      try {
         Throwables::$reporters[] = static function (Throwable $Throwable, array $context) use (&$Reports): void {
            $Reports[] = [$Throwable::class, $context];
         };
         // @ The handler shape every transport installs
         Timer::init(static function (): void {
            Timer::tick();
         });

         // @@ BG-H7-4: a task an earlier handler deleted never runs
         foreach (['same bucket' => [1, 1], 'later due bucket' => [2, 1]] as $label => [$first, $second]) {
            foreach ([true, false] as $cancel) {
               Timer::del();
               $ran = [];
               $B = 0;
               $A = Timer::add(30, static function () use (&$ran, &$B, $cancel): void {
                  $ran[] = 'A';
                  if ($cancel) {
                     Timer::del($B);
                  }
               }, persistent: false);
               $B = Timer::add(30, static function () use (&$ran): void {
                  $ran[] = 'B';
               });
               $due([$A => $first, $B => $second]);
               $Reports = [];
               $escaped = $alarm();
               $outcomes[] = [
                  $cancel ? "a task cancelled by an earlier handler never runs ({$label})" : "control: an uncancelled task runs ({$label})",
                  [$escaped, count($Reports), $ran],
                  [null, 0, $cancel ? ['A'] : ['A', 'B']],
               ];
            }
         }

         Timer::del();
         $ran = [];
         $A = Timer::add(30, static function () use (&$ran): void {
            $ran[] = 'A';
            Timer::del();
         }, persistent: false);
         $B = Timer::add(30, static function () use (&$ran): void {
            $ran[] = 'B';
         });
         $due([$A => 1, $B => 1]);
         $Reports = [];
         $escaped = $alarm();
         $outcomes[] = ['a wheel reset by a handler stops the rest of the tick', [$escaped, count($Reports), $ran], [null, 0, ['A']]];

         // @ Each task runs at most once, even through a nested tick
         Timer::del();
         $ran = [];
         $A = Timer::add(30, static function () use (&$ran): void {
            $ran[] = 'A';
            Timer::tick();
         }, persistent: false);
         $B = Timer::add(30, static function () use (&$ran): void {
            $ran[] = 'B';
         });
         $C = Timer::add(30, static function () use (&$ran): void {
            $ran[] = 'C';
         }, persistent: false);
         $due([$A => 1, $B => 1, $C => 1]);
         $Reports = [];
         $escaped = $alarm();
         $outcomes[] = ['a nested tick runs each task once', [$escaped, count($Reports), $ran], [null, 0, ['A', 'B', 'C']]];

         // @ C-1: the only timer nesting a tick keeps the alarm armed and runs again
         Timer::del();
         $runs = 0;
         $id = Timer::add(30, static function () use (&$runs): void {
            $runs++;
            Timer::tick();
         });
         $due([$id => 1]);
         pcntl_alarm(0);
         posix_kill(posix_getpid(), SIGALRM);
         pcntl_signal_dispatch();
         $armed = pcntl_alarm(0);
         $due([$id => 1]);
         $escaped = $alarm();
         $outcomes[] = [
            'the only timer nesting a tick keeps the alarm armed and runs again',
            [$armed > 0, $escaped, $runs, TimerRegistry::check($id)],
            [true, null, 2, true],
         ];

         // @@ A task re-armed during the tick into a later due bucket never runs
         //   twice — re-armed by this tick, or by a tick nested in a handler
         foreach (['this tick' => false, 'a nested tick' => true] as $label => $nested) {
            Timer::del();
            $ran = [];
            $P = 0;
            $target = 0;
            // ! Move P's re-armed entry into D's bucket: due, and still ahead
            $Move = static function () use (&$P, &$target, $Tasks): void {
               $map = $Tasks->getValue();
               foreach ($map as $key => $tasks) {
                  if (isSet($tasks[$P]) && $key !== $target) {
                     $Task = $map[$key][$P];
                     unset($map[$key][$P]);
                     if ($map[$key] === []) {
                        unset($map[$key]);
                     }
                     $map[$target][$P] = $Task;
                  }
               }
               $Tasks->setValue(null, $map);
            };
            $Handler = static function () use (&$ran, $nested, $Move): void {
               $ran[] = 'M';
               if ($nested) {
                  Timer::tick();
               }
               $Move();
            };
            $Periodic = static function () use (&$ran): void {
               $ran[] = 'P';
            };
            // ! Same frame: P runs first and re-arms, then M moves it; nested: M
            //   runs first, its nested tick runs D and P (D's bucket was
            //   inserted first) and re-arms P
            if ($nested) {
               $M = Timer::add(30, $Handler, persistent: false);
               $P = Timer::add(1, $Periodic);
            }
            else {
               $P = Timer::add(1, $Periodic);
               $M = Timer::add(30, $Handler, persistent: false);
            }
            $D = Timer::add(30, static function () use (&$ran): void {
               $ran[] = 'D';
            }, persistent: false);
            $target = $due($nested ? [$M => 3, $P => 2, $D => 1] : [$P => 3, $M => 3, $D => 1]) - 1;
            $Reports = [];
            $escaped = $alarm();
            $outcomes[] = [
               "a task re-armed by {$label} into a later due bucket runs once",
               [$escaped, $ran],
               [null, $nested ? ['M', 'D', 'P'] : ['P', 'M', 'D']],
            ];
         }

         // @ A task added and made due during a tick first runs on the next one —
         //   even in a later due bucket this tick still reaches (Y's)
         Timer::del();
         $ran = [];
         $Y = 0;
         $A = Timer::add(30, static function () use (&$ran, &$Y, $Tasks): void {
            $ran[] = 'A';
            $X = Timer::add(30, static function () use (&$ran): void {
               $ran[] = 'X';
            }, persistent: false);
            // ! Move X into Y's bucket: due, and still ahead of this handler
            $map = $Tasks->getValue();
            $target = null;
            foreach ($map as $runtime => $tasks) {
               if (isSet($tasks[$Y])) {
                  $target = $runtime;
               }
            }
            foreach ($map as $runtime => $tasks) {
               if (isSet($tasks[$X]) && $target !== null) {
                  $Task = $map[$runtime][$X];
                  unset($map[$runtime][$X]);
                  if ($map[$runtime] === []) {
                     unset($map[$runtime]);
                  }
                  $map[$target][$X] = $Task;
               }
            }
            $Tasks->setValue(null, $map);
         }, persistent: false);
         $Y = Timer::add(30, static function () use (&$ran): void {
            $ran[] = 'Y';
         }, persistent: false);
         $due([$A => 2, $Y => 1]);
         $Reports = [];
         $escapes = [$alarm()];
         $first = $ran;
         $escapes[] = $alarm();
         $outcomes[] = [
            'a task added during a tick into a later due bucket runs on the next one',
            [$escapes, count($Reports), $first, $ran],
            [[null, null], 0, ['A', 'Y'], ['A', 'Y', 'X']],
         ];

         // @@ BG-H7-9: a throwing destructor never escapes the tick
         $shapes = [
            'a one-shot capture' => static function (bool $throw): array {
               $X = new T13Capture($throw);
               $id = Timer::add(30, static function () use ($X): void {}, persistent: false);

               return [$id => 1];
            },
            'an $args object' => static function (bool $throw): array {
               $id = Timer::add(30, static function (T13Capture $X): void {}, [new T13Capture($throw)], persistent: false);

               return [$id => 1];
            },
            'a capture cancelled by another handler' => static function (bool $throw): array {
               $X = new T13Capture($throw);
               $B = 0;
               $A = Timer::add(30, static function () use (&$B): void {
                  Timer::del($B);
               }, persistent: false);
               $B = Timer::add(30, static function () use ($X): void {});

               return [$A => 1, $B => 1];
            },
            'a capture in a reference cycle' => static function (bool $throw): array {
               $C = new T13Cycle($throw);
               $C->Handler = static function () use ($C): void {};
               $id = Timer::add(30, $C->Handler, persistent: false);

               return [$id => 1];
            },
            'a persistent timer that deletes itself' => static function (bool $throw): array {
               $X = new T13Capture($throw);
               $id = 0;
               $id = Timer::add(30, static function () use (&$id, $X): void {
                  Timer::del($id);
               });

               return [$id => 1];
            },
         ];
         foreach ($shapes as $label => $Build) {
            foreach ([true, false] as $throw) {
               Timer::del();
               T13Capture::$destructions = 0;
               $due($Build($throw));
               $escaped = $alarm();
               $outcomes[] = [
                  ($throw ? '' : 'control: ') . "{$label} is released once, contained",
                  [$escaped, T13Capture::$destructions],
                  [null, 1],
               ];
            }
         }

         Timer::del();
         $Reports = [];
         $id = Timer::add(30, static function (): void {
            throw new T13Poison('handler failure');
         }, persistent: false);
         $due([$id => 1]);
         $outcomes[] = ['a poisoned handler exception is reported once, contained', [$alarm(), count($Reports)], [null, 1]];

         // @@ SIG-M1: poison three levels deep — a capture's, and a handler's
         Timer::del();
         T13Poison::$released = T13Poison2::$released = T13Poison3::$released = 0;
         $id = Timer::add(30, static function (): void {}, [new class {
            public function __destruct ()
            {
               throw new T13Poison3('capture failure');
            }
         }], persistent: false);
         $due([$id => 1]);
         $outcomes[] = [
            'a three-level poisoned capture destructor is contained, each failure released once',
            [$alarm(), [T13Poison3::$released, T13Poison2::$released, T13Poison::$released], count($Parked->getValue())],
            [null, [1, 1, 1], 0],
         ];

         Timer::del();
         $Reports = [];
         $id = Timer::add(30, static function (): void {
            throw new T13Poison3('handler failure');
         }, persistent: false);
         $due([$id => 1]);
         $outcomes[] = ['a three-level poisoned handler exception is reported, contained', [$alarm(), count($Reports)], [null, 1]];

         // @ A poisoned capture freed only by the cycle collector stays contained
         Timer::del();
         $Cycle = new T13PoisonCycle;
         $Cycle->Handler = static function () use ($Cycle): void {};
         $id = Timer::add(30, $Cycle->Handler, persistent: false);
         $Cycle = null;
         $due([$id => 1]);
         $outcomes[] = ['a poisoned capture in a reference cycle is contained', $alarm(), null];

         // @ Many plain destructor failures are all released — none parked
         Timer::del();
         T13Capture::$destructions = 0;
         $Wide = [];
         for ($i = 0; $i < 100; $i++) {
            $Wide[] = new T13Capture(true);
         }
         $id = Timer::add(30, static function (): void {}, $Wide, persistent: false);
         $Wide = [];
         $due([$id => 1]);
         $outcomes[] = [
            'a hundred plain destructor failures are released, none parked',
            [$alarm(), T13Capture::$destructions, count($Parked->getValue())],
            [null, 100, 0],
         ];

         // @ An endless chain is cut by the generation budget: parked, not spun
         Timer::del();
         T13Chain::$made = 0;
         T13Chain::$armed = true;
         $id = Timer::add(30, static function (): void {}, [new class {
            public function __destruct ()
            {
               throw new T13Chain('chain start');
            }
         }], persistent: false);
         $due([$id => 1]);
         $escaped = $alarm();
         $made = T13Chain::$made;
         $parked = count($Parked->getValue());
         T13Chain::$armed = false;
         $Parked->setValue(null, []);
         $outcomes[] = [
            'an endless destructor chain is parked by the generation budget',
            [$escaped, $made < 1000, $parked],
            [null, true, 1],
         ];

         // @ A poisoned failure from a reset observer never escapes Timer::del(),
         //   is tried once per drain (never spun) and again on a later touch
         $calls = 0;
         $observer = TimerReset::add(static function () use (&$calls): void {
            $calls++;
            // ! Leaves release work queued, and stops failing after a while:
            //   a regression then shows as extra calls, not as a spinning suite
            Timer::del((int) Timer::add(30, static function (): void {}));
            if ($calls <= 8) {
               throw new T13Poison2('observer failure');
            }
         });
         $escaped = null;
         $retried = 0;
         try {
            Timer::del();
            $once = $calls;
            Timer::add(30, static function (): void {});
            $retried = $calls;
         }
         catch (Throwable $Escaped) {
            $Escapes[] = $Escaped;
            $escaped = $Escaped::class;
            $once = $calls;
         }
         finally {
            TimerReset::del($observer);
         }
         $outcomes[] = [
            'a poisoned reset observer failure is contained, tried once, retried on a later touch',
            [$escaped, $once, $retried],
            [null, 1, 2],
         ];

         // @@ EVENTS-3: a handler failure is reported, never swallowed
         Timer::del();
         $Reports = [];
         $id = Timer::add(30, static function (): void {
            throw new RuntimeException('persistent failure');
         });
         $due([$id => 1]);
         $alarm();
         $outcomes[] = [
            'a persistent failure is reported with its origin and id, and stays scheduled',
            [$Reports, TimerRegistry::check($id), $wheel($id)],
            [[[RuntimeException::class, ['origin' => 'timer', 'id' => $id]]], true, true],
         ];

         Timer::del();
         $Reports = [];
         // ! An Error, not an Exception: the most common real handler failure
         $id = Timer::add(30, static function (): void {
            throw new TypeError('one-shot failure');
         }, persistent: false);
         $due([$id => 1]);
         $outcomes[] = [
            'a one-shot Error is reported and released',
            [$alarm(), $Reports[0][0] ?? null, TimerRegistry::check($id)],
            [null, TypeError::class, false],
         ];

         Timer::del();
         $Reports = [];
         $id = Timer::add(30, static function (): void {});
         $due([$id => 1]);
         $alarm();
         $outcomes[] = ['control: a successful handler reports nothing', count($Reports), 0];

         // @@ A reporter that throws a poisoned exception — one or two levels
         //   deep — never escapes either, and the timer stays scheduled
         foreach (['one level' => T13Poison::class, 'two levels' => T13Poison2::class] as $label => $Poison) {
            Timer::del();
            $count = count(Throwables::$reporters);
            Throwables::$reporters[] = static function () use ($Poison): void {
               throw new $Poison('reporter failure');
            };
            $id = Timer::add(30, static function (): void {
               throw new RuntimeException('handler failure');
            });
            $due([$id => 1]);
            $escaped = $alarm();
            array_splice(Throwables::$reporters, $count);
            $outcomes[] = [
               "a poisoned reporter failure ({$label}) is contained and the timer stays scheduled",
               [$escaped, TimerRegistry::check($id), $wheel($id)],
               [null, true, true],
            ];
         }
      }
      finally {
         T13Chain::$armed = false;
         $Parked->setValue(null, []);
         Timer::del();
         pcntl_alarm(0);
         array_splice(Throwables::$reporters, $reporters);
         pcntl_signal(SIGALRM, $Previous === false ? SIG_DFL : $Previous);

         // @ Release what escaped (a red run) contained, the way Timer does: a
         //   failure is one more value to release, never dropped on its catch
         $budget = 64;
         while ($Escapes !== [] && $budget-- > 0) {
            $Escaped = array_pop($Escapes);
            try {
               unset($Escaped);
            }
            catch (Throwable $Failure) {
               $Escapes[] = $Failure;
               $Failure = null;
            }
         }
      }

      // @ Yield only after the wheel, the reporters and the handler are restored
      foreach ($outcomes as [$description, $actual, $expected]) {
         yield new Assertion(description: $description . ': ' . json_encode($actual))
            ->expect($actual)
            ->to->be($expected)
            ->assert();
      }
   })
);
