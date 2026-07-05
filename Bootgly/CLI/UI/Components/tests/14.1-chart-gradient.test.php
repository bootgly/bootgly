<?php

namespace Bootgly\CLI\UI\Components;


use function assert;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\UI\Components\Chart\Gradient;


return new Specification(
   description: 'It should interpolate hex stops into truecolor or 256-color escapes',
   test: function () {
      // @ Linear (2 stops), truecolor forced
      $Gradient = new Gradient(['#000000', '#ffffff'], extended: false);

      yield assert(
         assertion: $Gradient->sample(0) === "\e[38;2;0;0;0m"
            && $Gradient->sample(100) === "\e[38;2;255;255;255m",
         description: 'Edges sample the first and last stops'
      );
      yield assert(
         assertion: $Gradient->sample(50) === "\e[38;2;128;128;128m",
         description: 'Midpoint interpolates linearly'
      );
      yield assert(
         assertion: $Gradient->sample(-5) === $Gradient->sample(0)
            && $Gradient->sample(150) === $Gradient->sample(100),
         description: 'Out-of-range percentages clamp to the edges'
      );

      // @ Three stops — midpoint hits the middle stop exactly
      $Heat = new Gradient(['#000000', '#ff0000', '#ffffff'], extended: false);

      yield assert(
         assertion: $Heat->sample(50) === "\e[38;2;255;0;0m",
         description: 'Three stops pass through the middle stop at 50%'
      );

      // @ Solid (1 stop)
      $Solid = new Gradient(['#00ffff'], extended: false);

      yield assert(
         assertion: $Solid->sample(0) === $Solid->sample(100)
            && $Solid->sample(0) === "\e[38;2;0;255;255m",
         description: 'A single stop renders a solid color at any percentage'
      );

      // @ 256-color cube fallback
      $Cube = new Gradient(['#000000', '#ffffff'], extended: true);

      yield assert(
         assertion: $Cube->sample(0) === "\e[38;5;16m"
            && $Cube->sample(100) === "\e[38;5;231m",
         description: 'Extended mode maps RGB to the 6x6x6 cube (16 and 231)'
      );
   }
);
