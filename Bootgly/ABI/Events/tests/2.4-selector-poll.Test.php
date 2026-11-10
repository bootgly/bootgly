<?php

use Bootgly\ABI\Events\Selector;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Selector: a deadline that is not in the future polls without waiting',
   test: function () {
      $Selector = new Selector();
      [$near, $far] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      $Selector->watch($near);

      // # Zero deadline, nothing ready
      $started = hrtime(true);
      $ready = $Selector->wait(0);
      $elapsed = hrtime(true) - $started;
      yield assert(
         assertion: $ready === 0 && $Selector->interrupted === false && $elapsed < 250_000_000,
         description: 'wait(0) with nothing ready returns at once'
      );

      // # A deadline already behind the clock
      $started = hrtime(true);
      $ready = $Selector->wait($Selector->Clock->tick - 1);
      $elapsed = hrtime(true) - $started;
      yield assert(
         assertion: $ready === 0 && $elapsed < 250_000_000,
         description: 'A past deadline returns at once'
      );

      // # A poll still reports what is ready
      fwrite($far, 'x');
      $started = hrtime(true);
      $ready = $Selector->wait(0);
      $elapsed = hrtime(true) - $started;
      yield assert(
         assertion: $ready === 1 && isSet($Selector->readable[(int) $near]) && $elapsed < 250_000_000,
         description: 'A poll reports the streams that are already ready'
      );

      // # Nothing watched: a poll is a no-op, not a refusal
      $Empty = new Selector();
      $started = hrtime(true);
      $ready = $Empty->wait(0);
      $elapsed = hrtime(true) - $started;
      yield assert(
         assertion: $ready === 0 && $Empty->interrupted === false && $elapsed < 250_000_000,
         description: 'A poll with nothing watched returns zero at once'
      );

      fclose($near);
      fclose($far);
   }
);
