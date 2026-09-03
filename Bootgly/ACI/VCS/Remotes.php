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


use function explode;
use function preg_match;
use function preg_replace;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;


/**
 * The remotes of one working tree.
 */
final class Remotes
{
   // * Config
   public private(set) Git $Git;


   public function __construct (Git $Git)
   {
      // * Config
      $this->Git = $Git;
   }

   /**
    * List the remotes with their fetch URLs.
    *
    * @return array<string,string> Remote name => fetch URL.
    */
   public function list (): array
   {
      $names = $this->Git->query(['remote']);
      // ?
      if ($names === null || $names === '') {
         return [];
      }

      // @@
      $remotes = [];
      foreach (explode("\n", $names) as $name) {
         $name = trim($name);
         if ($name === '') {
            continue;
         }

         $URL = $this->Git->query(['remote', 'get-url', $name]);
         if ($URL !== null) {
            $remotes[$name] = $URL;
         }
      }

      // :
      return $remotes;
   }

   /**
    * Find the remote pointing at a repository, whatever the spelling of its URL.
    *
    * `https://github.com/bootgly/bootgly.kit`, `git@github.com:bootgly/bootgly.kit.git`
    * and `ssh://git@github.com/bootgly/bootgly.kit/` all name the same repository.
    *
    * @param string $URL The repository URL (or local path) to look for.
    *
    * @return null|string The remote name, or null when no remote points there.
    */
   public function find (string $URL): null|string
   {
      $wanted = self::normalize($URL);
      // ?
      if ($wanted === '') {
         return null;
      }

      // @@
      foreach ($this->list() as $name => $candidate) {
         if (self::normalize($candidate) === $wanted) {
            return $name;
         }
      }

      // :
      return null;
   }

   /**
    * Add a remote.
    *
    * @param string $name The remote name.
    * @param string $URL Its URL.
    *
    * @return bool True when git accepted it.
    */
   public function add (string $name, string $URL): bool
   {
      return $this->Git->execute(['remote', 'add', $name, $URL]) === 0;
   }

   /**
    * Reduce a repository URL to a comparable `host/path` (or a local path).
    *
    * Scheme, user, port, a trailing slash and a trailing `.git` are dropped;
    * the result is lowercased — hosting services do not distinguish case in
    * an owner or repository name.
    *
    * @param string $URL
    *
    * @return string The normalized form; empty when the URL is empty.
    */
   public static function normalize (string $URL): string
   {
      $URL = trim($URL);

      // ! `file://` is a local path in disguise
      if (preg_match('#^file://#i', $URL) === 1) {
         $URL = substr($URL, 7);
      }
      // ! `user@host:path` (scp-like) and `scheme://[user@]host[:port]/path` both reduce to `host/path`
      elseif (preg_match('#^[a-z][a-z0-9+.-]*://(?:[^@/]+@)?([^/:]+)(?::\d+)?/(.*)$#i', $URL, $matches) === 1) {
         $URL = "{$matches[1]}/{$matches[2]}";
      }
      elseif (preg_match('#^(?:[^@/]+@)?([^/:]+):(?!//)(.*)$#', $URL, $matches) === 1) {
         $URL = "{$matches[1]}/{$matches[2]}";
      }
      // ? Anything else is a local path — and only an absolute one is comparable:
      //   a bare `github.com/owner/repo` is a directory, not the repository it spells
      elseif (str_starts_with($URL, '/') === false) {
         return '';
      }

      $URL = rtrim($URL, '/');
      if (str_ends_with($URL, '.git') === true) {
         $URL = substr($URL, 0, -4);
      }
      $URL = preg_replace('#/{2,}#', '/', $URL) ?? $URL;

      // :
      return strtolower(rtrim($URL, '/'));
   }
}
