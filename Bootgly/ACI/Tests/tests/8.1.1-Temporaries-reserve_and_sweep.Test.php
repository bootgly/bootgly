<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
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

      // @ The root is never group/world-writable — made under umask 0002, or
      //   found that way — in a process of its own with a fresh temp directory
      $system = "{$reserved}/system";
      mkdir($system, 0o700);
      $script = 'umask(0002); spl_autoload_register(static function (string $class): void { $file = $GLOBALS["argv"][1]'
         . ' . str_replace("\\\\", "/", $class) . ".php"; if (is_file($file)) { require $file; } });'
         . ' Bootgly\\ACI\\Tests\\Temporaries::reserve("made"); $root = sys_get_temp_dir() . "/" . Bootgly\\ACI\\Tests\\Temporaries::ROOT;'
         . ' $made = fileperms($root) & 0777; chmod($root, 0775); clearstatcache();'
         . ' Bootgly\\ACI\\Tests\\Temporaries::reserve("found"); clearstatcache(); echo decoct($made), " ", decoct(fileperms($root) & 0777);';
      $modes = trim((string) shell_exec(
         escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg("sys_temp_dir={$system}") . ' -r ' . escapeshellarg($script)
         . ' ' . escapeshellarg(BOOTGLY_ROOT_DIR) . ' 2>&1'
      ));
      foreach ((array) glob("{$system}/" . Temporaries::ROOT . '/*') as $entry) {
         @rmdir((string) $entry);
      }
      @rmdir("{$system}/" . Temporaries::ROOT);
      @rmdir($system);

      yield new Assertion(
         description: "reserve() keeps its root 0755 — made under umask 0002 and found group-writable ({$modes})",
      )
         ->expect($modes)
         ->to->be('755 755')
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
