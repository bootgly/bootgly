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
use function file_exists;
use function file_get_contents;
use function getenv;
use function is_file;
use function json_encode;
use function preg_match;
use function trim;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * Security regression H5 — a Docker build must not copy Git-ignored secrets
 * into the deployable image.
 *
 * The harmless canaries live in the real framework build context under the
 * same path shapes used by project environment files, Codex-local state and
 * private scripts. The official Dockerfile copies that context into /bootgly.
 * This test executes only in a provenance-labelled image, proves that a normal
 * tracked control crossed the same COPY boundary, and then requires every
 * secret-shaped canary to be absent.
 */
$frameworkSHA = (string) getenv('BOOTGLY_FRAMEWORK_SHA');
$image = preg_match('/^[a-f0-9]{40}$/D', $frameworkSHA) === 1;

return new Test(
   description: 'Docker build context must exclude Git-ignored secret files',
   skip: $image === false,

   test: function () use ($frameworkSHA) {
      $control = BOOTGLY_ROOT_DIR
         . 'Bootgly/API/Environment/tests/fixtures/docker-context/control.txt';
      $controlBytes = @file_get_contents($control);

      yield (new Assertion(
         description: 'The runner is the built image and its ordinary control crossed COPY',
         fallback: 'H5 Docker image harness control failed before the secret-boundary assertion.'
      ))
         ->expect(
            preg_match('/^[a-f0-9]{40}$/D', $frameworkSHA) === 1
               && file_exists(BOOTGLY_ROOT_DIR . '.git') === false
               && is_file(BOOTGLY_ROOT_DIR . 'Dockerfile')
               && is_file($control)
               && trim((string) $controlBytes) === 'H5_CONTROL_INCLUDED'
         )
         ->to->be(true)
         ->assert();

      $canaries = [
         'project .env' => BOOTGLY_ROOT_DIR
            . 'projects/H5_Docker_Context/configs/runtime/.env',
         'project .env.production' => BOOTGLY_ROOT_DIR
            . 'projects/H5_Docker_Context/configs/runtime/.env.production',
         'project .codex credentials' => BOOTGLY_ROOT_DIR
            . 'projects/H5_Docker_Context/.codex/credentials.json',
         'private script' => BOOTGLY_ROOT_DIR . 'scripts/_h5-docker-context-secret.php',
      ];
      $present = array_keys(array_filter($canaries, is_file(...)));

      yield (new Assertion(
         description: 'Secret-shaped canaries do not cross the Docker COPY boundary',
         fallback: 'CONFIRMED H5: Dockerfile build context copied Git-ignored secret '
            . 'canaries into the runtime image: ' . json_encode($present)
      ))
         ->expect($present)
         ->to->be([])
         ->assert();
   }
);
