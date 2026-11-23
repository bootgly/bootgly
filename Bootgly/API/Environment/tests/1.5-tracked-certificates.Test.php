<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment;


use const BOOTGLY_ROOT_DIR;
use const OPENSSL_KEYTYPE_EC;
use function array_filter;
use function array_keys;
use function array_values;
use function chmod;
use function clearstatcache;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_readable;
use function json_encode;
use function ksort;
use function mkdir;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function openssl_x509_export;
use function openssl_x509_parse;
use function preg_match;
use function preg_match_all;
use function sort;
use function str_contains;
use function str_starts_with;
use function symlink;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function Bootgly\ABI\remove_recursively;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * Security regression INFRA-1 — no certificate or private key in the tree may
 * be anything but a self-signed `localhost` test fixture.
 *
 * `@/certificates/localhost.pem` carried a Cloudflare Origin CA certificate for
 * the production domain WITH its private key through twenty published tags,
 * every Packagist dist and every Docker image. Nothing referenced it; nothing
 * looked at it. This case looks: every file that carries PEM key material must
 * be named below, and every certificate anywhere must be self-signed with
 * `CN=localhost`. A new fixture is added HERE, consciously, or the suite is red.
 *
 * It walks the filesystem, not `git ls-files`, so it holds inside a published
 * image too — the artefact that actually leaks. The runtime and dependency
 * trees at the root (`.git`, `node_modules`, `storage`, `tmp`, `vendor`) are
 * pruned before the walk enters them, so what a local run leaves there never
 * reaches the verdict. Anywhere else, a directory the walk cannot read or
 * traverse fails the case by name: the guard cannot vouch for what it cannot
 * see. Symlinked directories are listed, never followed.
 */
return new Test(
   description: 'Every certificate in the tree is a self-signed localhost fixture, and every private key is a named one',

   test: function () {
      // ! The ONLY files allowed to carry a private key. Paths, not patterns:
      //   a glob would re-admit the next file dropped beside them.
      $keyed = [
         '@/certificates/localhost.key.pem',
         'Bootgly/ADI/Database/tests/fixtures/postgresql_tls.pem',
         'Bootgly/API/Security/tests/fixtures/jwt_loopback_tls.pem',
         'Bootgly/API/Security/tests/fixtures/jwt_rs256.php',
         'Bootgly/WPI/Nodes/HTTP_Client_CLI/tests/E2E_SSL/localhost.key.pem',
      ];
      // ! Runtime and dependency trees are not the repository's content —
      //   matched against the whole path from the root: top-level entries only
      $skipped = ['.git', 'node_modules', 'storage', 'tmp', 'vendor'];

      // ! The walk: prunes the skipped entries before descending, reports the
      //   directories it cannot enter, never follows a symlinked directory
      $scan = static function (string $root) use ($keyed, $skipped): array {
         $unreadable = [];
         $Walk = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
               new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
               static function (
                  SplFileInfo $Entry, string $path, RecursiveDirectoryIterator $Iterator
               ) use ($skipped, &$unreadable): bool {
                  $relative = $Iterator->getSubPathname();
                  // ? Pruned: never opened, never stat'ed below
                  if (in_array($relative, $skipped, true) === true) {
                     return false;
                  }
                  // ? A directory it cannot list or traverse — named, not entered
                  if (
                     $Entry->isLink() === false && $Entry->isDir() === true
                     && ($Entry->isReadable() === false || $Entry->isExecutable() === false)
                  ) {
                     $unreadable[] = $relative;

                     return false;
                  }

                  // :
                  return true;
               }
            ),
            RecursiveIteratorIterator::SELF_FIRST
         );

         $visited = [];
         $unlisted = [];
         $foreign = [];
         $counted = 0;
         // @@ Every file in the tree, read once
         foreach ($Walk as $path => $Entry) {
            // ! Recorded before any skip, so the pruning itself is observable
            $relative = $Walk->getSubPathname();
            $visited[] = $relative;
            if ($Entry->isFile() === false || $Entry->getSize() > 524288) {
               continue;
            }

            $bytes = (string) file_get_contents((string) $path);
            if (str_contains($bytes, '-----BEGIN ') === false) {
               continue;
            }

            // ? Key material outside the named set — fail closed, whatever it is
            if (preg_match('#-----BEGIN (?:RSA |EC |ENCRYPTED |OPENSSH )?PRIVATE KEY-----#', $bytes) === 1
               && in_array($relative, $keyed, true) === false) {
               $unlisted[] = $relative;
            }

            // ? Every certificate: self-signed, and named localhost
            preg_match_all('#-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----#s', $bytes, $blocks);
            foreach ($blocks[0] as $block) {
               $parsed = openssl_x509_parse($block);
               if (is_array($parsed) === false) {
                  continue; // a regex or a fixture string, not a certificate
               }
               $counted++;

               $subject = $parsed['subject'] ?? [];
               $issuer = $parsed['issuer'] ?? [];
               if ($subject !== $issuer || ($subject['CN'] ?? null) !== 'localhost') {
                  $foreign[$relative] = json_encode(['subject' => $subject, 'issuer' => $issuer]);
               }
            }
         }

         sort($unlisted);
         sort($unreadable);
         ksort($foreign);

         // :
         return [$counted, $foreign, $unlisted, $unreadable, $visited];
      };

      // @ The tree this checkout (or image) carries — judged before anything is planted
      [$counted, $foreign, $unlisted, $unreadable] = $scan(BOOTGLY_ROOT_DIR);

      yield (new Assertion(
         description: 'The walk saw the known fixture certificates (the guard is not vacuous)',
         fallback: 'INFRA-1 guard saw no certificate at all — the tree it walked is not the repository.'
      ))
         ->expect($counted >= 4)
         ->to->be(true)
         ->assert();

      $listed = json_encode($unreadable);
      yield (new Assertion(
         description: 'Every directory of the tree outside the pruned ones can be read',
         fallback: "INFRA-1 guard cannot vouch for directories it cannot read or traverse: {$listed}"
      ))
         ->expect($unreadable)
         ->to->be([])
         ->assert();

      $listed = json_encode($foreign);
      yield (new Assertion(
         description: 'Every certificate in the tree is a self-signed localhost fixture',
         fallback: "CONFIRMED INFRA-1: a certificate that is not a self-signed localhost fixture is tracked in the tree: {$listed}"
      ))
         ->expect($foreign)
         ->to->be([])
         ->assert();

      $listed = json_encode($unlisted);
      yield (new Assertion(
         description: 'Every private key in the tree is one of the named test fixtures',
         fallback: "CONFIRMED INFRA-1: private key material outside the named fixtures: {$listed}"
      ))
         ->expect($unlisted)
         ->to->be([])
         ->assert();

      // ? The named set must exist — a stale entry would let a re-added file
      //   of the same name pass by default
      $missing = array_keys(array_filter($keyed, static fn (string $file): bool
         => is_file(BOOTGLY_ROOT_DIR . $file) === false));

      $listed = json_encode($missing);
      yield (new Assertion(
         description: 'Every named key fixture is present, so the allow-list carries no stale entry',
         fallback: "The INFRA-1 allow-list names files that do not exist: {$listed}"
      ))
         ->expect($missing)
         ->to->be([])
         ->assert();

      // --- The walk itself, on planted trees outside the checkout (inside it,
      //     the guard above would judge the planted material)

      // ! A foreign certificate and a loose key, generated here: no PEM in this file
      $Key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
      $Request = openssl_csr_new(['commonName' => 'example.test'], $Key, ['digest_alg' => 'sha256']);
      $Certificate = openssl_csr_sign($Request, null, $Key, 1, ['digest_alg' => 'sha256']);
      $certificate = '';
      $key = '';
      $exported = openssl_x509_export($Certificate, $certificate) && openssl_pkey_export($Key, $key);

      $base = Temporaries::reserve('tracked-certificates');
      $root = "{$base}/root";
      $outside = "{$base}/outside";
      $loud = "{$base}/loud";
      // ! Locked only around the walks: a run killed inside that window leaves
      //   a reservation `Temporaries::sweep()` cannot remove
      $locks = [
         "{$root}/tmp/locked" => 0000,
         "{$loud}/Planted/listless" => 0111,
         "{$loud}/Planted/locked" => 0000,
         "{$loud}/Planted/searchless" => 0444,
      ];

      $planted = [];
      $loudly = [];
      $controls = [];
      try {
         // ! Found: plain files, a `tmp/` that is not at the root, and names
         //   that only start like a pruned one
         mkdir("{$root}/Planted/tmp", 0755, true);
         file_put_contents("{$root}/Planted/foreign.pem", $certificate);
         file_put_contents("{$root}/Planted/tmp/nested.pem", $certificate);
         file_put_contents("{$root}/Planted/loose.key", $key);
         file_put_contents("{$root}/vendor.pem", $certificate);
         file_put_contents("{$root}/storage.key", $key);
         // ! Pruned: the same material under every skipped root entry
         // @@ One tree per skipped name
         foreach ($skipped as $name) {
            mkdir("{$root}/{$name}", 0755, true);
            file_put_contents("{$root}/{$name}/foreign.pem", $certificate);
            file_put_contents("{$root}/{$name}/loose.key", $key);
         }
         // ! Not followed: a symlinked directory out of the tree
         mkdir($outside, 0755, true);
         file_put_contents("{$outside}/foreign.pem", $certificate);
         symlink($outside, "{$root}/Linked");
         // ! Named: directories the walk cannot list or traverse
         mkdir("{$loud}/Planted/searchless", 0755, true);
         file_put_contents("{$loud}/Planted/searchless/foreign.pem", $certificate);
         // ! Not named: a link to a locked directory is listed, never judged as one
         symlink("{$loud}/Planted/locked", "{$loud}/Planted/pointer");
         // @@ Every lock, the pruned one included
         foreach ($locks as $lock => $mode) {
            if (is_dir($lock) === false) {
               mkdir($lock, 0755, true);
            }
            chmod($lock, $mode);
         }
         clearstatcache();

         // ! Controls: the link would expose a certificate if followed; the
         //   locks hold for this user (not for root, nor a mode-less filesystem)
         $controls = [
            'linked' => is_link("{$root}/Linked") === true && is_file("{$root}/Linked/foreign.pem") === true,
            'locked' => is_readable("{$loud}/Planted/locked") === false,
         ];

         // @ Walk both planted trees
         $planted = $scan("{$root}/");
         $loudly = $scan($loud);
      }
      finally {
         // @@ Unlock first, then remove it all, links as links
         foreach (array_keys($locks) as $lock) {
            if (is_dir($lock) === true) {
               chmod($lock, 0755);
            }
         }
         remove_recursively($base);
      }

      yield (new Assertion(
         description: 'The planted material was generated and the symlink would expose a certificate if followed'
      ))
         ->expect($exported === true && ($controls['linked'] ?? false) === true)
         ->to->be(true)
         ->assert();

      [, $plantedForeign, $plantedUnlisted, $plantedUnreadable, $plantedVisited] = $planted;

      $listed = json_encode(array_keys($plantedForeign));
      yield (new Assertion(
         description: 'Planted foreign certificates are found: a `tmp/` below the root and a top-level name that only starts like a pruned one are walked',
         fallback: "The walk missed planted certificates: {$listed}"
      ))
         ->expect(array_keys($plantedForeign))
         ->to->be(['Planted/foreign.pem', 'Planted/tmp/nested.pem', 'vendor.pem'])
         ->assert();

      $listed = json_encode($plantedUnlisted);
      yield (new Assertion(
         description: 'Planted loose private keys are found',
         fallback: "The walk missed planted keys: {$listed}"
      ))
         ->expect($plantedUnlisted)
         ->to->be(['Planted/loose.key', 'storage.key'])
         ->assert();

      $entered = array_values(array_filter($plantedVisited, static function (string $relative) use ($skipped): bool {
         // @@ At or under a skipped root entry
         foreach ($skipped as $name) {
            if ($relative === $name || str_starts_with($relative, "{$name}/") === true) {
               return true;
            }
         }

         // :
         return false;
      }));

      $listed = json_encode($entered);
      yield (new Assertion(
         description: 'The skipped root entries are pruned before the walk enters them, the locked `tmp/locked` included',
         fallback: "The walk entered a skipped tree: {$listed}"
      ))
         ->expect([$entered, $plantedUnreadable])
         ->to->be([[], []])
         ->assert();

      yield (new Assertion(
         description: 'A symlinked directory is listed but not followed out of the tree'
      ))
         ->expect([
            in_array('Linked', $plantedVisited, true),
            in_array('Linked/foreign.pem', $plantedVisited, true),
         ])
         ->to->be([true, false])
         ->assert();

      // ? Root (or a filesystem without modes) reads every lock: nothing to observe
      if (($controls['locked'] ?? false) === false) {
         yield (new Assertion(
            description: 'Unreadable directories are named: skipped, the locks do not hold for this user'
         ))->skip();

         return;
      }

      [, $loudForeign, , $loudUnreadable] = $loudly;

      $listed = json_encode($loudUnreadable);
      yield (new Assertion(
         description: 'Directories the walk cannot list or traverse are named (a link to one is not), and nothing behind them is read',
         fallback: "The walk did not report its unreadable directories: {$listed}"
      ))
         ->expect([$loudUnreadable, $loudForeign])
         ->to->be([['Planted/listless', 'Planted/locked', 'Planted/searchless'], []])
         ->assert();
   }
);
