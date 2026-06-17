<?php

use Bootgly\ACI\Logs\Formatters\JSON;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Record::import() reconstructs a record from a decoded JSON line (pipe round-trip)',
   test: function () {
      // # Serialize then import
      $Original = new Record(Levels::Warning, 'chan', 'hello', ['a' => 1]);
      $Original->extra['pid'] = 99;

      $decoded = json_decode(trim((new JSON)->format($Original)), true);
      $Record = Record::import($decoded);

      yield assert(
         assertion: $Record->Level === Levels::Warning,
         description: 'level is restored from its label'
      );

      yield assert(
         assertion: $Record->channel === 'chan' && $Record->message === 'hello',
         description: 'channel and message are restored'
      );

      yield assert(
         assertion: $Record->context['a'] === 1 && $Record->extra['pid'] === 99,
         description: 'context and processor-enriched extra are restored'
      );

      yield assert(
         assertion: abs($Record->timestamp - $Original->timestamp) < 0.001,
         description: 'original timestamp is preserved'
      );

      // # Unknown level falls back to Debug
      $Fallback = Record::import(['level' => 'bogus', 'message' => 'x']);
      yield assert(
         assertion: $Fallback->Level === Levels::Debug,
         description: 'an unknown level label falls back to Debug'
      );
   }
);
