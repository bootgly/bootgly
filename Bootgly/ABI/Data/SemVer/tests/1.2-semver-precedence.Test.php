<?php

namespace Bootgly\ABI\Data\SemVer\Tests;


use function assert;
use function count;
use function usort;

use Bootgly\ABI\Data\SemVer;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `SemVer::compare()` — SemVer §11 precedence, including the two orderings
 * a naive sort gets wrong: a stable above its own pre-releases and numeric
 * identifiers by value.
 */

return new Test(
   description: 'SemVer::compare() orders by SemVer §11 precedence',
   test: function () {
      // ! The specification's own chain, lowest first
      $chain = [
         '1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-alpha.beta', '1.0.0-beta', '1.0.0-beta.2',
         '1.0.0-beta.11', '1.0.0-rc.1', '1.0.0',
      ];
      $ordered = true;
      for ($index = 1, $count = count($chain); $index < $count; $index++) {
         $Lower = new SemVer($chain[$index - 1]);
         $Higher = new SemVer($chain[$index]);

         if ($Lower->compare($Higher) !== -1 || $Higher->compare($Lower) !== 1) {
            $ordered = false;

            break;
         }
      }

      yield assert(
         assertion: $ordered,
         description: 'the §11 example chain orders strictly, in both directions'
      );

      // # The numbers
      yield assert(
         assertion: new SemVer('1.0.0')->compare(new SemVer('2.0.0')) === -1
            && new SemVer('2.1.0')->compare(new SemVer('2.0.9')) === 1
            && new SemVer('2.1.1')->compare(new SemVer('2.1.0')) === 1
            && new SemVer('10.0.0')->compare(new SemVer('9.9.9')) === 1,
         description: 'major, then minor, then patch — numerically, not as text'
      );

      // # A stable outranks only ITS pre-releases
      yield assert(
         assertion: new SemVer('1.0.0')->compare(new SemVer('1.0.0-rc.1')) === 1
            && new SemVer('1.1.0-beta.1')->compare(new SemVer('1.0.0')) === 1,
         description: 'a stable beats its own pre-releases and loses to the next line\'s pre-release'
      );

      // # Pre-release identifiers
      yield assert(
         assertion: new SemVer('1.0.0-beta.10')->compare(new SemVer('1.0.0-beta.9')) === 1,
         description: 'numeric identifiers compare by value (beta.10 > beta.9)'
      );
      yield assert(
         assertion: new SemVer('1.0.0-1')->compare(new SemVer('1.0.0-a')) === -1
            && new SemVer('1.0.0-alpha.1')->compare(new SemVer('1.0.0-alpha.beta')) === -1,
         description: 'a numeric identifier always ranks below an alphanumeric one'
      );
      yield assert(
         assertion: new SemVer('1.0.0-beta')->compare(new SemVer('1.0.0-alpha')) === 1
            && new SemVer('1.0.0-B')->compare(new SemVer('1.0.0-a')) === -1,
         description: 'alphanumeric identifiers compare in ASCII order (uppercase first)'
      );
      yield assert(
         assertion: new SemVer('1.0.0-alpha')->compare(new SemVer('1.0.0-alpha.1')) === -1,
         description: 'the longer identifier list ranks higher when the shared prefix is equal'
      );

      // # Build metadata is not precedence
      yield assert(
         assertion: new SemVer('1.0.0+build.1')->compare(new SemVer('1.0.0+build.2')) === 0
            && new SemVer('1.0.0-beta.6+a')->compare(new SemVer('v1.0.0-beta.6')) === 0,
         description: 'build metadata (and the `v`) never changes precedence'
      );

      // # A sort by compare() is a SemVer sort
      $tags = ['v1.0.0-beta.9', 'v1.0.0', 'v1.0.0-beta.10', 'v1.1.0-beta.1', 'v1.0.0-rc.1', 'v0.24.0-beta'];
      $SemVers = [];
      foreach ($tags as $tag) {
         $SemVers[$tag] = new SemVer($tag);
      }
      usort($tags, static fn (string $a, string $b): int => $SemVers[$b]->compare($SemVers[$a]));

      yield assert(
         assertion: $tags === ['v1.1.0-beta.1', 'v1.0.0', 'v1.0.0-rc.1', 'v1.0.0-beta.10', 'v1.0.0-beta.9', 'v0.24.0-beta'],
         description: 'newest first: 1.1.0-beta.1 > 1.0.0 > 1.0.0-rc.1 > 1.0.0-beta.10 > 1.0.0-beta.9 > 0.24.0-beta'
      );
   }
);
