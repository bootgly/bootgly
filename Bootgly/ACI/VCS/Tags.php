<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\VCS;


use function count;
use function explode;
use function preg_match;
use function strcmp;
use function trim;
use function uasort;

use Bootgly\ABI\Data\SemVer;


/**
 * The tags of one working tree that name versions.
 *
 * Read from the refs themselves (`for-each-ref`), never from `git tag` or
 * `git describe`: the first columnizes under `column.ui`, the second appends
 * a `-<n>-g<hash>` suffix that the SemVer grammar accepts as a pre-release.
 */
final class Tags
{
   // * Config
   public private(set) Git $Git;


   public function __construct (Git $Git)
   {
      // * Config
      $this->Git = $Git;
   }

   /**
    * List the tags that parse as semantic versions, newest first.
    *
    * An annotated tag is peeled to the commit it points at; a tag on
    * anything but a commit is left out.
    *
    * @return array<string,array{SemVer:SemVer,commit:string,annotated:bool}> Tag name => its version and commit.
    */
   public function list (): array
   {
      $status = $this->Git->execute([
         'for-each-ref',
         '--format=%(refname:strip=2)%00%(objecttype)%00%(objectname)%00%(*objecttype)%00%(*objectname)',
         'refs/tags',
      ]);
      // ?
      if ($status !== 0) {
         return [];
      }

      // @@
      $tags = [];
      foreach (explode("\n", trim($this->Git->output)) as $line) {
         $fields = explode("\0", $line);
         if (count($fields) !== 5) {
            continue;
         }
         [$name, $type, $object, $peeled, $target] = $fields;

         $SemVer = SemVer::parse($name);
         if ($SemVer === null) {
            continue;
         }

         // ! A tag object peels to its target; a lightweight tag is its object
         $commit = match (true) {
            $type === 'commit' => $object,
            $type === 'tag' && $peeled === 'commit' => $target,
            default => null,
         };
         if ($commit === null) {
            continue;
         }

         $tags[$name] = ['SemVer' => $SemVer, 'commit' => $commit, 'annotated' => $type === 'tag'];
      }

      // @ Newest first; equal precedence falls back to the name so the order is total
      uasort($tags, static function (array $a, array $b): int {
         return $b['SemVer']->compare($a['SemVer']) ?: strcmp($a['SemVer'] . '', $b['SemVer'] . '');
      });

      // :
      return $tags;
   }

   /**
    * The tags a remote advertises — its side of the truth, before or after a fetch.
    *
    * A forced fetch corrects the tags the remote also has, but it never
    * removes a local tag the remote never had (one from a fork, a mirror, a
    * `git fetch --all`). Whoever treats tags as releases must intersect with
    * this list, or a tag from anywhere becomes a release from here.
    *
    * @param string $remote The remote name or URL.
    *
    * @return null|array<string,true> Tag name => true; null when the remote could not be reached.
    */
   public function probe (string $remote): null|array
   {
      // ?
      if ($this->Git->execute([...Git::DEADLINE, 'ls-remote', '--tags', '--refs', '--', $remote]) !== 0) {
         return null;
      }

      // @@ `<hash>\trefs/tags/<name>`
      $tags = [];
      foreach (explode("\n", trim($this->Git->output)) as $line) {
         if (preg_match('/^[0-9a-f]{40,64}\trefs\/tags\/(.+)$/', $line, $matches) === 1) {
            $tags[$matches[1]] = true;
         }
      }

      // :
      return $tags;
   }

   /**
    * Read the message of an annotated tag — its release notes.
    *
    * A lightweight tag has no message of its own (its object is the commit),
    * so it yields an empty string rather than the commit's message, which
    * would read as notes it is not.
    *
    * @param string $tag The tag name.
    *
    * @return string The message without a signature, trimmed; empty when there is none.
    */
   public function read (string $tag): string
   {
      $read = $this->Git->query([
         'for-each-ref', '--format=%(objecttype)%0a%(contents:subject)%0a%0a%(contents:body)', "refs/tags/{$tag}",
      ]);
      // ?
      if ($read === null || $read === '') {
         return '';
      }

      [$type, $message] = explode("\n", $read, 2) + [1 => ''];
      // ?:
      if ($type !== 'tag') {
         return '';
      }

      // :
      return trim($message);
   }
}
