<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ABI\Events\Selector;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Selector: a signal interrupts the wait, which resumes without extending the deadline',
   skip: function_exists('proc_open') === false
      || function_exists('pcntl_signal') === false
      || function_exists('posix_kill') === false,
   test: function () {
      // ! A child that signals this process after a delay
      $signal = function (int $delay): mixed {
         return proc_open(
            [PHP_BINARY, '-r', 'usleep((int) $argv[3]); posix_kill((int) $argv[1], (int) $argv[2]);', '--', (string) getmypid(), (string) SIGUSR1, (string) $delay],
            [],
            $pipes
         );
      };

      $handled = 0;
      $previous = pcntl_signal_get_handler(SIGUSR1);
      pcntl_signal(SIGUSR1, function () use (&$handled) {
         $handled++;
      });

      try {
         $Selector = new Selector();
         [$near, $far] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         $Selector->watch($near);

         // # A signal cuts the wait short
         $Process = $signal(150_000);
         $started = hrtime(true);
         $deadline = $Selector->Clock->tick + 600_000_000;

         $ready = $Selector->wait($deadline);
         $interrupted = hrtime(true) - $started;

         yield assert(
            assertion: $ready === 0 && $Selector->interrupted === true,
            description: 'The signal ends the wait with no ready stream and the interruption reported'
         );
         yield assert(
            assertion: $interrupted < 500_000_000,
            description: "The interrupted wait returns before its deadline (after {$interrupted} ns of 600 ms)"
         );

         pcntl_signal_dispatch();
         yield assert(
            assertion: $handled === 1,
            description: 'The caller dispatches the signal that interrupted the wait'
         );

         // # Resuming with the same deadline does not extend it
         $ready = $Selector->wait($deadline);
         $total = hrtime(true) - $started;

         yield assert(
            assertion: $ready === 0 && $Selector->interrupted === false,
            description: 'The resumed wait ends at the deadline, with the interruption flag cleared'
         );
         yield assert(
            assertion: $total >= 600_000_000 && $total < 1_000_000_000,
            description: "Both waits together last one deadline, not two (took {$total} ns for 600 ms)"
         );
         proc_close($Process);

         // # With nothing watched, the sleep is interrupted the same way
         $Empty = new Selector();
         $Process = $signal(150_000);
         $started = hrtime(true);
         $deadline = $Empty->Clock->tick + 600_000_000;

         $ready = $Empty->wait($deadline);
         $interrupted = hrtime(true) - $started;
         pcntl_signal_dispatch();

         yield assert(
            assertion: $ready === 0 && $Empty->interrupted === true && $interrupted < 500_000_000 && $handled === 2,
            description: 'A sleep on an empty Selector is interrupted by a signal too'
         );

         $ready = $Empty->wait($deadline);
         $total = hrtime(true) - $started;
         yield assert(
            assertion: $ready === 0 && $Empty->interrupted === false && $total >= 600_000_000 && $total < 1_000_000_000,
            description: 'The resumed sleep ends at the same deadline'
         );
         proc_close($Process);

         // # An exception thrown by an asynchronous handler is the caller's
         $counting = pcntl_signal_get_handler(SIGUSR1);
         $asynchronous = pcntl_async_signals(true);
         pcntl_signal(SIGUSR1, function () {
            throw new RuntimeException('raised by the handler');
         });

         $Process = $signal(150_000);
         $raised = '';
         $ready = null;
         try {
            $ready = $Selector->wait($Selector->Clock->tick + 2_000_000_000);
         }
         catch (RuntimeException $Exception) {
            $raised = $Exception->getMessage();
         }
         finally {
            pcntl_async_signals($asynchronous);
            pcntl_signal(SIGUSR1, $counting);
         }
         proc_close($Process);

         yield assert(
            assertion: $raised === 'raised by the handler' && $ready === null,
            description: 'An exception thrown by an asynchronous signal handler during the wait reaches the caller'
         );

         // # ...whatever its class: the ones the call itself may raise are
         //   told apart by what raised them
         $classes = [];
         foreach ([
            static fn (): Throwable => new ErrorException('raised by the handler'),
            static fn (): Throwable => new TypeError('raised by the handler'),
            static fn (): Throwable => new ValueError('raised by the handler'),
         ] as $Raise) {
            $counting = pcntl_signal_get_handler(SIGUSR1);
            $asynchronous = pcntl_async_signals(true);
            pcntl_signal(SIGUSR1, function () use ($Raise) {
               throw $Raise();
            });

            $Process = $signal(150_000);
            $ready = null;
            try {
               $ready = $Selector->wait($Selector->Clock->tick + 2_000_000_000);
               $classes[] = "swallowed: {$ready}";
            }
            catch (Throwable $Throwable) {
               $classes[] = $Throwable::class . ': ' . $Throwable->getMessage();
            }
            finally {
               pcntl_async_signals($asynchronous);
               pcntl_signal(SIGUSR1, $counting);
            }
            proc_close($Process);
         }

         yield assert(
            assertion: $classes === [
               'ErrorException: raised by the handler',
               'TypeError: raised by the handler',
               'ValueError: raised by the handler',
            ],
            description: 'An ErrorException, a TypeError and a ValueError thrown by an asynchronous signal handler reach the caller too — observed: '
               . json_encode($classes)
         );

         // # An error handler that ignores `@` turns the warning of the
         //   interrupted call into an ErrorException: the call's own, so the
         //   wait still answers an interruption and raises nothing
         $Process = $signal(150_000);
         $outcome = '';
         set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $level, $file, $line);
         });
         try {
            $ready = $Selector->wait($Selector->Clock->tick + 2_000_000_000);
            $outcome = "returned {$ready}, interrupted: " . json_encode($Selector->interrupted);
         }
         catch (Throwable $Throwable) {
            $outcome = $Throwable::class . ': ' . $Throwable->getMessage();
         }
         finally {
            restore_error_handler();
         }
         proc_close($Process);
         pcntl_signal_dispatch();

         yield assert(
            assertion: $outcome === 'returned 0, interrupted: true',
            description: "An interruption whose warning an error handler ignoring `@` turned into an ErrorException still ends the wait as an interruption — observed: {$outcome}"
         );

         // # A stream_select() call of the handler itself is the handler's:
         //   the Selector's call is the one made from the Selector's own file,
         //   so the ValueError of a handler that hands stream_select() nothing
         //   to select reaches the caller, never taken for an interruption
         $counting = pcntl_signal_get_handler(SIGUSR1);
         $asynchronous = pcntl_async_signals(true);
         pcntl_signal(SIGUSR1, static function (): void {
            $read = [];
            $write = null;
            $except = null;
            stream_select($read, $write, $except, 0, 0);
         });

         $Process = $signal(150_000);
         $outcome = '';
         try {
            $ready = $Selector->wait($Selector->Clock->tick + 2_000_000_000);
            $outcome = "swallowed: {$ready}, interrupted: " . json_encode($Selector->interrupted);
         }
         catch (Throwable $Throwable) {
            $outcome = $Throwable::class . ': ' . $Throwable->getMessage();
         }
         finally {
            pcntl_async_signals($asynchronous);
            pcntl_signal(SIGUSR1, $counting);
         }
         proc_close($Process);

         yield assert(
            assertion: $outcome === 'ValueError: No stream arrays were passed',
            description: "The ValueError of an asynchronous handler's own stream_select() call reaches the caller — observed: {$outcome}"
         );

         // @ The same, with an error whose message names stream_select(): a
         //   handler's call with a negative timeout — still not the
         //   Selector's own call, so it reaches the caller too
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         $counting = pcntl_signal_get_handler(SIGUSR1);
         $asynchronous = pcntl_async_signals(true);
         pcntl_signal(SIGUSR1, static function () use ($Pair): void {
            $read = [$Pair[0]];
            $write = null;
            $except = null;
            stream_select($read, $write, $except, -1, 0);
         });

         $Process = $signal(150_000);
         $named = '';
         try {
            $ready = $Selector->wait($Selector->Clock->tick + 2_000_000_000);
            $named = "swallowed: {$ready}, interrupted: " . json_encode($Selector->interrupted);
         }
         catch (Throwable $Throwable) {
            $named = $Throwable::class . ': ' . $Throwable->getMessage();
         }
         finally {
            pcntl_async_signals($asynchronous);
            pcntl_signal(SIGUSR1, $counting);
            fclose($Pair[0]);
            fclose($Pair[1]);
         }
         proc_close($Process);

         yield assert(
            assertion: str_starts_with($named, 'ValueError: stream_select(): Argument #4 ($seconds)'),
            description: "The ValueError an asynchronous handler's stream_select() call names itself in reaches the caller too — observed: {$named}"
         );

         // # What user code raises during the poll that tells an interruption
         //   from a failure is that code's own: a watched stream wrapper whose
         //   cast the interrupting signal armed throws there, and its
         //   exception reaches the caller — never taken for the poll's failure
         $Wrapper = new class {
            public static mixed $Inner = null;
            public static bool $armed = false;
            public mixed $context;

            public function stream_open (string $path, string $mode, int $options, null|string &$opened): bool
            {
               return true;
            }
            public function stream_cast (int $as): mixed
            {
               if (self::$armed) {
                  throw new ValueError('raised by the stream wrapper');
               }

               return self::$Inner;
            }
         };
         [$inner, $outer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         // ! Static state of a class declared once per process: reset it
         $Wrapper::$Inner = $inner;
         $Wrapper::$armed = false;
         stream_wrapper_register('selector-armed', $Wrapper::class);
         $Wrapped = fopen('selector-armed://cast', 'r');
         $Selector->watch($Wrapped);
         $counting = pcntl_signal_get_handler(SIGUSR1);
         $asynchronous = pcntl_async_signals(true);
         pcntl_signal(SIGUSR1, static function () use ($Wrapper): void {
            $Wrapper::$armed = true;
         });

         $Process = $signal(150_000);
         $started = hrtime(true);
         $outcome = '';
         try {
            $ready = $Selector->wait($Selector->Clock->tick + 2_000_000_000);
            $outcome = "swallowed: {$ready}, interrupted: " . json_encode($Selector->interrupted);
         }
         catch (Throwable $Throwable) {
            $outcome = $Throwable::class . ': ' . $Throwable->getMessage();
         }
         finally {
            pcntl_async_signals($asynchronous);
            pcntl_signal(SIGUSR1, $counting);
            $Selector->forget($Wrapped);
            fclose($Wrapped);
            stream_wrapper_unregister('selector-armed');
         }
         $elapsed = hrtime(true) - $started;
         proc_close($Process);
         fclose($inner);
         fclose($outer);

         yield assert(
            assertion: $outcome === 'ValueError: raised by the stream wrapper' && $elapsed < 1_000_000_000,
            description: "An exception a stream wrapper raises during the poll after an interruption reaches the caller (after {$elapsed} ns of 2 s) — observed: {$outcome}"
         );

         fclose($near);
         fclose($far);
      }
      finally {
         pcntl_signal(SIGUSR1, $previous);
      }
   }
);
