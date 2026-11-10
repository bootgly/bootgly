<?php

use Bootgly\ABI\Events\Selector;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Selector: wait() gives up at an absolute monotonic deadline, never before it',
   test: function () {
      $Selector = new Selector();
      [$near, $far] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      $Selector->watch($near);

      // # Deadline with nothing ready
      $started = hrtime(true);
      $deadline = $Selector->Clock->tick + 100_000_000;
      $ready = $Selector->wait($deadline);
      $elapsed = hrtime(true) - $started;

      yield assert(
         assertion: $ready === 0 && $Selector->interrupted === false && $Selector->readable === [],
         description: 'The deadline passes: no ready stream and no interruption'
      );
      yield assert(
         assertion: hrtime(true) >= $deadline,
         description: 'A wait that timed out never returns before its deadline'
      );
      yield assert(
         assertion: $elapsed < 400_000_000,
         description: "The wait returns close to the deadline (took {$elapsed} ns for 100 ms)"
      );

      // # Data before the deadline ends the wait early
      fwrite($far, 'x');
      $started = hrtime(true);
      $ready = $Selector->wait($Selector->Clock->tick + 2_000_000_000);
      $elapsed = hrtime(true) - $started;

      yield assert(
         assertion: $ready === 1 && $elapsed < 1_000_000_000,
         description: 'A ready stream ends the wait long before the deadline'
      );
      fread($near, 16);

      // # The deadline is read on the Selector's own Clock
      $Clock = new Clock();
      $Fake = new Selector($Clock);
      $Fake->watch($near);

      yield assert(
         assertion: $Fake->Clock === $Clock,
         description: 'The Selector exposes the Clock it was given'
      );

      $started = hrtime(true);
      $ready = $Fake->wait($Clock->tick + 80_000_000);
      $elapsed = hrtime(true) - $started;
      yield assert(
         assertion: $ready === 0 && $elapsed >= 80_000_000 && $elapsed < 400_000_000,
         description: 'The time left is the deadline minus the given Clock\'s tick'
      );

      $deadline = $Clock->tick + 2_000_000_000;
      $Clock->advance(3);
      $started = hrtime(true);
      $ready = $Fake->wait($deadline);
      $elapsed = hrtime(true) - $started;
      yield assert(
         assertion: $ready === 0 && $elapsed < 500_000_000,
         description: 'Once the Clock is past the deadline, the wait does not block'
      );

      fclose($near);
      fclose($far);
   }
);
