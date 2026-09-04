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
use function is_file;
use function json_encode;
use function preg_match;
use function trim;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * Security regression H5, second half — a Docker build must not copy the
 * Git-ignored working material into the deployable image.
 *
 * The harmless canaries live in the real framework build context under the
 * same path shapes used by project environment files, Codex-local state,
 * private scripts, internal reports and local scratch work. The official
 * Dockerfile copies that context into /bootgly.
 *
 * This case needs a provenance-stamped build: without the stamp there is no way
 * to tell the built image apart from a checkout that happens to be inside a
 * container, and the control assertion below would fail an honest build. It
 * therefore SKIPS rather than passing — a skipped case is visible in the run
 * summary, while a pass over assertions that never executed is not.
 */
/** @var array{contained:bool,mounted:bool,measurable:bool,image:bool,SHA:string} $Stance */
$Stance = require __DIR__ . '/fixtures/docker-context/stance.php';

return new Test(
   description: 'Docker build context must exclude the secret-shaped canary files',
   skip: $Stance['image'] === false,

   test: function () use ($Stance) {
      $frameworkSHA = $Stance['SHA'];
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

      // ? The `!` negations are hand-maintained, and nothing else watches them:
      //   dropping one silently removes the file from the image while every
      //   suite stays green. Assert them beside the control — a re-admitted
      //   file that fails to cross is the same defect as a secret that does.
      //
      //   Only paths the FRAMEWORK owns are listed. `README.md` and anything
      //   under `projects/` are re-admitted by the ignore file too, but an
      //   image built FROM this one legitimately replaces its README and prunes
      //   the demo projects — asserting those would turn a security case red
      //   for an ordinary derived application image, which inherits the
      //   provenance stamp and therefore runs this case.
      $readmitted = [
         'Array benchmarks README' => BOOTGLY_ROOT_DIR
            . 'Bootgly/ABI/Code/__Array/tests/benchmarks/README.md',
         'Array benchmarks RESULTS' => BOOTGLY_ROOT_DIR
            . 'Bootgly/ABI/Code/__Array/tests/benchmarks/results/RESULTS.md',
         'Tests ARCHITECTURE' => BOOTGLY_ROOT_DIR . 'Bootgly/ACI/Tests/ARCHITECTURE.md',
         'container entrypoint' => BOOTGLY_ROOT_DIR . '@/__docker__/entrypoint.sh',
      ];
      // ! The twelve `.env` fixtures the Configs suites read. They are the
      //   largest negation block in the ignore file and the easiest to lose to
      //   a "tidy the .env rules" edit, which would leave those suites failing
      //   in the image with no hint that the CONTEXT is what dropped them.
      $fixtures = BOOTGLY_ROOT_DIR . 'Bootgly/API/Environment/Configs/tests/fixtures/';
      foreach ([
         'configs/database/.env',
         'configs/database/.env.development',
         'configs/policy_bad_name/.env',
         'configs/policy_environment/.env',
         'configs/policy_environment/.env.development',
         'configs/policy_environment/.env.production',
         'configs/policy_extra/.env',
         'configs/policy_good/.env',
         'configs/policy_locked/.env',
         'configs/server/.env',
         'project/configs/database/.env',
      ] as $fixture) {
         $readmitted["Configs fixture {$fixture}"] = $fixtures . $fixture;
      }
      $readmitted['Resources secure kv .env'] = BOOTGLY_ROOT_DIR
         . 'Bootgly/WPI/Nodes/HTTP_Server_CLI/Response/Resources/tests/fixtures/secure/kv/.env';

      $missing = array_keys(array_filter($readmitted, static fn (string $path): bool
         => is_file($path) === false));

      yield (new Assertion(
         description: 'Every file the ignore file re-admits actually crossed COPY',
         fallback: 'The Docker context dropped files its `!` negations promise to keep: '
            . json_encode($missing)
      ))
         ->expect($missing)
         ->to->be([])
         ->assert();

      $canaries = [
         'project .env' => BOOTGLY_ROOT_DIR
            . 'projects/H5_Docker_Context/configs/runtime/.env',
         'project .env.production' => BOOTGLY_ROOT_DIR
            . 'projects/H5_Docker_Context/configs/runtime/.env.production',
         'project .codex credentials' => BOOTGLY_ROOT_DIR
            . 'projects/H5_Docker_Context/.codex/credentials.json',
         'private script' => BOOTGLY_ROOT_DIR . 'scripts/_h5-docker-context-secret.php',
         // ! The shapes git ignores wholesale — internal reports, unreleased
         //   entities, examples, notes, local scratch work. Each is a
         //   DIRECTORY or a glob in the ignore file, so a canary per shape is
         //   what keeps the whole class watched instead of four named paths.
         //   Each canary is matched by exactly ONE pattern: a `docs/` canary
         //   that also ends in `.md` would let `**/docs` be deleted silently.
         'project docs' => BOOTGLY_ROOT_DIR . 'projects/H5_Docker_Context/docs/report.txt',
         'project unreleased entity' => BOOTGLY_ROOT_DIR . 'projects/H5_Docker_Context/&/Draft.php',
         'project markdown' => BOOTGLY_ROOT_DIR . 'projects/H5_Docker_Context/NOTES.md',
         'project scratch test' => BOOTGLY_ROOT_DIR
            . 'projects/H5_Docker_Context/tests/_canary.Test.php',
         'project scratch example' => BOOTGLY_ROOT_DIR
            . 'projects/H5_Docker_Context/_b.example.php',
         'project scratch benchmark' => BOOTGLY_ROOT_DIR
            . 'projects/H5_Docker_Context/_c.benchmark.php',
         'project shell script' => BOOTGLY_ROOT_DIR
            . 'projects/H5_Docker_Context/scripts/canary.sh',
         // ! Opportunistic, not a planted canary: a tracked file at this exact
         //   path would collide with the local interpreter config it stands
         //   for. It watches the pattern on every checkout that HAS one.
         'local php.ini' => BOOTGLY_ROOT_DIR . '@/php.ini',
      ];
      $present = array_keys(array_filter($canaries, is_file(...)));

      yield (new Assertion(
         description: 'Secret-shaped canaries do not cross the Docker COPY boundary',
         fallback: 'CONFIRMED H5: Dockerfile build context copied secret-shaped '
            . 'canaries into the runtime image: ' . json_encode($present)
      ))
         ->expect($present)
         ->to->be([])
         ->assert();
   }
);
