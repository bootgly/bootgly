<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations\Waiters;


use const SIGKILL;
use const WNOHANG;
use function function_exists;
use function is_callable;
use function microtime;
use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function posix_kill;
use function substr;
use AssertionError;
use Exception;
use Throwable;

use Bootgly\ABI\IO\IPC\Pipe;
use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Waiter;


class RunTimeout extends Waiter
{
   // * Metadata
   // # Child
   /**
    * Exit code the child uses to report that the waited callable threw.
    *
    * The child is a forked copy of the whole test run, so it can NEVER report a
    * failure by throwing: an escaping Throwable does not fail the assertion, it
    * makes that second process continue running every remaining case and suite.
    * Its exit code is the only verdict the parent can read.
    */
   private const int EXIT_THROWN = 2;
   /**
    * Maximum bytes of the reason the child hands over the pipe.
    */
   private const int REASON_LENGTH = 4096;
   /**
    * How the child died, when it died from a Throwable.
    */
   private string $thrown = '';


   public function assert (mixed &$actual, mixed &$expected): bool
   {
      // ?
      if (is_callable($actual) === false) {
         return false;
      }

      // !
      $arguments = $this->arguments;
      // ! `wait()` takes its budget in MICROseconds — that is what `Waiter`
      //   documents, what `fail()` reports, and what the `$duration` setter
      //   converts to — while every measurement below is `microtime(true)`,
      //   which is seconds. Normalise the budget once, here, before the fork:
      //   the parent's kill guard and the child's verdict then compare like
      //   with like from one conversion, instead of each carrying its own.
      //   Comparing the two raw made every budget 1,000,000x too large, so no
      //   waiter assertion could fail and no long callable was ever cut short.
      //   The `?? $expected` this replaces was dead: `Waiter::$expected` is
      //   typed `int|float` and always assigned by the constructor, so the
      //   fallback could never be reached — and it made the division `mixed`.
      $timeout = $this->expected / 1000000;

      // ! Check if have pcntl_* extension
      if (function_exists('pcntl_fork') === false) {
         throw new AssertionError('The pcntl extension is required to use the RunTimeout.');
      }
      // ! Check if have posix_* extension
      if (function_exists('posix_kill') === false) {
         throw new AssertionError('The posix extension is required to use the RunTimeout.');
      }

      // ! The channel carrying the child's reason back — the exit code says that
      //   it failed, this says why. A pipe that cannot open costs the reason,
      //   never the verdict.
      $Pipe = new Pipe;
      $piped = $Pipe->open();

      $PID = pcntl_fork();
      if ($PID == -1) { // Error
         throw new Exception('Could not fork process');
      }
      else if ($PID) { // Parent process
         $initial = microtime(true);
         $duration = 0;
         $status = 0;
         $PID_child = 0;

         while (true) {
            $PID_child = pcntl_waitpid(
               $PID,
               $status,
               WNOHANG
            );

            $now = microtime(true);
            $duration = $now - $initial;

            // @ Check if the child process has exited
            if ($PID_child === -1 || $PID_child > 0) {
               break;
            }

            if ($timeout > 0 && $duration > $timeout) {
               $this->duration = $duration;

               posix_kill($PID, SIGKILL);
               pcntl_waitpid($PID, $status);

               $Pipe->close();

               return false;
            }
         }

         $this->duration = $duration;

         // ---

         // ! Whatever the child managed to report before dying
         $reason = $piped
            ? $Pipe->read(self::REASON_LENGTH)
            : false;
         $Pipe->close();

         // ? pcntl_waitpid() failed, so $status describes nothing
         if ($PID_child === -1) {
            return false;
         }
         // ? Killed by a signal — never a clean run
         if (pcntl_wifexited($status) === false) {
            return false;
         }

         $code = pcntl_wexitstatus($status);

         if ($code === self::EXIT_THROWN) {
            $this->thrown = ($reason === false || $reason === '')
               ? 'the waited callable threw'
               : $reason;
         }

         // : The exit code IS the verdict — ignoring it is what let a child that
         //   died on a Throwable be reported as a passing assertion
         return $code === 0;
      }
      else { // Child process
         $initial = microtime(true);

         try {
            // @ Execute the actual callable
            $actual(...$arguments);
         }
         catch (Throwable $Throwable) {
            // ! Report and die. Rethrowing here would leave this forked copy of
            //   the run alive, executing the rest of the suite a second time.
            if ($piped) {
               $origin = $Throwable::class;
               $file = $Throwable->getFile();
               $line = $Throwable->getLine();

               $Pipe->write(
                  substr(
                     "{$origin}: {$Throwable->getMessage()} in {$file}:{$line}",
                     0,
                     self::REASON_LENGTH
                  )
               );
            }

            exit(self::EXIT_THROWN);
         }

         $final = microtime(true);
         $duration = $final - $initial;

         // : Same convention as the parent's guard above — a timeout of 0 means
         //   there is no budget to blow (`wait(<Closure>)` stores 0 and routes
         //   its verdict to the subassertion instead), so only a real budget can
         //   fail here. The parent used to discard this code, which is why the
         //   child answering `1` to every subassertion form went unnoticed.
         exit($timeout > 0 && $duration > $timeout ? 1 : 0);
      }
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      // ?: The callable never ran out of time — it died. Reporting the budget
      //    here would point the reader at a limit the run never reached.
      if ($this->thrown !== '') {
         return new Fallback(
            'Failed asserting that the callable ran without throwing: %s.',
            [
               'thrown' => $this->thrown
            ],
            $verbosity
         );
      }

      // ! Reported in the unit the caller wrote it in — only the comparison in
      //   `assert()` is normalised, never the budget the reader is shown
      $timeout = $this->expected;

      return new Fallback(
         'Failed asserting that the callable executed within %s microseconds.',
         [
            'expected' => $timeout
         ],
         $verbosity
      );
   }
}
