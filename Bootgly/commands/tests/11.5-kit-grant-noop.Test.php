<?php
namespace Bootgly\commands;


use function array_diff;
use function assert;
use function file_put_contents;
use function filegroup;
use function fileowner;
use function function_exists;
use function getenv;
use function lchgrp;
use function mkdir;
use function posix_getegid;
use function posix_getgroups;
use function putenv;
use function rmdir;
use function symlink;
use function unlink;
use ReflectionMethod;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * `KitCommand::grant()` is inert outside the image, for a non-root caller and
 * for a path outside the kit's own `projects/`; `KitCommand::hand()` — the
 * walk it delegates to — changes the owner of what is under the tree and
 * never of what a link points at. The handover itself needs a second group
 * to be observable, so a single-group host skips instead of passing blind.
 */
return new Test(
   description: '`grant()` is inert outside the image; `hand()` re-owns the tree and never follows a link',
   skip: function_exists('posix_getgroups') === false
      || array_diff(posix_getgroups(), [posix_getegid()]) === [],
   test: function () {
      $dir = Temporaries::reserve('kit-grant');
      mkdir("{$dir}/projects/App", 0o755, true);
      mkdir("{$dir}/outside", 0o755, true);
      file_put_contents("{$dir}/projects/App/file", 'x');
      file_put_contents("{$dir}/outside/target", 'y');
      symlink("{$dir}/outside/target", "{$dir}/projects/App/link");
      symlink("{$dir}/outside", "{$dir}/projects/App/linkdir");
      $owner = fileowner("{$dir}/projects/App/file");
      $previous = getenv('BOOTGLY_DOCKER');

      try {
         // @ grant(): outside the image — a tree and a file alike
         putenv('BOOTGLY_DOCKER');
         KitCommand::grant("{$dir}/projects/App");
         KitCommand::grant("{$dir}/projects/App/file");

         yield assert(
            assertion: fileowner("{$dir}/projects/App/file") === $owner,
            description: 'outside the image nothing changes hands'
         );

         // @ grant(): inside the image, but not root and not under the kit's
         //   projects/ — either alone is enough to keep it inert
         putenv('BOOTGLY_DOCKER=1');
         KitCommand::grant("{$dir}/projects/App");

         yield assert(
            assertion: fileowner("{$dir}/projects/App/file") === $owner,
            description: 'a non-root caller never chowns (the euid guard, first of the conjunction)'
         );

         // @ hand(): a group this user may hand files to, other than the
         //   file's — proven usable on a throwaway first: a runtime can list a
         //   supplementary group it cannot chgrp to (an incomplete gid map)
         $GID = filegroup("{$dir}/projects/App/file");
         $other = null;
         file_put_contents("{$dir}/probe", 'p');
         foreach (posix_getgroups() as $candidate) {
            if ($candidate !== $GID && @lchgrp("{$dir}/probe", $candidate) === true) {
               $other = $candidate;
               break;
            }
         }
         if ($other === null) {
            yield assert(assertion: true, description: 'Skipped: no second group this runtime can hand the tree to');
            return;
         }
         $Hand = new ReflectionMethod(KitCommand::class, 'hand');
         $Hand->invoke(null, "{$dir}/projects/App", $owner, $other);

         yield assert(
            assertion: filegroup("{$dir}/projects/App/file") === $other,
            description: 'a file under the tree changes group'
         );
         yield assert(
            assertion: filegroup("{$dir}/outside/target") === $GID,
            description: 'the target of a link inside the tree keeps its group — the link was not followed'
         );
         yield assert(
            assertion: filegroup("{$dir}/outside") === $GID,
            description: 'a linked directory is neither followed nor descended into'
         );
      }
      finally {
         if ($previous === false) {
            putenv('BOOTGLY_DOCKER');
         }
         else {
            putenv("BOOTGLY_DOCKER={$previous}");
         }
         @unlink("{$dir}/projects/App/link");
         @unlink("{$dir}/projects/App/linkdir");
         @unlink("{$dir}/projects/App/file");
         @unlink("{$dir}/probe");
         @unlink("{$dir}/outside/target");
         @rmdir("{$dir}/projects/App");
         @rmdir("{$dir}/projects");
         @rmdir("{$dir}/outside");
         @rmdir($dir);
      }
   }
);
