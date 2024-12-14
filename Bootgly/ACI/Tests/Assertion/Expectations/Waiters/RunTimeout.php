<?php

namespace Bootgly\ACI\Tests\Assertion\Expectations\Waiters;


use ArgumentCountError;
use AssertionError;
use Exception;
use Throwable;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_kill;
use const WNOHANG;

use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Waiter;


class RunTimeout extends Waiter
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      // !
      $arguments = $this->arguments;
      $timeout = $this->timeout ?? $expected;

      // ! Validate if have pcntl_* extension
      if (function_exists('pcntl_fork') === false) {
         throw new AssertionError('The pcntl extension is required to use the RunTimeout.');
      }
      // ! Validate if have posix_* extension
      if (function_exists('posix_kill') === false) {
         throw new AssertionError('The posix extension is required to use the RunTimeout.');
      }

      // It's a shame that PHP doesn't have a native and dedicated Code API for processes.

      $PID = pcntl_fork();
      if ($PID == -1) { // Error
         throw new Exception('Could not fork process');
      }
      elseif ($PID) { // Parent process
         $initial = microtime(true);

         while (true) {
            $status = null;
            $PID_child = pcntl_waitpid($PID, $status, WNOHANG);

            // @ Check if the child process has exited
            if ($PID_child == -1 || $PID_child > 0) {
               break;
            }

            $now = microtime(true);
            if (($now - $initial) > $timeout) {
               posix_kill($PID, SIGKILL);
               pcntl_waitpid($PID, $status);

               return false;
            }

            // @ Wait for 10ms before checking again
            usleep(10000);
         }

         return true;
      }
      else { // Child process
         $initial = microtime(true);

         try {
            // @ Execute the actual callable
            $actual(...$arguments);
         }
         catch (ArgumentCountError $Error) {
            throw new AssertionError($Error->getMessage());
         }
         catch (Throwable $Throwable) {
            throw new AssertionError($Throwable->getMessage());
         }

         $final = microtime(true);
         $duration = $final - $initial;

         exit($duration <= $timeout ? 0 : 1);
      }
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      $timeout = $this->timeout ?? $expected;

      return new Fallback(
         'Failed asserting that the callable executed within %s seconds.',
         [
            'expected' => $timeout
         ],
         $verbosity
      );
   }
}