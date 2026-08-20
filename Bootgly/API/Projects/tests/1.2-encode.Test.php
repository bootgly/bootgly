<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
   description: 'Projects::encode() creates injective filesystem-safe project identities',
   test: function () {
      yield assert(
         assertion: Projects::encode('Demo/HTTP_Server_CLI') === 'Demo~HTTP_Server_CLI',
         description: 'slash becomes tilde'
      );
      yield assert(
         assertion: Projects::encode('Benchmark') === 'Benchmark',
         description: 'a flat path is unchanged'
      );
      yield assert(
         assertion: Projects::encode('Demo/Queue-HTTP_Server_CLI') === 'Demo~Queue-HTTP_Server_CLI',
         description: 'the encoded id carries no path separator'
      );

      // ! Two distinct paths sharing a leaf must encode to distinct pid/lock ids
      yield assert(
         assertion: Projects::encode('Demo/HTTP_Server_CLI') !== Projects::encode('Other/HTTP_Server_CLI'),
         description: 'shared-leaf paths encode to distinct ids (no pid collision)'
      );

      // ! Reserved state-name syntax must remain data when it came from a
      //   manually registered project path.
      yield assert(
         assertion: Projects::encode('A/B') === 'A~B'
            && Projects::encode('A~B') === 'A%7EB'
            && Projects::encode('A%7EB') === 'A%257EB',
         description: 'slash, literal tilde and percent escape remain injective'
      );
      yield assert(
         assertion: Projects::encode('A.B') === 'A%2EB'
            && Projects::encode('A*B') === 'A%2AB'
            && Projects::encode('A?B') === 'A%3FB',
         description: 'instance and glob syntax cannot enter a state filename'
      );

      $paths = [
         'A/B',
         'A~B',
         'A%7EB',
         'A.B',
         'A*B',
         'A?B',
         'A[B]',
         'Näme/Ç',
      ];
      $encoded = array_map(Projects::encode(...), $paths);
      yield assert(
         assertion: count($encoded) === count(array_unique($encoded)),
         description: 'adversarial path corpus has no encoded collisions'
      );
   }
);
