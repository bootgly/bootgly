<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\CertificateSnapshot;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Swaps;

return new Specification(
   description: 'ACME Swaps: atomic generation request, worker acknowledgements and applied marker',
   test: function () {
      $path = sys_get_temp_dir() . '/bootgly-acme-swaps-' . getmypid() . '/';
      $generation = str_repeat('a', 32);
      $certificateHash = str_repeat('b', 64);
      $keyHash = str_repeat('c', 64);
      $Snapshot = new CertificateSnapshot(
         $generation,
         '/tmp/certificate.pem',
         '/tmp/key.pem',
         $certificateHash,
         $keyHash,
         time() - 1,
         time() + 3600,
         false,
         ['localhost']
      );

      $instance = 'aaaaaaaaaaaaaaaa';

      try {
         $Swaps = new Swaps($path, $instance);
         $requested = $Swaps->request($Snapshot);
         $desired = $Swaps->fetch();
         yield assert(
            assertion: is_string($requested)
               && $desired !== null
               && $desired['attempt'] === $requested
               && $desired['generation'] === $generation
               && $desired['certificateHash'] === $certificateHash
               && $desired['keyHash'] === $keyHash
               && is_int($desired['requested']),
            description: 'the desired generation and hashes round-trip through one atomic record'
         );

         yield assert(
            assertion: is_string($requested)
               && $Swaps->acknowledge($requested, $generation, 101, true, $certificateHash, $keyHash)
               && $Swaps->acknowledge($requested, $generation, 102, false, $certificateHash, $keyHash, 'rejected')
               && count($Swaps->collect($generation, $requested)) === 2,
            description: 'worker acknowledgements stay keyed by PID within the exact generation and attempt'
         );
         yield assert(
            assertion: $Swaps->acknowledge('invalid', 'invalid', 0, true, 'bad', null) === false,
            description: 'malformed attempts, generations, PIDs and hashes are rejected before persistence'
         );

         $replacement = $Swaps->request($Snapshot);
         yield assert(
            assertion: is_string($requested)
               && is_string($replacement)
               && $replacement !== $requested
               && $Swaps->collect($generation, $replacement) === [],
            description: 'a second request for the same generation cannot consume acknowledgements from an older attempt'
         );

         if (is_string($replacement)) {
            $Swaps->acknowledge($replacement, $generation, 103, true, $certificateHash, $keyHash);
            rename(
               "{$path}{$instance}/{$generation}/{$replacement}/103.json",
               "{$path}{$instance}/{$generation}/{$replacement}/999.json"
            );
         }
         yield assert(
            assertion: is_string($replacement)
               && $Swaps->collect($generation, $replacement) === [],
            description: 'an acknowledgement filename must match its embedded PID'
         );

         yield assert(
            assertion: is_string($replacement)
               && $Swaps->complete($Snapshot, $replacement)
               && $Swaps->resolve() === $generation,
            description: 'the applied marker advances only for the current generation attempt'
         );

         $mode = fileperms("{$path}{$instance}/request.json") & 0777;
         yield assert(
            assertion: $mode === 0600,
            description: 'generation rendezvous files are private at creation'
         );

         // @ Two servers on ONE storage base (H2) — the rendezvous is
         //   namespaced per instance, so a second server can never clobber
         //   the first one's desired attempt, ACKs or applied marker,
         //   whether it serves the SAME identity or a different one
         $Other = new Swaps($path, 'bbbbbbbbbbbbbbbb');

         yield assert(
            assertion: $Other->fetch() === null && $Other->resolve() === null,
            description: 'a second server instance starts with an empty rendezvous despite the shared base'
         );

         $foreign = new CertificateSnapshot(
            str_repeat('d', 32),
            '/tmp/certificate.pem',
            '/tmp/key.pem',
            str_repeat('e', 64),
            str_repeat('f', 64),
            time() - 1,
            time() + 3600,
            false,
            ['other.localhost']
         );
         $concurrent = $Other->request($foreign);
         $desired = $Swaps->fetch();

         yield assert(
            assertion: is_string($concurrent)
               && $desired !== null
               && $desired['attempt'] === $replacement
               && $desired['generation'] === $generation
               && $Swaps->resolve() === $generation
               && $Other->collect($generation, (string) $replacement) === [],
            description: 'a concurrent publication by another instance never clobbers this instance attempt, applied marker or ACKs'
         );

         // @ A certifier/bootstrap publisher is intentionally short-lived.
         //   Its PID must never become a lease for the fork-inherited server
         //   namespace: another instance publishing after that child exits
         //   must leave the first server's request untouched.
         $publisher = pcntl_fork();
         if ($publisher === 0) {
            exit($Swaps->request($Snapshot) === null ? 1 : 0);
         }
         pcntl_waitpid($publisher, $status);
         $publishedByChild = $Swaps->fetch();

         $Third = new Swaps($path, 'cccccccccccccccc');
         $thirdAttempt = $Third->request($foreign);
         $afterForeignPublication = $Swaps->fetch();

         yield assert(
            assertion: pcntl_wifexited($status)
               && pcntl_wexitstatus($status) === 0
               && $publishedByChild !== null
               && is_string($thirdAttempt)
               && $afterForeignPublication === $publishedByChild
               && is_dir("{$path}{$instance}"),
            description: 'a forked publisher exit cannot make its live server namespace reapable by another instance'
         );

         // @ Upgrade cleanup is deliberately limited to the old base-level
         //   layout; it never traverses a 16-hex live instance namespace.
         $legacyGeneration = "{$path}" . str_repeat('9', 32) . '/';
         mkdir($legacyGeneration, 0700, true);
         file_put_contents("{$legacyGeneration}101.json", '{}');
         file_put_contents("{$path}request.json", '{}');
         file_put_contents("{$path}applied.json", '{}');

         $Swaps->request($Snapshot);

         yield assert(
            assertion: is_dir($legacyGeneration) === false
               && is_file("{$path}request.json") === false
               && is_file("{$path}applied.json") === false
               && is_dir("{$path}bbbbbbbbbbbbbbbb")
               && is_dir("{$path}cccccccccccccccc"),
            description: 'publication cleans only legacy base artifacts and preserves every sibling instance'
         );
      }
      finally {
         if (is_dir($path)) {
            $Iterator = new RecursiveIteratorIterator(
               new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
               RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($Iterator as $Entry) {
               $Entry->isDir()
                  ? @rmdir($Entry->getPathname())
                  : @unlink($Entry->getPathname());
            }
            @rmdir($path);
         }
      }
   }
);
