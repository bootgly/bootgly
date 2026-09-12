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
use function array_filter;
use function array_keys;
use function explode;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_file;
use function json_encode;
use function openssl_x509_parse;
use function preg_match;
use function preg_match_all;
use function str_contains;
use function strlen;
use function substr;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;


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
 * image too — the artefact that actually leaks.
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
      // ! Runtime and dependency trees are not the repository's content
      $skipped = ['.git', 'node_modules', 'storage', 'tmp', 'vendor'];

      $Walk = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator(BOOTGLY_ROOT_DIR, FilesystemIterator::SKIP_DOTS),
         RecursiveIteratorIterator::SELF_FIRST
      );

      $unlisted = [];
      $foreign = [];
      $counted = 0;
      // @@ Every file in the tree, read once
      foreach ($Walk as $path => $Entry) {
         $relative = substr((string) $path, strlen(BOOTGLY_ROOT_DIR));
         $top = explode('/', $relative, 2)[0];
         if (in_array($top, $skipped, true) === true) {
            continue;
         }
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

      yield (new Assertion(
         description: 'The walk saw the known fixture certificates (the guard is not vacuous)',
         fallback: 'INFRA-1 guard saw no certificate at all — the tree it walked is not the repository.'
      ))
         ->expect($counted >= 4)
         ->to->be(true)
         ->assert();

      yield (new Assertion(
         description: 'Every certificate in the tree is a self-signed localhost fixture',
         fallback: 'CONFIRMED INFRA-1: a certificate that is not a self-signed localhost fixture is '
            . 'tracked in the tree: ' . json_encode($foreign)
      ))
         ->expect($foreign)
         ->to->be([])
         ->assert();

      yield (new Assertion(
         description: 'Every private key in the tree is one of the named test fixtures',
         fallback: 'CONFIRMED INFRA-1: private key material outside the named fixtures: '
            . json_encode($unlisted)
      ))
         ->expect($unlisted)
         ->to->be([])
         ->assert();

      // ? The named set must exist — a stale entry would let a re-added file
      //   of the same name pass by default
      $missing = array_keys(array_filter($keyed, static fn (string $file): bool
         => is_file(BOOTGLY_ROOT_DIR . $file) === false));

      yield (new Assertion(
         description: 'Every named key fixture is present, so the allow-list carries no stale entry',
         fallback: 'The INFRA-1 allow-list names files that do not exist: ' . json_encode($missing)
      ))
         ->expect($missing)
         ->to->be([])
         ->assert();
   }
);
