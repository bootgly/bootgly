<?php

use Bootgly\ABI\Syntax\Sections;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Sections: the accepted fixture is clean; the rejected one reports order, membership and visibility of Config/Data/Metadata properties',
   test: function () {
      $Sections = new Sections;

      // @ Accepted — asymmetric visibility, readonly, implicit public, virtual hooks, constructor headers, anonymous classes, interfaces, enums
      $found = [];
      foreach ($Sections->analyze(__DIR__ . '/fixtures/accepted.php')->issues as $Issue) {
         $found[] = "{$Issue->line}:{$Issue->type}:{$Issue->symbol}";
      }
      yield assert(
         assertion: $found === [],
         description: 'The accepted fixture must report nothing, got: ' . json_encode($found)
      );

      // @ Rejected
      $found = [];
      foreach ($Sections->analyze(__DIR__ . '/fixtures/rejected.php')->issues as $Issue) {
         $found[] = [$Issue->line, $Issue->type, $Issue->symbol];
      }
      $expected = [
         [8, 'unsectioned_property', '$loose'],
         [13, 'section_order', 'Config'],
         [14, 'config_hidden', '$secret'],
         [17, 'metadata_writable', '$started'],
         [18, 'metadata_writable', '$instances'],
         [19, 'metadata_writable', '$cached'],
         [22, 'metadata_writable', '$written'],
         [28, 'section_order', 'Metadata'],
         [35, 'config_hidden', '$counted'],
         [39, 'unsectioned_property', '$argument'],
      ];
      yield assert(
         assertion: $found === $expected,
         description: 'The rejected fixture must report exactly its section problems, got: ' . json_encode($found)
      );
   }
);
