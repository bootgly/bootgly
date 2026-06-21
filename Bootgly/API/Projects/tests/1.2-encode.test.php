<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Projects;


return new Specification(
   description: 'Projects::encode() maps "/" to "~" so nested leaves never collide',
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
   }
);
