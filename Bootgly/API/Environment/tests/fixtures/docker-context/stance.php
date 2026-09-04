<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

/**
 * Where this run stands relative to the Docker COPY boundary — shared by the
 * two H5 cases so the gate has ONE definition, not two that can drift.
 *
 * Returns:
 *   contained  — a container runtime left its marker behind
 *   mounted    — the framework root came from a bind mount, not the image
 *   measurable — a container whose framework root IS the image's own COPY
 *   image      — measurable, AND the framework image, AND provenance-stamped
 *   SHA        — the build stamp, as given
 *
 * @return array{contained:bool,mounted:bool,measurable:bool,image:bool,SHA:string}
 */

// ! Containment, read from the runtime's own marker files. `BOOTGLY_DOCKER` is
//   NOT consulted: it is a wording hint for the `kit` refusal, and one
//   `docker run -e BOOTGLY_DOCKER=1` — or a host that exports it — would arm a
//   security verdict from outside. A runtime that leaves no marker costs these
//   canaries their coverage, which is the safe direction for an alarm.
$contained = file_exists('/.dockerenv') === true
          || file_exists('/run/.containerenv') === true;

// ! A bind mount is somebody's own tree, not the image's COPY. `-v .:/bootgly`
//   (devcontainers, `docker run -v` while hacking) puts a live checkout — `.git`
//   and all — where the built framework would be, and these cases have nothing
//   to say about it. `/proc/self/mountinfo` field 5 is the mount point.
//
//   ANY ancestor counts, not just an exact match: a GitHub Actions `container:`
//   job mounts `/home/runner/work` at `/__w` and checks the repository out at
//   `/__w/<repo>/<repo>`, so the framework root is UNDER the mount and never
//   equal to it. Field 5 escapes space, tab, newline and backslash as octal, so
//   unescape before comparing or a mount point with a space never matches.
$root = rtrim(BOOTGLY_ROOT_DIR, '/');
$mounted = false;
$mountinfo = @file_get_contents('/proc/self/mountinfo');
if (is_string($mountinfo) === true) {
   foreach (explode("\n", $mountinfo) as $line) {
      $fields = explode(' ', $line);
      if (isSet($fields[4]) === false) {
         continue;
      }

      $point = rtrim(
         strtr($fields[4], ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']),
         '/'
      );

      // ? `/` is every path's ancestor and is the image's own root filesystem
      if ($point === '') {
         continue;
      }

      if ($point === $root || str_starts_with("{$root}/", "{$point}/") === true) {
         $mounted = true;

         break;
      }
   }
}

// ! What these cases can measure at all: a container whose framework root came
//   from the image itself.
$measurable = $contained === true && $mounted === false;

// ! Only the FRAMEWORK image has the COPY boundary: it copies the repository
//   through `.dockerignore`. The kit image git-clones the same tree — no COPY,
//   no ignore file, and the canaries legitimately arrive, exactly as they do in
//   a kit installed by `curl … | bash`.
//
//   The signal is STRUCTURAL, never an environment variable: in the framework
//   image the framework IS the working base, while a kit nests it under
//   `Bootgly/` (the same idiom `TestCommand` uses to tell the two apart). An
//   env-based gate would be one `docker run -e` away from either crying
//   CONFIRMED inside a kit or silently skipping inside the framework image.
$SHA = (string) getenv('BOOTGLY_FRAMEWORK_SHA');

$image = $measurable
      && BOOTGLY_ROOT_DIR === BOOTGLY_WORKING_DIR
      && file_exists(BOOTGLY_ROOT_DIR . '.git') === false
      && is_file(BOOTGLY_ROOT_DIR . 'Dockerfile')
      // ! The stamp is what the control assertion demands. A build without the
      //   provenance args is legitimate — it just cannot prove the boundary, so
      //   the case that needs it SKIPS (visibly) instead of failing or, worse,
      //   passing over assertions it never ran.
      && preg_match('/^[a-f0-9]{40}$/D', $SHA) === 1;

// :
return [
   'contained' => $contained,
   'mounted' => $mounted,
   'measurable' => $measurable,
   'image' => $image,
   'SHA' => $SHA,
];
