<?php

use Bootgly\ABI\Debugging;
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'render() with TARGET_CLI produces the ANSI report string',
   test: function () {
      $Throwable = new Exception('CLI render probe message');

      $output = Throwables::render($Throwable, Debugging::TARGET_CLI);

      yield assert(
         assertion: str_contains($output, 'Exception'),
         description: 'output contains the throwable class name'
      );
      yield assert(
         assertion: str_contains($output, 'CLI render probe message'),
         description: 'output contains the throwable message'
      );
      yield assert(
         assertion: str_contains($output, ' at '),
         description: 'output contains the file location marker'
      );
      yield assert(
         assertion: str_contains($output, "\e["),
         description: 'output contains SGR escape sequences'
      );
      yield assert(
         assertion: str_contains($output, '<span') === false,
         description: 'CLI output contains no HTML markup'
      );
   }
);
