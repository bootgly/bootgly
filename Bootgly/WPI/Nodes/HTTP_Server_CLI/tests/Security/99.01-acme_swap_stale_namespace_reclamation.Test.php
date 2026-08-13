<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\CertificateSnapshot;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC L2 (HTTP_Server_CLI audit 2026-08-02) — swap namespaces
 * belonging to terminated AutoTLS owner processes must not accumulate forever.
 *
 * Every AutoTLS construction receives a fresh random 16-hex namespace. The
 * real Swaps request/acknowledge/complete/prune lifecycle persists three files
 * below that namespace. A later request executes Swaps::sweep(), but vulnerable
 * code removes only the legacy 32-hex layout and never a 16-hex namespace,
 * even after its actual owner process exited and was reaped.
 *
 * This case distinguishes an owner from a publisher. Three child processes
 * each construct their own AutoTLS instance, publish a complete swap, exit and
 * are reaped; their trees are then aged by 400 days. Separately, a live owner
 * constructs AutoTLS and forks a short-lived publisher that uses the inherited
 * namespace. The publisher exits, but its owning process remains alive. A
 * fresh instance finally publishes on the same base, driving the production
 * sweep. Secure behavior may reclaim the old terminated-owner trees, but must
 * preserve both the live owner's exact records and the current namespace.
 *
 * Controls prove that the trigger really ran (a legacy 32-hex tree is swept),
 * that a fresh publication remains usable, that a dead publisher does not make
 * its live owner's namespace stale, and that a namespace-shaped symlink is
 * neither followed nor used to delete an outside sentinel.
 *
 * The PoC proves persistent linear namespace/file growth. Disk or inode
 * exhaustion remains a long-duration inferred consequence, not a reproduced
 * result and not an unauthenticated remote attack claim.
 */
$probe = [
   'error' => '',
   'retired' => [],
   'live' => [],
   'current' => [],
   'controls' => [],
   'evidence' => [],
];

return new Test(
   description: 'Terminated AutoTLS owner namespaces must be reclaimed without touching live siblings',
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $storage = sys_get_temp_dir()
         . '/bootgly-security-l2-swaps-' . bin2hex(random_bytes(6));
      $swapsBase = "{$storage}/swaps/";
      $outside = "{$storage}-outside";
      $livePID = 0;
      $LiveSocket = null;

      $Remove = static function (string $directory): void {
         if (is_link($directory)) {
            @unlink($directory);

            return;
         }
         if (is_dir($directory) === false) {
            return;
         }

         $Iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
         );
         foreach ($Iterator as $Entry) {
            $path = $Entry->getPathname();
            if ($Entry->isLink() || $Entry->isFile()) {
               @unlink($path);
            }
            else {
               @rmdir($path);
            }
         }
         @rmdir($directory);
      };

      $Snapshot = static function (string $label): CertificateSnapshot {
         return new CertificateSnapshot(
            hash('md5', "l2-generation-{$label}"),
            "/tmp/l2-{$label}-certificate.pem",
            "/tmp/l2-{$label}-key.pem",
            hash('sha256', "l2-certificate-{$label}"),
            hash('sha256', "l2-key-{$label}"),
            time() - 60,
            time() + 3600,
            false,
            ['localhost'],
         );
      };

      $Build = static function (string $path): AutoTLS {
         return new AutoTLS(
            domains: ['localhost'],
            email: 'security-l2@example.test',
            staging: true,
            path: $path,
            challenges: "{$path}/challenges",
            port: 5002,
         );
      };

      $Publish = static function (
         AutoTLS $Secure,
         CertificateSnapshot $Snapshot,
      ): array {
         $Swaps = $Secure->Swaps;
         $attempt = $Swaps->request($Snapshot);
         $PID = getmypid();
         $acknowledged = is_string($attempt)
            && $Swaps->acknowledge(
               $attempt,
               $Snapshot->generation,
               $PID,
               true,
               $Snapshot->certificateHash,
               $Snapshot->keyHash,
            );
         $completed = is_string($attempt)
            && $Swaps->complete($Snapshot, $attempt);
         if (is_string($attempt)) {
            $Swaps->prune($Snapshot->generation, $attempt);
         }

         return [
            'instance' => $Secure->instance,
            'path' => $Swaps->path,
            'attempt' => $attempt,
            'acknowledged' => $acknowledged,
            'completed' => $completed,
            'fetched' => $Swaps->fetch(),
            'resolved' => $Swaps->resolve(),
            'generation' => $Snapshot->generation,
            'created' => is_dir($Swaps->path),
         ];
      };

      $Age = static function (string $directory, int $timestamp): bool {
         if (is_dir($directory) === false || is_link($directory)) {
            return false;
         }

         $aged = true;
         $Iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
         );
         foreach ($Iterator as $Entry) {
            $path = $Entry->getPathname();
            if ($Entry->isFile() && str_ends_with($path, '.json')) {
               $JSON = @file_get_contents($path);
               $record = is_string($JSON) ? json_decode($JSON, true) : null;
               if (is_array($record)) {
                  foreach (['requested', 'acknowledged', 'applied'] as $field) {
                     if (array_key_exists($field, $record)) {
                        $record[$field] = $timestamp;
                     }
                  }
                  $encoded = json_encode($record);
                  $aged = is_string($encoded)
                     && file_put_contents($path, $encoded) !== false
                     && $aged;
               }
            }
            $aged = @touch($path, $timestamp, $timestamp) && $aged;
         }

         return @touch($directory, $timestamp, $timestamp) && $aged;
      };

      $Measure = static function (string $directory): array {
         $files = 0;
         $bytes = 0;
         if (is_dir($directory) && is_link($directory) === false) {
            $Iterator = new RecursiveIteratorIterator(
               new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($Iterator as $Entry) {
               if ($Entry->isFile() && $Entry->isLink() === false) {
                  $files++;
                  $bytes += $Entry->getSize();
               }
            }
         }

         return ['files' => $files, 'bytes' => $bytes];
      };

      $Read = static function ($Socket): array {
         stream_set_timeout($Socket, 10);
         $line = fgets($Socket);
         $record = is_string($line) ? json_decode(trim($line), true) : null;

         return is_array($record)
            ? $record
            : ['error' => 'child returned no valid JSON evidence'];
      };

      try {
         if (
            function_exists('pcntl_fork') === false
            || function_exists('pcntl_waitpid') === false
            || function_exists('posix_kill') === false
         ) {
            throw new RuntimeException('L2 requires pcntl and POSIX process support.');
         }
         if (mkdir($storage, 0700, true) === false || mkdir($outside, 0700, true) === false) {
            throw new RuntimeException('Could not create the isolated L2 storage fixture.');
         }

         // # Retired owners — AutoTLS is constructed after each fork, so the
         //   process that exits actually owns the random namespace. This is not
         //   the short-lived certifier/publisher shape.
         for ($index = 0; $index < 3; $index++) {
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($Pair === false) {
               throw new RuntimeException('Could not create a retired-owner evidence socket.');
            }

            $PID = pcntl_fork();
            if ($PID === -1) {
               throw new RuntimeException('Could not fork a retired AutoTLS owner.');
            }
            if ($PID === 0) {
               fclose($Pair[0]);
               fclose(STDIN);
               fclose(STDOUT);
               fclose(STDERR);

               try {
                  $Secure = $Build($storage);
                  $record = $Publish($Secure, $Snapshot("retired-{$index}"));
                  $record['error'] = '';
                  $valid = $record['created']
                     && is_string($record['attempt'])
                     && $record['acknowledged']
                     && $record['completed']
                     && is_array($record['fetched'])
                     && $record['resolved'] === $record['generation'];
               }
               catch (Throwable $Throwable) {
                  $record = [
                     'error' => $Throwable::class . ': ' . $Throwable->getMessage(),
                  ];
                  $valid = false;
               }

               fwrite($Pair[1], json_encode($record) . "\n");
               fclose($Pair[1]);
               exit($valid ? 0 : 1);
            }

            fclose($Pair[1]);
            $record = $Read($Pair[0]);
            fclose($Pair[0]);
            $waited = pcntl_waitpid($PID, $status);
            $record['owner_pid'] = $PID;
            $record['reaped'] = $waited === $PID;
            $record['exit'] = pcntl_wifexited($status)
               ? pcntl_wexitstatus($status)
               : -1;
            $record['aged'] = is_string($record['path'] ?? null)
               && $Age($record['path'], time() - (400 * 86400));
            $probe['retired'][] = $record;
         }

         // # Live owner + dead publisher — the namespace must stay. The
         //   AutoTLS instance exists before the inner fork and remains owned by
         //   the outer child after that publisher exits and is reaped.
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('Could not create the live-owner control socket.');
         }
         $livePID = pcntl_fork();
         if ($livePID === -1) {
            throw new RuntimeException('Could not fork the live AutoTLS owner control.');
         }
         if ($livePID === 0) {
            fclose($Pair[0]);
            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);

            try {
               $Secure = $Build($storage);
               $Swaps = $Secure->Swaps;
               $PublisherPair = stream_socket_pair(
                  STREAM_PF_UNIX,
                  STREAM_SOCK_STREAM,
                  STREAM_IPPROTO_IP,
               );
               if ($PublisherPair === false) {
                  throw new RuntimeException('Could not create the publisher evidence socket.');
               }
               $publisherPID = pcntl_fork();
               if ($publisherPID === -1) {
                  throw new RuntimeException('Could not fork the inherited publisher.');
               }
               if ($publisherPID === 0) {
                  fclose($PublisherPair[0]);
                  try {
                     $published = $Publish($Secure, $Snapshot('live-owner'));
                     $published['error'] = '';
                     $valid = $published['created']
                        && is_string($published['attempt'])
                        && $published['acknowledged']
                        && $published['completed'];
                  }
                  catch (Throwable $Throwable) {
                     $published = [
                        'error' => $Throwable::class . ': ' . $Throwable->getMessage(),
                     ];
                     $valid = false;
                  }
                  fwrite($PublisherPair[1], json_encode($published) . "\n");
                  fclose($PublisherPair[1]);
                  exit($valid ? 0 : 1);
               }

               fclose($PublisherPair[1]);
               $published = $Read($PublisherPair[0]);
               fclose($PublisherPair[0]);
               $waited = pcntl_waitpid($publisherPID, $publisherStatus);
               $requestFile = "{$Swaps->path}request.json";
               $appliedFile = "{$Swaps->path}applied.json";
               $record = [
                  'error' => '',
                  'owner_pid' => getmypid(),
                  'publisher_pid' => $publisherPID,
                  'publisher_reaped' => $waited === $publisherPID,
                  'publisher_exit' => pcntl_wifexited($publisherStatus)
                     ? pcntl_wexitstatus($publisherStatus)
                     : -1,
                  'instance' => $Secure->instance,
                  'path' => $Swaps->path,
                  'published' => $published,
                  'fetched' => $Swaps->fetch(),
                  'resolved' => $Swaps->resolve(),
                  'request_hash' => is_file($requestFile)
                     ? hash_file('sha256', $requestFile)
                     : false,
                  'applied_hash' => is_file($appliedFile)
                     ? hash_file('sha256', $appliedFile)
                     : false,
               ];
            }
            catch (Throwable $Throwable) {
               $record = [
                  'error' => $Throwable::class . ': ' . $Throwable->getMessage(),
               ];
            }

            fwrite($Pair[1], json_encode($record) . "\n");
            fread($Pair[1], 1);
            fclose($Pair[1]);
            exit(($record['error'] ?? '') === '' ? 0 : 1);
         }

         fclose($Pair[1]);
         $LiveSocket = $Pair[0];
         $probe['live'] = $Read($LiveSocket);
         $probe['live']['alive_during_sweep'] = posix_kill($livePID, 0);

         // # Sweep controls — one legacy directory must disappear, while a
         //   namespace-shaped symlink must not be traversed.
         $legacy = $swapsBase . str_repeat('9', 32);
         if (mkdir($legacy, 0700, true) === false) {
            throw new RuntimeException('Could not create the legacy sweep control.');
         }
         file_put_contents("{$legacy}/101.json", '{}');
         file_put_contents("{$outside}/sentinel", 'L2-OUTSIDE-SENTINEL');
         $link = $swapsBase . 'eeeeeeeeeeeeeeee';
         if (symlink($outside, $link) === false) {
            throw new RuntimeException('Could not create the symlink non-follow control.');
         }

         // # Trigger — a new AutoTLS process image/configuration publishes on
         //   the same storage base, executing the real Swaps::request/sweep.
         $Current = $Build($storage);
         $probe['current'] = $Publish($Current, $Snapshot('current'));

         $livePath = $probe['live']['path'] ?? '';
         $liveRequest = is_string($livePath) ? "{$livePath}request.json" : '';
         $liveApplied = is_string($livePath) ? "{$livePath}applied.json" : '';
         $probe['controls'] = [
            'legacy_removed' => is_dir($legacy) === false,
            'symlink_preserved' => is_link($link),
            'outside_unchanged' => @file_get_contents("{$outside}/sentinel")
               === 'L2-OUTSIDE-SENTINEL',
            'live_request_unchanged' => is_file($liveRequest)
               && hash_file('sha256', $liveRequest)
                  === ($probe['live']['request_hash'] ?? null),
            'live_applied_unchanged' => is_file($liveApplied)
               && hash_file('sha256', $liveApplied)
                  === ($probe['live']['applied_hash'] ?? null),
         ];

         $retained = [];
         foreach ($probe['retired'] as $record) {
            if (is_string($record['path'] ?? null) && is_dir($record['path'])) {
               $retained[] = $record['instance'] ?? '(unknown)';
            }
         }
         $namespaces = [];
         foreach (glob("{$swapsBase}*") ?: [] as $entry) {
            $name = basename($entry);
            if (
               preg_match('/^[a-f0-9]{16}$/', $name) === 1
               && is_link($entry) === false
               && is_dir($entry)
            ) {
               $namespaces[] = $name;
            }
         }
         sort($namespaces);
         $probe['evidence'] = [
            'retired_owner_count' => count($probe['retired']),
            'retired_namespaces_retained' => $retained,
            'real_namespace_count_after_sweep' => count($namespaces),
            'real_namespaces_after_sweep' => $namespaces,
            'storage_after_sweep' => $Measure($swapsBase),
         ];
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         if (is_resource($LiveSocket)) {
            @fwrite($LiveSocket, "q");
            @fclose($LiveSocket);
         }
         if ($livePID > 1) {
            $reaped = false;
            for ($attempt = 0; $attempt < 50; $attempt++) {
               $waited = pcntl_waitpid($livePID, $status, WNOHANG);
               if ($waited === $livePID || $waited === -1) {
                  $reaped = true;
                  break;
               }
               usleep(20_000);
            }
            if ($reaped === false) {
               @posix_kill($livePID, SIGKILL);
               $reaped = pcntl_waitpid($livePID, $status) === $livePID;
            }
            $probe['live']['reaped_after_control'] = $reaped;
         }

         $Remove($storage);
         $Remove($outside);
      }

      return "GET /l2-acme-swap-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route(
         '/l2-acme-swap-harness',
         static function (Request $Request, Response $Response): Response {
            return $Response(body: 'L2-HARNESS-OK');
         },
         GET,
      );

      yield $Router->route('/*', static function (Request $Request, Response $Response): Response {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (str_contains($response, 'L2-HARNESS-OK') === false) {
         return 'L2 fixture failed: the registered native Security harness route was not selected.';
      }
      if ($probe['error'] !== '') {
         return 'L2 fixture failed before the stale-namespace assertion: ' . $probe['error'];
      }

      if (count($probe['retired']) !== 3) {
         return 'L2 fixture failed: expected three terminated AutoTLS owners; evidence='
            . json_encode($probe['retired']);
      }
      foreach ($probe['retired'] as $record) {
         if (
            ($record['error'] ?? '') !== ''
            || ($record['created'] ?? false) !== true
            || is_string($record['attempt'] ?? null) === false
            || ($record['acknowledged'] ?? false) !== true
            || ($record['completed'] ?? false) !== true
            || is_array($record['fetched'] ?? null) === false
            || ($record['resolved'] ?? null) !== ($record['generation'] ?? null)
            || ($record['reaped'] ?? false) !== true
            || ($record['exit'] ?? -1) !== 0
            || ($record['aged'] ?? false) !== true
         ) {
            return 'L2 fixture failed: a retired owner did not complete, exit, reap, and age '
               . 'its real swap lifecycle; evidence=' . json_encode($record);
         }
      }

      $live = $probe['live'];
      $published = $live['published'] ?? [];
      if (
         ($live['error'] ?? '') !== ''
         || ($live['alive_during_sweep'] ?? false) !== true
         || ($live['publisher_reaped'] ?? false) !== true
         || ($live['publisher_exit'] ?? -1) !== 0
         || ($live['reaped_after_control'] ?? false) !== true
         || is_array($live['fetched'] ?? null) === false
         || ($live['resolved'] ?? null) !== ($published['generation'] ?? null)
         || ($published['completed'] ?? false) !== true
      ) {
         return 'L2 control failed: the dead publisher/live owner distinction was not proved; '
            . 'evidence=' . json_encode($live);
      }

      $current = $probe['current'];
      if (
         ($current['created'] ?? false) !== true
         || is_string($current['attempt'] ?? null) === false
         || ($current['acknowledged'] ?? false) !== true
         || ($current['completed'] ?? false) !== true
         || is_array($current['fetched'] ?? null) === false
         || ($current['resolved'] ?? null) !== ($current['generation'] ?? null)
      ) {
         return 'L2 control failed: the fresh production publication did not complete; evidence='
            . json_encode($current);
      }

      foreach ($probe['controls'] as $control => $passed) {
         if ($passed !== true) {
            return "L2 control failed: {$control} was false; evidence="
               . json_encode($probe['controls']);
         }
      }

      $retained = $probe['evidence']['retired_namespaces_retained'] ?? [];
      if (count($retained) === 3) {
         Vars::$labels = ['L2 stale AutoTLS swap namespace evidence'];
         dump(json_encode($probe['evidence'], JSON_UNESCAPED_SLASHES));

         return 'CONFIRMED L2: all three 400-day-old namespaces belonging to distinct '
            . 'terminated and reaped AutoTLS owner processes remained after a fresh '
            . 'production publication swept the legacy layout. The live owner and current '
            . 'namespaces were correctly preserved. This proves persistent linear '
            . 'namespace/file accumulation; disk exhaustion remains inferred.';
      }
      if ($retained === []) {
         return true;
      }

      return 'L2 fixture failed: stale-owner reclamation was partial rather than the vulnerable '
         . 'or secure invariant; evidence=' . json_encode($probe['evidence']);
   },
);
