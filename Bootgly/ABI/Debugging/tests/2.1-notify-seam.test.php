<?php

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'notify() dispatches reporters once per throwable and swallows broken reporters',
   test: function () {
      // !
      $reporters = Throwables::$reporters;
      Throwables::$reporters = [];

      $received = [];
      Throwables::$reporters[] = static function (Throwable $Throwable, array $context) use (&$received): void {
         $received[] = [$Throwable, $context];
      };

      // @ Dispatch + dedup
      $Throwable = new Exception('seam probe');
      Throwables::notify($Throwable, ['origin' => 'test']);
      Throwables::notify($Throwable, ['origin' => 'test']);

      // @ Broken reporter must not cascade nor block the next reporter
      $after = false;
      Throwables::$reporters[] = static function (Throwable $Throwable, array $context): void {
         throw new RuntimeException('broken reporter');
      };
      Throwables::$reporters[] = static function (Throwable $Throwable, array $context) use (&$after): void {
         $after = true;
      };
      Throwables::notify(new Exception('second probe'));

      // ? Restore static state (suite runs in-process)
      Throwables::$reporters = $reporters;

      // :
      yield assert(
         assertion: count($received) === 2,
         description: 'reporter ran once for the deduplicated throwable and once for the second one'
      );
      yield assert(
         assertion: $received[0][0] instanceof Exception && $received[0][1] === ['origin' => 'test'],
         description: 'reporter received the throwable and its context'
      );
      yield assert(
         assertion: $after === true,
         description: 'a throwing reporter does not block the following reporters'
      );
   }
);
