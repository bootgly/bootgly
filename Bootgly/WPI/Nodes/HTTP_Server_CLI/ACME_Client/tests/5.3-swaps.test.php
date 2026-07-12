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

      try {
         $Swaps = new Swaps($path);
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
               "{$path}{$generation}/{$replacement}/103.json",
               "{$path}{$generation}/{$replacement}/999.json"
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

         $mode = fileperms("{$path}request.json") & 0777;
         yield assert(
            assertion: $mode === 0600,
            description: 'generation rendezvous files are private at creation'
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
