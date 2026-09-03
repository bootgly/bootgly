<?php

namespace Bootgly\ABI\Data\SemVer\Tests;

use function assert;
use ValueError;

use Bootgly\ABI\Data\SemVer;
use Bootgly\ACI\Tests\Suite\Test;

/**
 * `SemVer` parsing — the SemVer 2.0.0 grammar, the tag-style `v` prefix,
 * the parts a parsed value exposes and the canonical string form.
 */

return new Test(
   description: 'SemVer parses the SemVer 2.0.0 grammar (with a `v` prefix) and rejects everything else',
   test: function () {
      // # A release
      $SemVer = new SemVer('1.0.0');

      yield assert(
         assertion: $SemVer->major === 1 && $SemVer->minor === 0 && $SemVer->patch === 0
            && $SemVer->prerelease === [] && $SemVer->build === '' && $SemVer->stable === true,
         description: 'a bare release carries its three numbers, no labels and is stable'
      );

      // # A pre-release with build metadata, tag-style
      $SemVer = new SemVer('v1.0.0-beta.6+sha.8df529d');

      yield assert(
         assertion: $SemVer->major === 1 && $SemVer->prerelease === ['beta', '6']
            && $SemVer->build === 'sha.8df529d' && $SemVer->stable === false,
         description: 'a `v`-prefixed pre-release splits its identifiers and keeps its build metadata'
      );
      yield assert(
         assertion: (string) $SemVer === '1.0.0-beta.6+sha.8df529d',
         description: 'the canonical string drops the `v` and keeps everything else'
      );

      // # Every valid shape the specification lists
      $valid = [
         '0.0.4', '1.2.3', '10.20.30', '1.1.2-prerelease+meta', '1.1.2+meta', '1.1.2+meta-valid',
         '1.0.0-alpha', '1.0.0-beta', '1.0.0-alpha.beta', '1.0.0-alpha.1', '1.0.0-alpha0.valid',
         '1.0.0-alpha.0valid', '1.0.0-alpha-a.b-c-somethinglong+build.1-aef.1-its-okay',
         '1.0.0-rc.1+build.1', '2.0.0-rc.1+build.123', '1.2.3-beta', '10.2.3-DEV-SNAPSHOT',
         '1.2.3-SNAPSHOT-123', '1.0.0', '2.0.0', '1.1.7', '2.0.0+build.1848', '2.0.1-alpha.1227',
         '1.0.0-alpha+beta', '1.2.3----RC-SNAPSHOT.12.9.1--.12+788', '1.2.3----R-S.12.9.1--.12+meta',
         '1.2.3----RC-SNAPSHOT.12.9.1--.12', '1.0.0+0.build.1-rc.10000aaa-kk-0.1',
         '999999999999999999.999999999999999999.999999999999999999', '1.0.0-0A.is.legal',
         'v1.0.0', 'v0.24.0-beta',
      ];
      $accepted = true;
      foreach ($valid as $candidate) {
         if (SemVer::parse($candidate) === null) {
            $accepted = false;

            break;
         }
      }

      yield assert(
         assertion: $accepted,
         description: 'every valid example of the specification parses'
      );

      // # Every invalid shape the specification lists
      $invalid = [
         '1', '1.2', '1.2.3-0123', '1.2.3-0123.0123', '1.1.2+.123', '+invalid', '-invalid',
         '-invalid+invalid', '-invalid.01', 'alpha', 'alpha.beta', 'alpha.beta.1', 'alpha.1',
         'alpha+beta', 'alpha_beta', 'alpha.', 'alpha..', 'beta', '1.0.0-alpha_beta', '-alpha.',
         '1.0.0-alpha..', '1.0.0-alpha..1', '1.0.0-alpha...1', '01.1.1', '1.01.1', '1.1.01',
         '1.2', '1.2.3.DEV', '1.2-SNAPSHOT', '1.2.31.2.3----RC-SNAPSHOT.12.09.1--..12+788',
         '1.2-RC-SNAPSHOT', '-1.0.3-gamma+b7718', '+justmeta', '9.8.7+meta+meta', '9.8.7-whatever+meta+meta',
         '', 'v', 'V1.0.0', ' 1.0.0', "1.0.0\n", 'main', 'HEAD', '1badbde',
      ];
      $rejected = true;
      foreach ($invalid as $candidate) {
         if (SemVer::parse($candidate) !== null) {
            $rejected = false;

            break;
         }
      }

      yield assert(
         assertion: $rejected,
         description: 'every invalid example of the specification is refused — a trailing newline included'
      );

      // # A `git describe` suffix IS a pre-release by the grammar
      //   `beta.6-1-g1badbde` is two identifiers, the second alphanumeric with
      //   hyphens — legal SemVer. Whoever lists tags must list refs, never the
      //   output of `describe`, or a kit one commit past a release would rank
      //   as a release of its own.
      $Described = SemVer::parse('v1.0.0-beta.6-1-g1badbde');

      yield assert(
         assertion: $Described !== null && $Described->prerelease === ['beta', '6-1-g1badbde'],
         description: 'a describe-style suffix parses as a pre-release identifier — by design of the grammar'
      );

      // # A number no PHP integer holds exactly is refused, not saturated
      yield assert(
         assertion: SemVer::parse('99999999999999999999999.0.0') === null
            && SemVer::parse('9999999999999999999.0.0') === null
            && SemVer::parse('999999999999999999.0.0')?->major === 999999999999999999,
         description: 'a number beyond 18 digits is refused (it would saturate and compare equal to another); 18 digits parse exactly'
      );

      // # The constructor throws where `parse()` returns null
      $thrown = false;
      try {
         new SemVer('1.0');
      }
      catch (ValueError) {
         $thrown = true;
      }

      yield assert(
         assertion: $thrown === true,
         description: 'the constructor raises a ValueError on an invalid string'
      );
   }
);
