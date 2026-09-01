<?php

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatters\JSON;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Record carries the process instance qualifier: stamped at construction, serialized by JSON, restored by import()',
   test: function () {
      $savedProvenance = Record::$provenance;
      $savedQualifier = isset(Record::$qualifier) ? Record::$qualifier : null;
      Record::$provenance = 'framework';

      try {
         // # A process that claimed no instance builds unstamped records
         Record::$qualifier = '';
         $Bare = new Record(Levels::Info, 'chan', 'msg');
         yield assert(
            assertion: $Bare->instance === '',
            description: 'with no claimed instance, a new record carries an empty instance'
         );

         // # Stamped instance
         Record::$qualifier = '8443';
         $Stamped = new Record(Levels::Info, 'chan', 'msg', ['k' => 'v']);
         yield assert(
            assertion: $Stamped->instance === '8443',
            description: 'a new record copies the process qualifier at construction'
         );

         // # JSON: the key is emitted in a fixed position (the de-dup contract is byte-exact)
         $line = (new JSON)->format($Stamped);
         $decoded = json_decode(trim($line), true);
         yield assert(
            assertion: is_array($decoded)
               && $decoded['instance'] === '8443'
               && array_keys($decoded) === ['timestamp', 'level', 'project', 'instance', 'channel', 'message', 'context', 'extra'],
            description: 'the JSON formatter serializes the instance right after the project'
         );

         // # The qualifier is serialized verbatim — never canonicalized
         Record::$qualifier = '08080';
         yield assert(
            assertion: str_contains((new JSON)->format(new Record(Levels::Info, 'chan', 'msg')), '"instance":"08080"'),
            description: 'a leading zero survives serialization byte-exact'
         );

         // # import() restores the line's instance — never the current process static
         Record::$qualifier = '9999';
         $Imported = Record::import((array) $decoded);
         yield assert(
            assertion: $Imported->instance === '8443' && (new JSON)->format($Imported) === $line,
            description: 'import() restores the instance byte-exact (a stamped process never leaks onto imported lines)'
         );

         // # Legacy lines (no instance key) import as unstamped
         $Legacy = Record::import(['level' => 'info', 'channel' => 'chan', 'message' => 'old']);
         yield assert(
            assertion: $Legacy->instance === '',
            description: 'a line written before the field existed imports with an empty instance'
         );

         // # Non-string values import as unstamped (one rule, byte parity)
         $Typed = Record::import(['level' => 'info', 'message' => 'x', 'instance' => 8443]);
         $Null = Record::import(['level' => 'info', 'message' => 'x', 'instance' => null]);
         $Empty = Record::import(['level' => 'info', 'message' => 'x', 'instance' => '']);
         yield assert(
            assertion: $Typed->instance === '' && $Null->instance === '' && $Empty->instance === '',
            description: 'non-string or empty instance values import as unstamped'
         );

         // # The key is always emitted, even unstamped (stable line shape)
         Record::$qualifier = '';
         $unstamped = json_decode(trim((new JSON)->format(new Record(Levels::Info, 'chan', 'msg'))), true);
         yield assert(
            assertion: is_array($unstamped) && array_key_exists('instance', $unstamped) && $unstamped['instance'] === '',
            description: 'an unstamped record still serializes the instance key (empty)'
         );

         // # Each record captures the qualifier current at ITS construction
         Record::$qualifier = '1';
         $First = new Record(Levels::Info, 'chan', 'msg');
         Record::$qualifier = '';
         $Second = new Record(Levels::Info, 'chan', 'msg');
         yield assert(
            assertion: $First->instance === '1' && $Second->instance === '',
            description: 'a record built under a qualifier keeps it after the static changes'
         );
      }
      finally {
         Record::$provenance = $savedProvenance;
         if ($savedQualifier !== null) {
            Record::$qualifier = $savedQualifier;
         }
      }
   }
);
