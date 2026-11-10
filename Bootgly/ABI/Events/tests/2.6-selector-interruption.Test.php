<?php

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

         fclose($near);
         fclose($far);
      }
      finally {
         pcntl_signal(SIGUSR1, $previous);
      }
   }
);
