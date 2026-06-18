<?php

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite\Test\Specification;

use InvalidArgumentException;


return new Specification(
   description: 'Logger->log() forces named arguments and validates the single level + context',
   test: function () {
      $saved = Display::$segments;
      Display::show(Display::MESSAGE);

      $Logger = new Logger('x');
      $Logger->Handlers = new Handlers; // no output sink

      // # Positional argument rejected
      $thrown = false;
      try {
         $Logger->log('positional'); // @phpstan-ignore-line argument.type
      } catch (InvalidArgumentException) {
         $thrown = true;
      }
      yield assert(
         assertion: $thrown,
         description: 'a positional argument is rejected'
      );

      // # Unknown level rejected
      $thrown = false;
      try {
         $Logger->log(foo: 'bar'); // @phpstan-ignore-line
      } catch (InvalidArgumentException) {
         $thrown = true;
      }
      yield assert(
         assertion: $thrown,
         description: 'an unknown level name is rejected'
      );

      // # Multiple levels accepted — one record each (the point of the variadic)
      $accepted = true;
      try {
         $Logger->log(info: 'a', error: 'b');
      } catch (\Throwable) {
         $accepted = false;
      }
      yield assert(
         assertion: $accepted,
         description: 'multiple level arguments are accepted, not rejected'
      );

      // # Missing level rejected
      $thrown = false;
      try {
         $Logger->log(context: ['a' => 1]);
      } catch (InvalidArgumentException) {
         $thrown = true;
      }
      yield assert(
         assertion: $thrown,
         description: 'a call without a level is rejected'
      );

      // # Valid named call passes
      $ok = true;
      try {
         $Logger->log(info: 'fine');
      } catch (\Throwable) {
         $ok = false;
      }
      yield assert(
         assertion: $ok,
         description: 'a valid named-level call is accepted'
      );

      Display::show($saved);
   }
);
