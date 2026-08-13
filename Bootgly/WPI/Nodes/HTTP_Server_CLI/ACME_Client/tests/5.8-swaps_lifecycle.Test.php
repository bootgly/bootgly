<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\CertificateSnapshot;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Swaps;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;


/**
 * Regressions for three gaps in the namespace-lease remediation.
 *
 * A) An explicit release is terminal for the `Swaps` OBJECT — deliberate, and
 *    pinned by 5.3. It must not be terminal for its OWNER: an `AutoTLS` whose
 *    lease was retired (startup abort, `secure: null`, a rejected candidate)
 *    is handed back by supported lifecycles and has to publish again.
 *
 * B) Legacy collection renames `<32hex>` to `.legacy-<32hex>-<16hex>` before
 *    removing it. An interrupted removal must stay collectable: nothing else
 *    in the base carries that name, so a collector that ignores it converts a
 *    resumable in-place removal into a permanent orphan.
 *
 * C) Tombstone discovery must not depend on glob syntax. `AutoTLS` accepts any
 *    absolute two-level path, glob metacharacters included, and a blind lookup
 *    does not merely skip work — it reports "no tombstone", which lets the
 *    recovery path discard a lease whose tombstone is still standing.
 */

return new Test(
   description: 'ACME Swaps: a retired owner republishes, and collection survives interruption and glob-unsafe paths',
   test: function () {
      $generation = str_repeat('a', 32);
      $Snapshot = new CertificateSnapshot(
         $generation,
         '/tmp/certificate.pem',
         '/tmp/key.pem',
         str_repeat('b', 64),
         str_repeat('c', 64),
         time() - 1,
         time() + 3600,
         false,
         ['localhost']
      );

      $root = sys_get_temp_dir() . '/bootgly-swaps-lifecycle-' . getmypid();
      $erase = static function (string $directory): void {
         if (is_dir($directory) === false) {
            return;
         }
         $Entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
         );
         foreach ($Entries as $Entry) {
            $Entry->isDir() && $Entry->isLink() === false
               ? @rmdir($Entry->getPathname())
               : @unlink($Entry->getPathname());
         }
         @rmdir($directory);
      };

      try {
         // @@ A) A retired lease must not brick its owner
         $AutoTLS = new AutoTLS(
            domains: ['localhost'],
            email: 'admin@example.com',
            path: "{$root}/a/"
         );
         $first = $AutoTLS->Swaps->request($Snapshot);
         $Retired = $AutoTLS->Swaps;
         $instance = $Retired->instance;

         // The exact retirement `abort()`, `stop()` and `configure(secure: null)` perform.
         $AutoTLS->retire();

         // Both halves are pinned here on purpose. Making `release()` itself
         // re-acquirable would satisfy the republish assertion below while
         // breaking the invariant 5.3 pins: a stale reference must never be
         // able to resurrect a namespace already declared collectable.
         $terminal = $Retired->request($Snapshot) === null && $Retired->fetch() === null;

         $second = $AutoTLS->Swaps->request($Snapshot);
         $recovered = $AutoTLS->Swaps->fetch();

         yield assert(
            assertion: $terminal,
            description: 'the retired Swaps object stays terminal — observed: ' . var_export($terminal, true)
         );

         yield assert(
            assertion: is_string($first)
               && is_string($second)
               && $AutoTLS->Swaps !== $Retired
               && is_array($recovered)
               && ($recovered['attempt'] ?? null) === $second,
            description: 'a retired Auto-TLS owner is replaced rather than latched — republished: '
               . var_export(is_string($second), true)
               . ', replaced: ' . var_export($AutoTLS->Swaps !== $Retired, true)
               . ', fetch: ' . var_export(is_array($recovered), true)
         );

         yield assert(
            assertion: $AutoTLS->Swaps->instance === $instance,
            description: 'the replacement keeps the fork-inherited identity — observed: '
               . $AutoTLS->Swaps->instance
         );

         // @@ B) An interrupted legacy collection stays collectable
         $base = "{$root}/b/swaps/";
         $Owner = new Swaps($base, '1111111111111111');
         $Owner->request($Snapshot);

         // The exact state an interrupted `reap()` leaves behind: renamed, not removed.
         $stranded = "{$base}.legacy-" . str_repeat('d', 32) . '-' . str_repeat('e', 16);
         mkdir("{$stranded}/generation", 0700, true);
         file_put_contents("{$stranded}/generation/leftover.pem", 'residue');
         $planted = is_dir($stranded);

         $Later = new Swaps($base, '2222222222222222');
         $Later->request($Snapshot);

         yield assert(
            assertion: $planted && is_dir($stranded) === false,
            description: 'an interrupted legacy removal is resumed by the next collection — planted: '
               . var_export($planted, true) . ', still present: ' . var_export(is_dir($stranded), true)
         );

         // @@ C) Collection must not depend on glob syntax
         $bracket = "{$root}/c[1]/swaps/";
         $Retired = new Swaps($bracket, '3333333333333333');
         $Retired->request($Snapshot);
         $Retired->release();

         // Interrupt the collection exactly where `purge()` can fail: the tree is
         // already a bound tombstone, its lease still names the locked inode.
         $tombstone = "{$bracket}.gc-3333333333333333-" . str_repeat('f', 16);
         rename("{$bracket}3333333333333333", $tombstone);
         $staged = is_dir($tombstone) && is_file("{$bracket}3333333333333333.owner.lock");

         $Collector = new Swaps($bracket, '4444444444444444');
         $Collector->request($Snapshot);

         $tombstoneCollected = is_dir($tombstone) === false;
         $leaseRetained = is_file("{$bracket}3333333333333333.owner.lock");

         yield assert(
            assertion: $staged && $tombstoneCollected,
            description: 'a bound tombstone under a glob-unsafe base is collected — staged: '
               . var_export($staged, true) . ', collected: ' . var_export($tombstoneCollected, true)
         );

         yield assert(
            assertion: $tombstoneCollected || $leaseRetained,
            description: 'a lease is never discarded while its tombstone still stands — tombstone: '
               . var_export(is_dir($tombstone), true) . ', lease: ' . var_export($leaseRetained, true)
         );
      }
      finally {
         $erase($root);
      }
   }
);
