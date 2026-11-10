<?php

use Bootgly\ABI\Events\Selector;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Selector: a wait without a deadline blocks until a stream is ready',
   skip: function_exists('proc_open') === false,
   test: function () {
      // ! A child that writes to its output after 150 ms
      $Process = proc_open(
         [PHP_BINARY, '-r', 'usleep(150000); echo "ready";'],
         [1 => ['pipe', 'w']],
         $pipes
      );

      yield assert(
         assertion: is_resource($Process),
         description: 'The writer child starts'
      );

      $Selector = new Selector();
      $Selector->watch($pipes[1], payload: 'child');

      $started = hrtime(true);
      $ready = $Selector->wait();
      $elapsed = hrtime(true) - $started;

      yield assert(
         assertion: $ready === 1 && $Selector->readable === [(int) $pipes[1] => 'child'],
         description: 'The indefinite wait returns when the child writes'
      );
      yield assert(
         assertion: $elapsed >= 100_000_000 && $Selector->interrupted === false,
         description: "It blocked until then, with no interruption (took {$elapsed} ns)"
      );
      yield assert(
         assertion: fread($pipes[1], 16) === 'ready',
         description: 'The reported stream holds the data'
      );

      $Selector->forget($pipes[1]);
      fclose($pipes[1]);
      proc_close($Process);
   }
);
