<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ACI\Tests\Temporaries;


return new Specification(
   description: 'It should reserve owned test temporaries and sweep only orphaned or aged entries',
   test: new Assertions(Case: function (): Generator {
      // @ reserve() — owned, self-describing, fresh (the one real-root touch)
      $reserved = Temporaries::reserve('sweep-probe');

      yield new Assertion(
         description: 'reserve() creates a directory under the owned root, tagged with the caller PID',
      )
         ->expect(
            is_dir($reserved)
            && str_contains($reserved, '/' . Temporaries::ROOT . '/')
            && str_starts_with(basename($reserved), getmypid() . '-sweep-probe-')
         )
         ->to->be(true)
         ->assert();

      $invalid = false;
      try {
         Temporaries::reserve('../escape');
      }
      catch (InvalidArgumentException) {
         $invalid = true;
      }

      yield new Assertion(
         description: 'reserve() rejects names that could escape the owned root',
      )
         ->expect($invalid)
         ->to->be(true)
         ->assert();

      // ! Synthetic temp base — the reaper under test never touches the
      //   machine's real scratch space
      $base = $reserved;
      $root = "{$base}/" . Temporaries::ROOT;
      mkdir($root, 0o700);

      // ! One dead-owner entry (an implausibly-alive PID) with content...
      $orphan = "{$root}/999999999-orphan-deadbeef";
      mkdir($orphan, 0o700);
      file_put_contents("{$orphan}/residue.txt", 'x');
      // ! ...one live-owner entry (this process)...
      $live = "{$root}/" . getmypid() . '-live-cafe';
      mkdir($live, 0o700);
      // ! ...one aged legacy entry and one fresh legacy entry
      $agedLegacy = "{$base}/bootgly-cache-test-aged";
      mkdir($agedLegacy, 0o700);
      file_put_contents("{$agedLegacy}/residue.txt", 'x');
      touch($agedLegacy, time() - Temporaries::LEGACY_TTL - 3_600);
      $freshLegacy = "{$base}/bootgly-cache-test-fresh";
      mkdir($freshLegacy, 0o700);

      $removed = Temporaries::sweep($base);

      yield new Assertion(
         description: 'sweep() reaps the dead-owner entry and the aged legacy entry',
      )
         ->expect($removed === 2 && is_dir($orphan) === false && is_dir($agedLegacy) === false)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'sweep() keeps the live-owner entry and the fresh legacy entry',
      )
         ->expect(is_dir($live) && is_dir($freshLegacy))
         ->to->be(true)
         ->assert();

      // @ Curated legacy list can never match live production surfaces
      //   (bootgly-queues / bootgly-storage are exact names — every listed
      //   prefix must be hyphen-terminated so `bootgly-storage-` never
      //   matches `bootgly-storage`; tls/reload prefixes must never appear)
      $curated = true;
      foreach (Temporaries::LEGACY as $prefix) {
         if (str_ends_with($prefix, '-') === false) {
            $curated = false;
         }
         if ($prefix === 'bootgly-tls-' || $prefix === 'bootgly-reload-') {
            $curated = false;
         }
         if ($prefix === 'bootgly-queues-' || $prefix === 'bootgly-queue') {
            $curated = false;
         }
      }

      yield new Assertion(
         description: 'every legacy prefix is hyphen-terminated and excludes production surfaces',
      )
         ->expect($curated)
         ->to->be(true)
         ->assert();

      // @ Cleanup — the reserved base still holds the survivors; a second
      //   process would reap it later, but leave nothing behind ourselves
      rmdir($live);
      rmdir($freshLegacy);
      rmdir($root);
      rmdir($base);

      yield new Assertion(
         description: 'the reservation is fully releasable by its owner',
      )
         ->expect(is_dir($base) === false)
         ->to->be(true)
         ->assert();
   })
);
