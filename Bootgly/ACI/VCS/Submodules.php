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
use function file_exists;
use function is_file;
use function preg_match;
use Closure;


/**
 * The submodules of one working tree.
 */
final class Submodules
{
   // * Config
   public private(set) Git $Git;


   public function __construct (Git $Git)
   {
      // * Config
      $this->Git = $Git;
   }

   /**
    * List the submodules `.gitmodules` declares.
    *
    * @return array<string,string> Submodule name => its path in the tree.
    */
   public function list (): array
   {
      // ?
      if (is_file("{$this->Git->path}/.gitmodules") === false) {
         return [];
      }

      $declared = $this->Git->query([
         'config', '--file', '.gitmodules', '--get-regexp', '^submodule\..*\.path$',
      ]);
      // ?
      if ($declared === null || $declared === '') {
         return [];
      }

      // @@ `submodule.<name>.path <path>` — a name may itself carry dots
      $submodules = [];
      foreach (explode("\n", $declared) as $line) {
         if (preg_match('/^submodule\.(.+)\.path (.+)$/', $line, $matches) === 1) {
            $submodules[$matches[1]] = $matches[2];
         }
      }

      // :
      return $submodules;
   }

   /**
    * Inspect one submodule: what the index pins, what HEAD's tree records,
    * where the checkout stands and whether it carries changes.
    *
    * The three commits agree on a submodule nobody touched. A staged gitlink
    * (`pinned` ≠ `committed`) is a pin being replaced; a checkout on another
    * commit (`head` ≠ `pinned`) is a submodule someone moved; either one is
    * theirs, not a tool's to overwrite.
    *
    * @param string $path The submodule path in the tree.
    *
    * @return array{path:string,pinned:null|string,committed:null|string,head:null|string,initialized:bool,registered:bool,changes:null|array<string,string>}
    *         `changes` is null when the submodule could not be inspected; `registered` says
    *         whether `.git/config` knows the submodule (`git submodule init` ran for it) — a
    *         directory that merely exists at the path is not that submodule.
    */
   public function inspect (string $path): array
   {
      // ! Registered: `.git/config` carries its URL — what `submodule update` (no --init) acts on
      $registered = false;
      foreach ($this->list() as $name => $declared) {
         if ($declared === $path) {
            $URL = $this->Git->query(['config', '--get', "submodule.{$name}.url"]);
            $registered = $URL !== null && $URL !== '';

            break;
         }
      }

      // ! The index — what `git submodule update` follows
      $pinned = null;
      $entry = $this->Git->query(['ls-files', '-s', '--', $path]);
      if ($entry !== null && preg_match('/^160000 ([0-9a-f]{40,64})/', $entry, $matches) === 1) {
         $pinned = $matches[1];
      }

      // ! The HEAD tree — a gitlink is a bare hash there, present or not
      $committed = $this->Git->query(['rev-parse', '--verify', '--quiet', "HEAD:{$path}"]);
      if ($committed === '' || ($committed !== null && preg_match('/^[0-9a-f]{40,64}$/', $committed) !== 1)) {
         $committed = null;
      }

      // ! The checkout — only an initialized submodule has one
      $initialized = file_exists("{$this->Git->path}/{$path}/.git");
      $head = null;
      $changes = [];
      if ($initialized === true) {
         $Inner = new Git("{$this->Git->path}/{$path}", $this->Git->binary);
         $head = $Inner->resolve('HEAD');
         $changes = $Inner->inspect();
      }

      // :
      return [
         'path' => $path,
         'pinned' => $pinned,
         'committed' => $committed,
         'head' => $head,
         'initialized' => $initialized,
         'registered' => $registered,
         'changes' => $changes,
      ];
   }

   /**
    * Move every initialized submodule to the commit the index pins.
    *
    * No `--init`: a submodule the user never set up (or removed) stays that
    * way — updating is not the moment to add a platform.
    *
    * @param null|Closure $Callback Receives each output line as it arrives.
    *
    * @return int The exit status.
    */
   public function update (null|Closure $Callback = null): int
   {
      // ! The update fetches inside a submodule whose pin is not local yet — under the same deadline as every transfer
      return $this->Git->execute([...Git::DEADLINE, 'submodule', 'update'], $Callback);
   }
}
