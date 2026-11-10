<?php

use Bootgly\ABI\IO\IPC\Pipe;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Pipe: a signal during reading() is dispatched and the wait resumes without extending its timeout',
   skip: function_exists('proc_open') === false
      || function_exists('pcntl_signal') === false
      || function_exists('posix_kill') === false,
   test: function () {
      $handled = 0;
      $previous = pcntl_signal_get_handler(SIGUSR1);
      pcntl_signal(SIGUSR1, function () use (&$handled) {
         $handled++;
      });

      try {
         $Pipe = new Pipe();
         $Pipe->open();

         // ! A child that signals this process 600 ms from now — late in the
         //   1 s wait, so a wait restarted by the signal would end near 1.6 s
         $Process = proc_open(
            [PHP_BINARY, '-r', 'usleep(600000); posix_kill((int) $argv[1], (int) $argv[2]);', '--', (string) getmypid(), (string) SIGUSR1],
            [],
            $pipes
         );

         $yielded = [];
         $started = hrtime(true);
         foreach ($Pipe->reading(1024, 1_000_000) as $chunk) {
            $yielded[] = $chunk;

            break;
         }
         $elapsed = hrtime(true) - $started;
         proc_close($Process);

         yield assert(
            assertion: $yielded === [null],
            description: 'The signal does not end the loop: the wait resumes and times out'
         );
         yield assert(
            assertion: $handled === 1,
            description: 'The signal handler ran inside the loop'
         );
         yield assert(
            assertion: $elapsed >= 1_000_000_000 && $elapsed < 1_400_000_000,
            description: "The interrupted wait lasts one timeout, not more (took {$elapsed} ns for 1 s)"
         );

         $Pipe->close();
      }
      finally {
         pcntl_signal(SIGUSR1, $previous);
      }
   }
);
