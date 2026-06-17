<?php

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Formatters\Line;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Line formatter renders message, and adds severity/channel under DISPLAY_MESSAGE_WHEN_ID',
   test: function () {
      $saved = Display::$mode;

      $Line = new Line;
      $Record = new Record(Levels::Error, 'chan', 'boom');

      // # Plain message mode
      Display::$mode = Display::MESSAGE;
      $plain = $Line->format($Record);
      yield assert(
         assertion: str_contains($plain, 'boom'),
         description: 'the message is always rendered'
      );
      yield assert(
         assertion: str_contains($plain, 'ERROR') === false,
         description: 'no severity id under DISPLAY_MESSAGE'
      );

      // # Full id mode
      Display::$mode = Display::MESSAGE_WHEN_ID;
      $full = $Line->format($Record);
      yield assert(
         assertion: str_contains($full, 'ERROR'),
         description: 'severity label appears under DISPLAY_MESSAGE_WHEN_ID'
      );
      yield assert(
         assertion: str_contains($full, 'chan'),
         description: 'channel id appears under DISPLAY_MESSAGE_WHEN_ID'
      );

      Display::$mode = $saved;
   }
);
