<?php

namespace Bootgly\CLI\UI\Components;


use function assert;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should invoke the Handler exactly once when the countdown finishes',
   test: function () {
      // ! Timer with an in-memory stream
      $Output = new Output('php://memory');

      $invocations = 0;

      $Timer = new Timer($Output);
      $Timer->seconds = 3600.0;
      $Timer->Handler = static function (Timer $Timer) use (&$invocations): void {
         $invocations++;
      };

      // @ Run the full lifecycle synchronously (finish() forces zero)
      $Timer->start();
      $Timer->finish();

      // @ Valid
      yield assert(
         assertion: $invocations === 1,
         description: 'finish() invokes the Handler'
      );

      // @ Finishing again never re-invokes the Handler
      $Timer->finish();
      $Timer->tick();

      yield assert(
         assertion: $invocations === 1,
         description: 'The Handler fires exactly once'
      );

      // @ A Timer that never started never fires the Handler
      $Idle = new Timer($Output);
      $Idle->Handler = static function (Timer $Timer) use (&$invocations): void {
         $invocations++;
      };
      $Idle->finish();

      yield assert(
         assertion: $invocations === 1,
         description: 'finish() on a never-started Timer is a no-op'
      );
   }
);
