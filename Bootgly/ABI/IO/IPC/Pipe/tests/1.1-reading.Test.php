<?php

use Bootgly\ABI\IO\IPC\Pipe;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Pipe: reading() keeps yielding across timeouts and signals, and ends when the read end is gone',
   test: function () {
      // # A timeout yields null and the loop goes on
      $Pipe = new Pipe();
      $Pipe->open();

      $yielded = [];
      $started = hrtime(true);
      foreach ($Pipe->reading(1024, 50_000) as $chunk) {
         $yielded[] = $chunk;

         if (count($yielded) === 1) {
            $Pipe->write('late');
         }
         if (count($yielded) === 3) {
            break;
         }
      }
      $elapsed = hrtime(true) - $started;

      yield assert(
         assertion: $yielded === [null, 'late', null],
         description: 'A wait that times out yields null; data written afterwards is still read'
      );
      yield assert(
         assertion: $elapsed >= 100_000_000 && $elapsed < 1_000_000_000,
         description: "Each empty wait lasts the timeout, in microseconds (two took {$elapsed} ns)"
      );
      $Pipe->close();

      // # Without a timeout it polls
      $Pipe = new Pipe();
      $Pipe->open();
      $Pipe->write('now');

      $yielded = [];
      $started = hrtime(true);
      foreach ($Pipe->reading(1024) as $chunk) {
         $yielded[] = $chunk;

         if (count($yielded) === 3) {
            break;
         }
      }
      $elapsed = hrtime(true) - $started;

      yield assert(
         assertion: $yielded === ['now', null, null] && $elapsed < 100_000_000,
         description: 'A null timeout polls: what is there is read, and an empty pipe yields null at once'
      );

      // # The length bounds each chunk
      $Pipe->write('abcdef');
      $yielded = [];
      foreach ($Pipe->reading(4) as $chunk) {
         $yielded[] = $chunk;

         if (count($yielded) === 2) {
            break;
         }
      }
      yield assert(
         assertion: $yielded === ['abcd', 'ef'],
         description: 'Each chunk holds at most the given length'
      );

      // # A timeout too long to count in nanoseconds still waits and reads
      $Pipe->write('huge');
      $yielded = [];
      foreach ($Pipe->reading(1024, PHP_INT_MAX) as $chunk) {
         $yielded[] = $chunk;

         break;
      }
      yield assert(
         assertion: $yielded === ['huge'],
         description: 'A timeout of PHP_INT_MAX microseconds waits instead of failing'
      );

      // # A negative timeout is refused
      $yielded = [];
      foreach ($Pipe->reading(1024, -1) as $chunk) {
         $yielded[] = $chunk;
      }
      yield assert(
         assertion: $yielded === [false],
         description: 'A negative timeout yields false and ends the loop'
      );

      // # A closed read end ends the loop
      $Pipe->close();
      $yielded = [];
      foreach ($Pipe->reading(1024, 50_000) as $chunk) {
         $yielded[] = $chunk;
      }
      yield assert(
         assertion: $yielded === [false],
         description: 'Reading a closed pipe yields false once and ends'
      );

      // # ...also when it is closed between two waits
      $Pipe = new Pipe();
      $Pipe->open();
      $yielded = [];
      foreach ($Pipe->reading(1024, 20_000) as $chunk) {
         $yielded[] = $chunk;

         if (count($yielded) === 1) {
            $Pipe->close();
         }
      }
      yield assert(
         assertion: $yielded === [null, false],
         description: 'A pipe closed during the loop yields false on the next wait and ends'
      );
   }
);
