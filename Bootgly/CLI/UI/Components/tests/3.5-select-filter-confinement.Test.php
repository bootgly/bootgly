<?php

namespace Bootgly\CLI\UI\Components;


use const PHP_EOL;
use function assert;
use function fopen;
use function json_encode;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'It should confirm and toggle only options the filter shows',
   test: function () {
      // ! Selects with in-memory streams
      $build = static function (array $options): Select {
         $stream = fopen('php://memory', 'r+');
         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Select = new Select($Input, $Output);
         $Select->options = $options;

         return $Select;
      };
      $countries = [
         'Argentina', 'Australia', 'Brazil', 'Canada', 'China', 'Denmark',
         'Egypt', 'France', 'Germany', 'India', 'Italy', 'Japan', 'Mexico',
         'Norway', 'Portugal', 'Sweden', 'Switzerland', 'United Kingdom',
         'United States', 'Vietnam',
      ];

      // @ A zero-match filter parks the aim on a hidden option — Enter must
      //   confirm nothing, not the option the frame does not draw
      $Select = $build($countries);
      foreach (["\e[B", "\e[B", 'z', 'z'] as $key) {
         $Select->control($key);
      }
      $continues = $Select->control(PHP_EOL);

      yield assert(
         assertion: $Select->selected === [] && $continues === false,
         description: 'Enter over "(no matches)" confirms nothing, found: '
            . json_encode([$Select->selected, $Select->aimed, $Select->filter])
      );

      // @ …and Space must not toggle the hidden aim either
      $Select = $build($countries);
      foreach (["\e[B", "\e[B", 'z', 'z', ' '] as $key) {
         $Select->control($key);
      }

      yield assert(
         assertion: $Select->selected === [],
         description: 'Space over "(no matches)" selects nothing, found: '
            . json_encode([$Select->selected, $Select->aimed, $Select->filter])
      );

      // @ The refusal is state, not a latch: Backspace back to a matching
      //   filter re-aims and Enter confirms the visible option again
      $Select->control("\x7F");
      $Select->control(PHP_EOL);

      yield assert(
         assertion: $Select->selected === [2] && $Select->filter === 'z',
         description: 'Backspace to a matching filter restores confirmation, found: '
            . json_encode([$Select->selected, $Select->filter])
      );

      // @ Control that must not move: a filter WITH matches still confirms the
      //   aimed visible option
      $Select = $build($countries);
      foreach (['s', 'w', "\e[B", "\e[B", PHP_EOL] as $key) {
         $Select->control($key);
      }

      yield assert(
         assertion: $Select->selected === [15],
         description: 'A matching filter confirms the aimed visible option, found: '
            . json_encode([$Select->selected, $Select->aimed])
      );

      // @ The same guard refuses the empty-list toggle, whose aim points at no
      //   option at all
      $Select = $build([]);
      $Select->control(' ');

      yield assert(
         assertion: $Select->selected === [],
         description: 'Space on an empty list selects nothing, found: '
            . json_encode($Select->selected)
      );
   }
);
