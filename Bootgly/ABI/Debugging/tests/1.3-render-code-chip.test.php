<?php

use Bootgly\ABI\Debugging;
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'render() shows the code chip only for meaningful throwable codes',
   test: function () {
      $Coded = new Exception('coded', 42);
      $Plain = new Exception('plain');

      $coded = Throwables::render($Coded, Debugging::TARGET_CLI);
      $plain = Throwables::render($Plain, Debugging::TARGET_CLI);

      yield assert(
         assertion: str_contains($coded, ' #42 '),
         description: 'code 42 renders the #42 chip'
      );
      yield assert(
         assertion: str_contains($plain, ' #0 ') === false,
         description: 'code 0 renders no chip'
      );
   }
);
