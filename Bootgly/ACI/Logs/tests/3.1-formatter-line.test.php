<?php

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Formatters\Line;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Line formatter assembles each Display segment flag independently',
   test: function () {
      $saved = Display::$segments;

      $Line = new Line;
      $Record = new Record(Levels::Error, 'chan', 'boom', ['order' => 42]);

      // # Message only — inline, no annotations, no trailing newline
      Display::show(Display::MESSAGE);
      $plain = $Line->format($Record);
      yield assert(
         assertion: str_contains($plain, 'boom'),
         description: 'the message is rendered'
      );
      yield assert(
         assertion: str_contains($plain, 'ERROR') === false && str_contains($plain, 'chan') === false,
         description: 'no severity/channel under MESSAGE alone'
      );
      yield assert(
         assertion: str_contains($plain, "\n") === false,
         description: 'MESSAGE alone stays inline (no trailing newline)'
      );

      // # SEVERITY independent of CHANNEL
      Display::show(Display::MESSAGE, Display::SEVERITY);
      $severity = $Line->format($Record);
      yield assert(
         assertion: str_contains($severity, 'ERROR') && str_contains($severity, 'chan') === false,
         description: 'SEVERITY shows the level label without the channel'
      );

      // # CHANNEL independent of SEVERITY
      Display::show(Display::MESSAGE, Display::CHANNEL);
      $channel = $Line->format($Record);
      yield assert(
         assertion: str_contains($channel, 'chan') && str_contains($channel, 'ERROR') === false,
         description: 'CHANNEL shows the channel without the level label'
      );

      // # CHANNEL + SEVERITY compose as channel.LEVEL
      Display::show(Display::MESSAGE, Display::CHANNEL, Display::SEVERITY);
      $origin = $Line->format($Record);
      yield assert(
         assertion: str_contains($origin, 'chan.') && str_contains($origin, 'ERROR'),
         description: 'CHANNEL and SEVERITY compose into channel.LEVEL'
      );

      // # TIMESTAMP
      Display::show(Display::MESSAGE, Display::TIMESTAMP);
      $timed = $Line->format($Record);
      yield assert(
         assertion: str_contains($timed, '[') && str_contains($timed, ']'),
         description: 'TIMESTAMP brackets the time before the message'
      );

      // # CONTEXT dumps the record context inline
      Display::show(Display::MESSAGE, Display::CONTEXT);
      $withContext = $Line->format($Record);
      yield assert(
         assertion: str_contains($withContext, '"order"') && str_contains($withContext, '42'),
         description: 'CONTEXT appends the encoded context'
      );

      Display::show($saved);
   }
);
