<?php

namespace Bootgly\API\Environment;


use const BOOTGLY_ROOT_BASE;
use const BOOTGLY_VERSION;
use function assert;
use function preg_match;
use function shell_exec;
use function strlen;
use function substr;
use function trim;

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should identify the running build by version and commit',
   test: function () {
      // @ A build without a commit degrades to the version alone
      $Unknown = new Build('1.0.0');

      yield assert(
         assertion: $Unknown->commit === null && $Unknown->source === null
            && $Unknown->abbreviation === null
            && $Unknown->identify() === 'v1.0.0',
         description: 'An unidentifiable source reports the version alone'
      );

      // @ A build with a commit abbreviates it to git's short-hash width
      $Known = new Build('1.0.0', 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678', 'git');

      yield assert(
         assertion: $Known->abbreviation === 'a1b2c3d4'
            && strlen((string) $Known->abbreviation) === Build::ABBREVIATION
            && $Known->identify() === 'v1.0.0 (a1b2c3d4)',
         description: 'A known commit is abbreviated into the identity line'
      );

      // @ The abbreviation always mirrors the commit — it is derived, never stored
      $Other = new Build('2.0.0', 'ffffffffffffffffffffffffffffffffffffffff', 'composer');

      yield assert(
         assertion: $Other->abbreviation === substr((string) $Other->commit, 0, Build::ABBREVIATION)
            && $Other->identify() === 'v2.0.0 (ffffffff)',
         description: 'The abbreviation derives from the commit it belongs to'
      );

      // ---

      // @ Detecting the running framework — this checkout is a git working
      //   tree, so the commit must match what git itself reports
      $Build = Build::detect();
      $HEAD = trim((string) shell_exec('git -C ' . BOOTGLY_ROOT_BASE . ' rev-parse HEAD 2>/dev/null'));

      yield assert(
         assertion: $Build->version === BOOTGLY_VERSION,
         description: 'The detected build carries the framework version'
      );

      yield assert(
         assertion: preg_match('#^[0-9a-f]{40}$#', $HEAD) === 1
            && $Build->commit === $HEAD
            && $Build->source === 'git',
         description: 'The detected commit matches the git working tree HEAD'
      );

      yield assert(
         assertion: $Build->identify() === "v{$Build->version} ({$Build->abbreviation})"
            && $Build->abbreviation === substr($HEAD, 0, Build::ABBREVIATION),
         description: 'The identity line pairs the version with the short commit'
      );
   }
);
