<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handlers\File;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * A File sink never appends THROUGH a link — and decides on the inode it opened.
 *
 * `fopen()` follows a link at the destination, so a link planted at
 * `storage/logs/<channel>.log` turns the next record into an append to a path
 * somebody else chose — by whoever runs the handler. The handler refuses
 * anything but a regular file with a single name, verified on the handle it
 * opened, creates a missing file beside the destination and `link()`s it into
 * place (never through a dangling link), and reports the refusal.
 */
return new Test(
   description: 'File handler refuses links, hard links and dangling links at the destination, and creates missing files without following anything',
   test: function () {
      $dir = Temporaries::reserve('logs-symlink');
      $target = "$dir/target.txt";
      $link = "$dir/planted.log";
      $plain = "$dir/plain.log";
      $fresh = "$dir/fresh/created.log";
      $dangling = "$dir/dangling.log";
      $nowhere = "$dir/nowhere.txt";
      $two = "$dir/two.log";
      $alias = "$dir/two-alias.log";

      try {
         // @@ A) A link to an existing file: refused, target intact, link kept
         file_put_contents($target, "untouched\n");
         symlink($target, $link);

         $Planted = new File($link);
         $refused = $Planted->handle(new Record(Levels::Info, 'Web', 'through-the-link'));

         yield assert(
            assertion: $refused === false
               && file_get_contents($target) === "untouched\n"
               && is_link($link) === true,
            description: 'a link at the destination is refused: the handler reports false, '
               . 'the target keeps its bytes, and the link is left as found'
         );

         // @@ B) A regular file: written
         file_put_contents($plain, '');
         $Plain = new File($plain);
         $written = $Plain->handle(new Record(Levels::Info, 'Web', 'to-a-file'));

         yield assert(
            assertion: $written === true
               && is_file($plain) === true
               && str_contains((string) file_get_contents($plain), 'to-a-file'),
            description: 'a regular file at the destination is written as before'
         );

         // @@ C) An absent file: created as a regular file, written, no leftovers
         $Fresh = new File($fresh);
         $created = $Fresh->handle(new Record(Levels::Info, 'Web', 'first-record'));
         $entries = array_diff((array) scandir("$dir/fresh"), ['.', '..']);

         yield assert(
            assertion: $created === true
               && is_link($fresh) === false
               && is_file($fresh) === true
               && str_contains((string) file_get_contents($fresh), 'first-record')
               && $entries === [2 => 'created.log'],
            description: 'a missing file is created as a plain file and written, leaving no temporary beside it'
         );

         // @@ D) A dangling link: refused, and the target is NOT created
         symlink($nowhere, $dangling);
         $Dangling = new File($dangling);
         $followed = $Dangling->handle(new Record(Levels::Info, 'Web', 'through-a-dangling-link'));

         yield assert(
            assertion: $followed === false
               && file_exists($nowhere) === false
               && is_link($dangling) === true,
            description: 'a dangling link is refused and its target is never created'
         );

         // @@ E) A file with two names: refused while the second name exists, written once it is gone
         file_put_contents($two, "kept\n");
         link($two, $alias);
         $Two = new File($two);
         $shared = $Two->handle(new Record(Levels::Info, 'Web', 'two-names'));
         unlink($alias);
         $alone = $Two->handle(new Record(Levels::Info, 'Web', 'one-name'));
         $content = (string) file_get_contents($two);

         yield assert(
            assertion: $shared === false
               && $alone === true
               && str_contains($content, 'two-names') === false
               && str_contains($content, 'one-name'),
            description: 'a hard-linked file is refused until it has a single name again'
         );

         // @@ F) A link on the WAY (a linked directory) is fine for an unprivileged
         //       writer: the file is created and appended through it, in the real directory
         mkdir("$dir/real");
         symlink("$dir/real", "$dir/linked");
         $Linked = new File("$dir/linked/through.log");
         $created = $Linked->handle(new Record(Levels::Info, 'Web', 'created-through-a-linked-dir'));
         $appended = $Linked->handle(new Record(Levels::Info, 'Web', 'appended-through-a-linked-dir'));
         $through = (string) @file_get_contents("$dir/real/through.log");

         yield assert(
            assertion: $created === true
               && $appended === true
               && is_file("$dir/real/through.log") === true
               && str_contains($through, 'created-through-a-linked-dir')
               && str_contains($through, 'appended-through-a-linked-dir')
               && array_diff((array) scandir("$dir/real"), ['.', '..']) === [2 => 'through.log'],
            description: 'a linked directory on the way is followed by an unprivileged writer: the file is created in the real directory, appended, and no temporary is left'
         );
      }
      finally {
         foreach ([$link, $target, $plain, $fresh, $dangling, $nowhere, $two, $alias, "$dir/real/through.log", "$dir/linked"] as $file) {
            if (is_link($file) === true || is_file($file) === true) {
               unlink($file);
            }
         }
         foreach (["$dir/fresh", "$dir/real"] as $folder) {
            if (is_dir($folder) === true) {
               rmdir($folder);
            }
         }
         rmdir($dir);
      }
   }
);
