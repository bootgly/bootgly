<?php

use Bootgly\ABI\Syntax\Casing;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Casing: the accepted fixture is clean; the rejected one reports lowercase objects and miscased acronyms where the tokens make them knowable',
   test: function () {
      $Casing = new Casing;

      // @ Accepted — scalars, plural (enum) types, `object`, mixed unions, chained `new`, clones, untyped parameters
      $found = [];
      foreach ($Casing->analyze(__DIR__ . '/fixtures/accepted.php')->issues as $Issue) {
         $found[] = "{$Issue->line}:{$Issue->symbol}";
      }
      yield assert(
         assertion: $found === [],
         description: 'The accepted fixture must report nothing, got: ' . json_encode($found)
      );

      // @ Rejected — properties, documented collections, parameters (catch included), `new` assignments, acronyms
      $found = [];
      foreach ($Casing->analyze(__DIR__ . '/fixtures/rejected.php')->issues as $Issue) {
         $found[] = [$Issue->line, $Issue->type, $Issue->symbol, $Issue->kind];
      }
      $expected = [
         [14, 'acronym_case', '$url', 'property'],
         [15, 'object_case', '$clock', 'property'],
         [16, 'object_case', '$date', 'property'],
         [18, 'object_case', '$dates', 'property'],
         [20, 'object_case', '$moments', 'property'],
         [21, 'acronym_case', '$Json', 'property'],
         [27, 'object_case', '$callback', 'parameter'],
         [27, 'acronym_case', '$html', 'parameter'],
         [27, 'object_case', '$stamps', 'parameter'],
         [27, 'object_case', '$parent', 'parameter'],
         [27, 'acronym_case', '$uris', 'parameter'],
         [29, 'object_case', '$date', 'variable'],
         [30, 'object_case', '$other', 'variable'],
         [31, 'acronym_case', '$sql', 'variable'],
         [37, 'object_case', '$caught', 'parameter'],
         [43, 'acronym_case', '$https', 'variable'],
         [48, 'object_case', '$moment', 'parameter'],
         [57, 'object_case', '$when', 'parameter'],
      ];
      yield assert(
         assertion: $found === $expected,
         description: 'The rejected fixture must report exactly its names (a variable once per file), got: ' . json_encode($found)
      );

      // @ The message names the fix
      $messages = [];
      foreach ($Casing->analyze(__DIR__ . '/fixtures/rejected.php')->issues as $Issue) {
         $messages[$Issue->symbol] = $Issue->message;
      }
      yield assert(
         assertion: str_ends_with($messages['$uris'], '→ $URIs') && str_ends_with($messages['$Json'], '→ $JSON')
            && str_ends_with($messages['$callback'], '→ $Callback')
            && str_ends_with($messages['$https'], '→ $HTTPS'),
         description: 'Each message must name the corrected spelling, got: ' . json_encode($messages)
      );
   }
);
