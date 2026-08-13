<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\CertificateSnapshot;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Swaps;

return new Test(
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
         yield assert(
            assertion: is_dir($path) === false
               && is_file("{$path}{$instance}.owner.lock") === false
               && is_dir("{$path}{$instance}") === false
               && $Swaps->fetch() === null
               && is_dir($path) === false,
            description: 'construction and a read-only miss perform no filesystem mutation before publication'
         );

         $Unclaimed = new Swaps($path, '1212121212121212');
         $unclaimedCloneRejected = false;
         try {
            $Clone = clone $Unclaimed;
         }
         catch (Error) {
            $unclaimedCloneRejected = true;
         }
         $Unclaimed->release();
         yield assert(
            assertion: $unclaimedCloneRejected
               && $Unclaimed->request($Snapshot) === null
               && $Unclaimed->fetch() === null
               && is_file("{$path}1212121212121212.owner.lock") === false
               && is_dir("{$path}1212121212121212") === false,
            description: 'an unclaimed owner cannot be cloned and an explicit release is terminal without side effects'
         );

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

         $lease = "{$path}{$instance}.owner.lock";
         $LeaseProbe = fopen($lease, 'r+b');
         $leaseBlocked = is_resource($LeaseProbe)
            && flock($LeaseProbe, LOCK_EX | LOCK_NB) === false;
         is_resource($LeaseProbe) && fclose($LeaseProbe);
         yield assert(
            assertion: (fileperms($lease) & 0777) === 0600 && $leaseBlocked,
            description: 'the private owner lease remains locked while its server instance is live'
         );

         $cloneRejected = false;
         try {
            $Clone = clone $Swaps;
         }
         catch (Error) {
            $cloneRejected = true;
         }
         yield assert(
            assertion: $cloneRejected && $Swaps->fetch() !== null,
            description: 'a Swaps owner cannot be cloned into an alias that could close the live lease'
         );

         // @ Workers can be forked before a privileged master is allowed to
         //   publish. They must join the creator's committed lease without
         //   creating a competing inode and retain it independently.
         $lazyInstance = '1313131313131313';
         $Lazy = new Swaps($path, $lazyInstance);
         $Joiners = [];
         $JoinSockets = [];
         for ($index = 0; $index < 3; $index++) {
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($Pair === false) {
               throw new RuntimeException('Could not create the lazy-join control socket.');
            }
            $PID = pcntl_fork();
            if ($PID < 0) {
               throw new RuntimeException('Could not fork a lazy-join worker.');
            }
            if ($PID === 0) {
               fclose($Pair[0]);
               $deadline = microtime(true) + 3.0;
               do {
                  $record = $Lazy->fetch();
                  if ($record !== null) {
                     break;
                  }
                  usleep(1000);
               } while (microtime(true) < $deadline);
               $joined = $record !== null
                  && $Lazy->acknowledge(
                     $record['attempt'],
                     $record['generation'],
                     getmypid(),
                     true,
                     $record['certificateHash'],
                     $record['keyHash']
                  );
               fwrite($Pair[1], $joined ? '1' : '0');
               stream_set_blocking($Pair[1], false);
               $holdDeadline = microtime(true) + 5.0;
               do {
                  $command = fread($Pair[1], 1);
                  if ($command === 'q') {
                     break;
                  }
                  usleep(1000);
               } while (microtime(true) < $holdDeadline);
               fclose($Pair[1]);
               exit($joined && $command === 'q' ? 0 : 1);
            }
            fclose($Pair[1]);
            $Joiners[] = $PID;
            $JoinSockets[] = $Pair[0];
         }
         $lazyAttempt = $Lazy->request($Snapshot);
         $joined = [];
         foreach ($JoinSockets as $JoinSocket) {
            stream_set_timeout($JoinSocket, 4);
            $joined[] = fread($JoinSocket, 1) === '1';
         }
         $joinedACKs = is_string($lazyAttempt)
            ? $Lazy->collect($Snapshot->generation, $lazyAttempt)
            : [];
         $Lazy->release();

         $Probe = new Swaps($path, '1414141414141414');
         $probeAttempt = $Probe->request($Snapshot);
         $Probe->sweep();
         $heldWhileJoined = is_dir("{$path}{$lazyInstance}");
         foreach ($JoinSockets as $JoinSocket) {
            @fwrite($JoinSocket, 'q');
            fclose($JoinSocket);
         }
         $joinersExited = true;
         foreach ($Joiners as $Joiner) {
            $waited = pcntl_waitpid($Joiner, $joinStatus);
            $joinersExited = $joinersExited
               && $waited === $Joiner
               && pcntl_wifexited($joinStatus)
               && pcntl_wexitstatus($joinStatus) === 0;
         }
         $Probe->sweep();
         yield assert(
            assertion: is_string($lazyAttempt)
               && is_string($probeAttempt)
               && $joined === [true, true, true]
               && count($joinedACKs) === 3
               && $heldWhileJoined
               && $joinersExited
               && is_dir("{$path}{$lazyInstance}") === false
               && is_file("{$path}{$lazyInstance}.owner.lock") === false,
            description: 'pre-publication workers join one committed lease and keep it live until their final descriptor closes'
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

         // @ A separate owner creates its lease after fork and then exits.
         //   Once reaped, no inherited descriptor remains to keep that
         //   namespace live, so the next publication must collect it.
         $retiredInstance = 'dddddddddddddddd';
         $RetiredPID = pcntl_fork();
         if ($RetiredPID === 0) {
            $Retired = new Swaps($path, $retiredInstance);
            exit($Retired->request($foreign) === null ? 1 : 0);
         }
         pcntl_waitpid($RetiredPID, $retiredStatus);
         $retiredExisted = is_dir("{$path}{$retiredInstance}")
            && is_file("{$path}{$retiredInstance}.owner.lock");

         $Fourth = new Swaps($path, 'eeeeeeeeeeeeeeee');
         $fourthAttempt = $Fourth->request($foreign);
         $Fourth->sweep();
         yield assert(
            assertion: $RetiredPID > 1
               && pcntl_wifexited($retiredStatus)
               && pcntl_wexitstatus($retiredStatus) === 0
               && $retiredExisted
               && is_string($fourthAttempt)
               && is_dir("{$path}{$retiredInstance}") === false
               && is_file("{$path}{$retiredInstance}.owner.lock") === false
               && is_dir("{$path}{$instance}")
               && is_dir("{$path}bbbbbbbbbbbbbbbb")
               && is_dir("{$path}cccccccccccccccc"),
            description: 'publication reclaims a reaped owner namespace while preserving every live leased sibling'
         );

         $Released = new Swaps($path, 'ffffffffffffffff');
         $releasedAttempt = $Released->request($foreign);
         $Released->release();
         $Released->release();
         $Fourth->request($foreign);
         $Fourth->sweep();
         yield assert(
            assertion: is_string($releasedAttempt)
               && $Released->request($foreign) === null
               && $Released->fetch() === null
               && is_dir("{$path}ffffffffffffffff") === false
               && is_file("{$path}ffffffffffffffff.owner.lock") === false,
            description: 'release is idempotent, disables further access and lets a later owner collect the namespace'
         );

         // @ Crash recovery is deliberately structural. A valid/free lease
         //   proves owner death, but only states that this implementation can
         //   leave before/after its atomic marker commit may be removed.
         $Seed = static function (string $file, string $contents): bool {
            return file_put_contents($file, $contents) === strlen($contents)
               && chmod($file, 0600);
         };
         $leaseOnly = '0101010101010101';
         $emptyClaim = '0202020202020202';
         $partialClaim = '0303030303030303';
         $emptyTombstone = '0404040404040404';
         $boundTombstone = '0505050505050505';
         $mismatch = '0606060606060606';
         $ambiguous = '0707070707070707';
         $malformed = '0808080808080808';
         $multiple = '0a0a0a0a0a0a0a0a';
         $tokens = [
            $emptyClaim => str_repeat('1', 64),
            $partialClaim => str_repeat('2', 64),
            $emptyTombstone => str_repeat('3', 64),
            $boundTombstone => str_repeat('4', 64),
            $mismatch => str_repeat('5', 64),
            $ambiguous => str_repeat('6', 64),
            $malformed => str_repeat('7', 64),
            $multiple => str_repeat('d', 64),
         ];

         $fixtures = [$Seed("{$path}{$leaseOnly}.owner.lock", '')];
         foreach ($tokens as $crashedInstance => $token) {
            $fixtures[] = $Seed("{$path}{$crashedInstance}.owner.lock", $token);
         }
         $fixtures[] = mkdir("{$path}{$emptyClaim}", 0700);
         $fixtures[] = mkdir("{$path}{$partialClaim}", 0700);
         $partial = "{$path}{$partialClaim}/.owner-" . str_repeat('8', 64) . '.tmp';
         $fixtures[] = $Seed($partial, substr($tokens[$partialClaim], 0, 17));
         $emptyGC = "{$path}.gc-{$emptyTombstone}-1111111111111111";
         $fixtures[] = mkdir($emptyGC, 0700);
         $boundGC = "{$path}.gc-{$boundTombstone}-2222222222222222";
         $fixtures[] = mkdir($boundGC, 0700);
         $fixtures[] = $Seed("{$boundGC}/owner.token", $tokens[$boundTombstone]);
         $fixtures[] = $Seed("{$boundGC}/request.json", '{}');
         $fixtures[] = mkdir("{$path}{$mismatch}", 0700);
         $fixtures[] = $Seed("{$path}{$mismatch}/owner.token", str_repeat('9', 64));
         $fixtures[] = mkdir("{$path}{$ambiguous}", 0700);
         $fixtures[] = $Seed("{$path}{$ambiguous}/request.json", '{}');
         $fixtures[] = mkdir("{$path}{$malformed}", 0700);
         $fixtures[] = $Seed("{$path}{$malformed}/owner.token", 'partial-foreign-marker');

         // More than one tombstone for one free lease cannot be ordered or
         // authenticated as the unique continuation of a rename. Preserve the
         // entire set byte-for-byte for offline inspection.
         $multipleTombstones = [
            "{$path}.gc-{$multiple}-3333333333333333",
            "{$path}.gc-{$multiple}-4444444444444444",
         ];
         $multiplePaths = ["{$path}{$multiple}.owner.lock"];
         foreach ($multipleTombstones as $index => $tombstone) {
            $evidence = "evidence-{$index}";
            $fixtures[] = mkdir($tombstone, 0700);
            $fixtures[] = $Seed("{$tombstone}/owner.token", $tokens[$multiple]);
            $fixtures[] = $Seed("{$tombstone}/evidence", $evidence);
            $multiplePaths[] = $tombstone;
            $multiplePaths[] = "{$tombstone}/owner.token";
            $multiplePaths[] = "{$tombstone}/evidence";
         }
         $Identify = static function (string $entry): array {
            $status = @lstat($entry);

            return is_array($status)
               ? array_intersect_key($status, array_flip([
                  'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime'
               ]))
               : [];
         };
         $multipleBefore = [];
         foreach ($multiplePaths as $entry) {
            $multipleBefore[$entry] = $Identify($entry);
         }

         yield assert(
            assertion: in_array(false, $fixtures, true) === false
               && filesize("{$path}{$leaseOnly}.owner.lock") === 0
               && (fileperms("{$path}{$leaseOnly}.owner.lock") & 0777) === 0600
               && scandir("{$path}{$emptyClaim}") === ['.', '..']
               && file_get_contents("{$path}{$emptyClaim}.owner.lock") === $tokens[$emptyClaim]
               && file_get_contents($partial) === substr($tokens[$partialClaim], 0, 17)
               && (fileperms($partial) & 0777) === 0600
               && file_get_contents("{$boundGC}/owner.token") === $tokens[$boundTombstone]
               && file_get_contents("{$boundGC}/request.json") === '{}'
               && count($multipleBefore) === count($multiplePaths)
               && in_array([], $multipleBefore, true) === false,
            description: 'every synthetic crash fixture exists in the exact state its recovery assertion claims'
         );

         $Fourth->sweep();
         yield assert(
            assertion: is_file("{$path}{$leaseOnly}.owner.lock") === false
               && is_dir("{$path}{$emptyClaim}") === false
               && is_file("{$path}{$emptyClaim}.owner.lock") === false
               && (glob("{$path}.gc-{$emptyClaim}-*") ?: []) === []
               && is_dir("{$path}{$partialClaim}") === false
               && is_file("{$path}{$partialClaim}.owner.lock") === false
               && (glob("{$path}.gc-{$partialClaim}-*") ?: []) === []
               && is_dir($emptyGC) === false
               && is_file("{$path}{$emptyTombstone}.owner.lock") === false
               && is_dir($boundGC) === false
               && is_file("{$path}{$boundTombstone}.owner.lock") === false,
            description: 'exclusive sweep resumes every provable pre-commit and interrupted-GC crash state'
         );
         yield assert(
            assertion: is_dir("{$path}{$mismatch}")
               && is_file("{$path}{$mismatch}.owner.lock")
               && file_get_contents("{$path}{$mismatch}/owner.token") === str_repeat('9', 64)
               && is_dir("{$path}{$ambiguous}")
               && is_file("{$path}{$ambiguous}/request.json")
               && is_file("{$path}{$ambiguous}.owner.lock")
               && is_dir("{$path}{$malformed}")
               && file_get_contents("{$path}{$malformed}/owner.token") === 'partial-foreign-marker'
               && is_file("{$path}{$malformed}.owner.lock"),
            description: 'mismatched, malformed and markerless non-empty ownership states remain untouched'
         );
         $multipleAfter = [];
         foreach ($multiplePaths as $entry) {
            $multipleAfter[$entry] = $Identify($entry);
         }
         yield assert(
            assertion: $multipleAfter === $multipleBefore
               && file_get_contents("{$path}{$multiple}.owner.lock") === $tokens[$multiple]
               && file_get_contents("{$multipleTombstones[0]}/owner.token") === $tokens[$multiple]
               && file_get_contents("{$multipleTombstones[0]}/evidence") === 'evidence-0'
               && file_get_contents("{$multipleTombstones[1]}/owner.token") === $tokens[$multiple]
               && file_get_contents("{$multipleTombstones[1]}/evidence") === 'evidence-1',
            description: 'multiple tombstones for one lease remain byte-for-byte preserved as an ambiguous recovery state'
         );

         // @ A crash after link(owner.token) but before unlink(temp) leaves two
         //   names for the same inode. It is already committed and must remain
         //   joinable, then become fully reclaimable after the last owner exits.
         $linkedInstance = '0909090909090909';
         $linkedToken = str_repeat('a', 64);
         $linkedPath = "{$path}{$linkedInstance}";
         $linkedTemporary = "{$linkedPath}/.owner-" . str_repeat('b', 64) . '.tmp';
         $Seed("{$path}{$linkedInstance}.owner.lock", $linkedToken);
         mkdir($linkedPath, 0700);
         $Seed($linkedTemporary, $linkedToken);
         link($linkedTemporary, "{$linkedPath}/owner.token");
         $Seed("{$linkedPath}/request.json", (string) json_encode([
            'attempt' => str_repeat('c', 32),
            'generation' => $foreign->generation,
            'certificateHash' => $foreign->certificateHash,
            'keyHash' => $foreign->keyHash,
            'requested' => time(),
         ]));
         $linkedTemporaryStatus = lstat($linkedTemporary);
         $linkedMarkerStatus = lstat("{$linkedPath}/owner.token");
         $linkedCommitted = is_array($linkedTemporaryStatus)
            && is_array($linkedMarkerStatus)
            && $linkedTemporaryStatus['dev'] === $linkedMarkerStatus['dev']
            && $linkedTemporaryStatus['ino'] === $linkedMarkerStatus['ino']
            && $linkedTemporaryStatus['nlink'] === 2
            && $linkedMarkerStatus['nlink'] === 2;
         $Linked = new Swaps($path, $linkedInstance);
         $linkedDesired = $Linked->fetch();
         $Fourth->sweep();
         $linkedHeld = is_dir($linkedPath);
         $Linked->release();
         $Fourth->sweep();
         yield assert(
            assertion: $linkedCommitted
               && is_array($linkedDesired)
               && $linkedDesired['attempt'] === str_repeat('c', 32)
               && $linkedHeld
               && is_dir($linkedPath) === false
               && is_file("{$path}{$linkedInstance}.owner.lock") === false,
            description: 'the atomic marker hard-link crash state stays joinable and is reclaimed only after release'
         );

         // @ Rolling upgrades can leave namespaces created by a version that
         //   had no kernel lease. Their liveness is unknowable online, so they
         //   must be preserved rather than deleted by age or naming alone.
         $lockless = "{$path}1111111111111111";
         mkdir($lockless, 0700);
         file_put_contents("{$lockless}/request.json", '{}');
         $Fourth->request($foreign);
         $Fourth->sweep();
         $Collision = new Swaps($path, '1111111111111111');
         $collisionRejected = $Collision->request($foreign) === null;
         yield assert(
            assertion: $collisionRejected
               && is_dir($lockless)
               && is_file("{$lockless}/request.json")
               && is_file("{$path}1111111111111111.owner.lock") === false,
            description: 'a historical lockless namespace is preserved by collection and rejected on namespace collision'
         );

         // @ Upgrade cleanup is deliberately limited to the old base-level
         //   layout; it never traverses a 16-hex live instance namespace.
         $legacyGeneration = "{$path}" . str_repeat('9', 32) . '/';
         mkdir($legacyGeneration, 0700, true);
         file_put_contents("{$legacyGeneration}101.json", '{}');
         file_put_contents("{$path}request.json", '{}');
         file_put_contents("{$path}applied.json", '{}');

         $Swaps->request($Snapshot);
         $Swaps->sweep();

         yield assert(
            assertion: is_dir($legacyGeneration) === false
               && is_file("{$path}request.json") === false
               && is_file("{$path}applied.json") === false
               && is_dir("{$path}bbbbbbbbbbbbbbbb")
               && is_dir("{$path}cccccccccccccccc")
               && is_dir($lockless),
            description: 'publication cleans the legacy base layout while preserving live and lockless siblings'
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
