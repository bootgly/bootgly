<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

// Bootgly CI — tracked paths that collide on a case-insensitive checkout.
//
// macOS (APFS) and Windows check a tree out case-insensitively. Two tracked
// files whose paths differ only by case collapse into one: refused. A file
// and a directory whose names differ only by case cannot both exist either —
// one of them is never checked out: reported (the launcher `bootgly` beside
// the framework's `Bootgly/` is one, and the macOS cell checks the tree out on
// a case-sensitive volume until it is resolved). Two directories that differ
// only by case merge, losing nothing: let through.
//
//    usage: git ls-files | php .github/scripts/collisions.php

// ! Every tracked file, and every directory above one, by case-folded path
$nodes = [];
while (($line = fgets(STDIN)) !== false) {
   $path = rtrim($line, "\n");
   if ($path === '') {
      continue;
   }
   $parts = explode('/', $path);
   $prefix = '';
   foreach ($parts as $at => $part) {
      $prefix = $prefix === '' ? $part : "{$prefix}/{$part}";
      $kind = $at === count($parts) - 1 ? 'file' : 'directory';
      $nodes[strtolower($prefix)][$prefix] = $kind;
   }
}

// ---

// @ Names that more than one spelling of a path claims
$files = [];
$mixed = [];
foreach ($nodes as $spellings) {
   if (count($spellings) < 2) {
      continue;
   }
   $kinds = array_count_values($spellings);
   $listed = implode(', ', array_map(
      static fn (string $spelling, string $kind): string => "{$spelling} ({$kind})",
      array_keys($spellings),
      $spellings
   ));
   if (($kinds['file'] ?? 0) > 1) {
      $files[] = $listed;
   }
   else if (($kinds['file'] ?? 0) === 1) {
      $mixed[] = $listed;
   }
}

foreach ($mixed as $collision) {
   echo "::warning::a file and a directory collide on a case-insensitive checkout: {$collision}\n";
}
foreach ($files as $collision) {
   echo "::error::tracked files collide on a case-insensitive checkout: {$collision}\n";
}

// :
exit($files === [] ? 0 : 1);
