<?php

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatters\JSON;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Record carries process-scoped provenance: stamped at construction, serialized by JSON, restored by import()',
   test: function () {
      $saved = Record::$provenance;

      // # Default provenance
      Record::$provenance = 'framework';
      $Bare = new Record(Levels::Info, 'chan', 'msg');
      yield assert(
         assertion: $Bare->project === 'framework',
         description: 'with no booted project, a new record carries the framework provenance'
      );

      // # Stamped provenance
      Record::$provenance = 'Alpha App';
      $Stamped = new Record(Levels::Info, 'chan', 'msg');
      yield assert(
         assertion: $Stamped->project === 'Alpha App',
         description: 'a new record copies the process provenance at construction'
      );

      // # JSON round-trip
      $decoded = json_decode(trim((new JSON)->format($Stamped)), true);
      yield assert(
         assertion: is_array($decoded) && $decoded['project'] === 'Alpha App',
         description: 'the JSON formatter serializes the project field'
      );

      $Imported = Record::import((array) $decoded);
      yield assert(
         assertion: $Imported->project === 'Alpha App',
         description: 'import() restores the project field from a JSON line'
      );

      // # Legacy lines (no project key) never inherit the current process provenance
      $Legacy = Record::import(['level' => 'info', 'channel' => 'chan', 'message' => 'old']);
      yield assert(
         assertion: $Legacy->project === 'framework',
         description: 'a line written before the field existed imports as framework'
      );

      // # Invalid values fall back to framework
      $Empty = Record::import(['level' => 'info', 'message' => 'x', 'project' => '']);
      $Typed = Record::import(['level' => 'info', 'message' => 'x', 'project' => 42]);
      yield assert(
         assertion: $Empty->project === 'framework' && $Typed->project === 'framework',
         description: 'empty or non-string project values import as framework'
      );

      Record::$provenance = $saved;
   }
);
