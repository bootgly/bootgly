<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client;


use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_SH;
use function array_diff;
use function array_values;
use function basename;
use function bin2hex;
use function chmod;
use function clearstatcache;
use function count;
use function dirname;
use function explode;
use function fclose;
use function fflush;
use function file_get_contents;
use function flock;
use function fopen;
use function fstat;
use function fsync;
use function ftruncate;
use function function_exists;
use function fwrite;
use function hash_equals;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function link;
use function lstat;
use function mkdir;
use function posix_geteuid;
use function preg_match;
use function random_bytes;
use function rename;
use function rewind;
use function rmdir;
use function rtrim;
use function scandir;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function substr;
use function time;
use function trim;
use function umask;
use function unlink;
use function usleep;
use InvalidArgumentException;
use JsonException;
use Throwable;


/**
 * Generation-aware hot-swap rendezvous, namespaced per running server.
 *
 * The certifier/master atomically publishes one desired generation. Each worker
 * atomically acknowledges the exact generation and hashes it validated/applied.
 *
 * The rendezvous identity is random and fork-inherited when the server is
 * configured; `<base>/<instance>/` itself is materialized lazily by the first
 * demoted publisher. Unrelated servers sharing one storage base (even for the
 * same SAN set) can therefore never clobber each other's desired/applied
 * attempts or acknowledgements.
 *
 * A sibling `<instance>.owner.lock` carries the namespace lifetime. The creator
 * and every same-instance process hold a shared kernel lock; forked processes may
 * inherit its descriptor, while workers forked before first publication join the
 * exact inode independently. A later server may reclaim the bound tree only while
 * it holds that lease exclusively, which proves every owner copy is gone. This
 * deliberately does not use a publisher PID: ACME publishers may be short-lived
 * children of a server whose namespace remains live.
 *
 * @phpstan-type FileStatus array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,...}
 */
final class Swaps
{
   private const string MARKER = 'owner.token';
   private const string TEMPORARY = '/^\.owner-([a-f0-9]{64})\.tmp$/D';

   public private(set) string $base;
   public private(set) string $instance;
   public private(set) string $path;
   private string $lease;
   /** @var null|resource */
   private mixed $Lease = null;
   /** An explicit release is terminal for this process/object copy. */
   private bool $released = false;


   public function __construct (string $base, string $instance)
   {
      $base = rtrim($base, '/') . '/';
      if (
         $base === '/'
         || str_starts_with($base, '/') === false
         || str_contains($base, '/../')
         || str_contains($base, '/./')
      ) {
         throw new InvalidArgumentException('Auto-TLS swap path must be a dedicated absolute directory.');
      }
      if (preg_match('/^[a-f0-9]{16}$/', $instance) !== 1) {
         throw new InvalidArgumentException('Auto-TLS swap instance must be a 16-hex namespace.');
      }
      $this->base = $base;
      $this->instance = $instance;
      $this->path = "{$base}{$instance}/";
      $this->lease = "{$base}{$instance}.owner.lock";
   }

   public function request (CertificateSnapshot $Snapshot): null|string
   {
      if ($this->enter(true) === false) {
         return null;
      }

      $attempt = bin2hex(random_bytes(16));
      if ($this->write("{$this->path}request.json", [
         'attempt' => $attempt,
         'generation' => $Snapshot->generation,
         'certificateHash' => $Snapshot->certificateHash,
         'keyHash' => $Snapshot->keyHash,
         'requested' => time()
      ]) === false) {
         return null;
      }

      // Publication is the production collection trigger. The current lease
      // is already held, and enter() refuses privileged traversal through a
      // runtime-owned tree before this shared-base scan can execute.
      $this->reap();

      return $attempt;
   }

   /**
    * Release only this process' descriptor copy.
    *
    * Never issue LOCK_UN: forked copies share one open-file description, so an
    * explicit unlock in a helper or an old re-exec image could release the live
    * server's lease. The kernel drops the lock after the final copy is closed.
    */
   public function release (): void
   {
      if (is_resource($this->Lease)) {
         fclose($this->Lease);
      }
      $this->Lease = null;
      $this->released = true;
   }

   /**
    * Collect only namespaces whose owner lock is provably free.
    *
    * Acquisition enforces the privilege boundary before this shared-base walk.
    */
   public function sweep (): void
   {
      if ($this->enter(false)) {
         $this->reap();
      }
   }

   /** Join an initialized namespace, or create it only for a publisher. */
   private function enter (bool $create): bool
   {
      if ($this->authorize() === false) {
         return false;
      }
      if (is_resource($this->Lease)) {
         return true;
      }
      if ($this->released) {
         return false;
      }

      // A creator exposes the lease before its marker/request commit. Readers
      // never mutate that partial state: bounded retries let the creator finish
      // without converting a half-built namespace into trusted ownership.
      for ($attempt = 0; $attempt < 50; $attempt++) {
         if ($this->join()) {
            return true;
         }
         if (
            $create
            && $this->inspect($this->lease) === false
            && $this->inspect(rtrim($this->path, '/')) === false
            && $this->claim()
         ) {
            return true;
         }
         if ($this->inspect($this->lease) === false && $create === false) {
            return false;
         }
         usleep(1000);
      }

      return false;
   }

   /** Refuse every pathname operation while the caller still has UID 0. */
   private function authorize (): bool
   {
      return posix_geteuid() !== 0;
   }

   /** Create and bind one new namespace while retaining its shared lease. */
   private function claim (): bool
   {
      if (
         $this->authorize() === false
         || $this->validate($this->base, true) === false
      ) {
         return false;
      }
      if (
         is_dir($this->base) === false
         && @mkdir($this->base, 0700, true) === false
         && is_dir($this->base) === false
      ) {
         return false;
      }
      if (
         posix_geteuid() === 0
         || $this->validate($this->base, true) === false
         || @chmod($this->base, 0700) === false
         || $this->inspect($this->lease) !== false
         || $this->inspect(rtrim($this->path, '/')) !== false
      ) {
         return false;
      }

      $previousMask = umask(0077);
      try {
         $Handle = @fopen($this->lease, 'x+b');
      }
      finally {
         umask($previousMask);
      }
      if ($Handle === false) {
         return false;
      }

      $committed = false;
      $created = false;
      $directory = false;
      $marker = "{$this->path}" . self::MARKER;
      try {
         if (@chmod($this->lease, 0600) === false) {
            return false;
         }
         $before = $this->inspect($this->lease);
         $opened = fstat($Handle);
         if (
            $this->verify($before, false) === false
            || $this->verify($opened, false) === false
            || $before['dev'] !== $opened['dev']
            || $before['ino'] !== $opened['ino']
            || flock($Handle, LOCK_SH | LOCK_NB) === false
         ) {
            return false;
         }
         $token = bin2hex(random_bytes(32));
         if ($this->encode($Handle, $token) === false) {
            return false;
         }
         clearstatcache(true, $this->lease);
         $after = $this->inspect($this->lease);
         if (
            $this->verify($after, false) === false
            || $after['dev'] !== $opened['dev']
            || $after['ino'] !== $opened['ino']
         ) {
            return false;
         }
         if (@mkdir($this->path, 0700) === false) {
            return false;
         }
         $created = true;
         if (
            $this->validate($this->path, true) === false
            || @chmod($this->path, 0700) === false
         ) {
            return false;
         }
         $directory = $this->inspect(rtrim($this->path, '/'));
         if ($this->verify($directory, true) === false) {
            return false;
         }
         if ($this->publish($marker, $token) === false) {
            return false;
         }

         // link() inside publish() is the one irreversible commit point. From
         // here a same-instance reader may already hold its own shared lease;
         // never roll the pair back after making owner.token visible.
         $this->Lease = $Handle;
         $committed = true;
         return true;
      }
      finally {
         if ($committed === false) {
            $clear = $created === false;
            if ($created) {
               $currentDirectory = $this->inspect(rtrim($this->path, '/'));
               if (
                  is_array($directory)
                  && is_array($currentDirectory)
                  && $directory['dev'] === $currentDirectory['dev']
                  && $directory['ino'] === $currentDirectory['ino']
                  && $this->scan($this->path) === []
                  && @rmdir($this->path)
               ) {
                  $clear = true;
               }
               else if ($currentDirectory === false) {
                  $clear = true;
               }
            }
            // Keep the SH lock through pathname removal. If an ambiguous tree
            // appeared, preserve both halves rather than exposing a lockless
            // namespace that a later process could misclassify.
            if ($clear) {
               $this->discard($Handle, $this->lease);
            }
            fclose($Handle);
         }
      }

   }

   /** Join the exact committed lease/namespace pair without creating paths. */
   private function join (): bool
   {
      if (
         $this->released
         || $this->validate($this->lease, true) === false
         || $this->validate($this->path, true) === false
         || is_link($this->lease)
         || is_link($this->path)
      ) {
         return false;
      }
      $before = $this->inspect($this->lease);
      $directory = $this->inspect(rtrim($this->path, '/'));
      if (
         $this->verify($before, false) === false
         || $this->verify($directory, true) === false
      ) {
         return false;
      }

      $Handle = @fopen($this->lease, 'rb');
      if ($Handle === false) {
         return false;
      }
      $joined = false;
      try {
         $opened = fstat($Handle);
         if (
            $this->verify($opened, false) === false
            || $before['dev'] !== $opened['dev']
            || $before['ino'] !== $opened['ino']
            || flock($Handle, LOCK_SH | LOCK_NB) === false
         ) {
            return false;
         }
         clearstatcache(true, $this->lease);
         clearstatcache(true, $this->path);
         $after = $this->inspect($this->lease);
         $current = $this->inspect(rtrim($this->path, '/'));
         $token = $this->decode($Handle);
         if (
            $this->verify($after, false) === false
            || $this->verify($current, true) === false
            || $after['dev'] !== $opened['dev']
            || $after['ino'] !== $opened['ino']
            || $current['dev'] !== $directory['dev']
            || $current['ino'] !== $directory['ino']
            || $token === null
            || $this->match($this->path, $token) === false
         ) {
            return false;
         }

         $this->Lease = $Handle;
         $joined = true;
         return true;
      }
      finally {
         if ($joined === false) {
            fclose($Handle);
         }
      }
   }

   /** Remove legacy artifacts and namespaces whose owner lock is provably free. */
   private function reap (): void
   {
      $names = @scandir($this->base);
      if ($names === false) {
         return;
      }

      foreach ($names as $name) {
         if ($name === '.' || $name === '..') {
            continue;
         }
         $entry = "{$this->base}{$name}";
         if (is_link($entry)) {
            continue;
         }

         // ? Legacy layout — request/applied and generation directories
         //   lived directly on the base before instance namespaces
         if (is_file($entry)) {
            if ($name === 'request.json' || $name === 'applied.json') {
               $this->erase($entry, $this->inspect($entry));
            }
            continue;
         }
         if (is_dir($entry) === false) {
            continue;
         }
         if (preg_match('/^[a-f0-9]{32}$/', $name) === 1) {
            try {
               $target = "{$this->base}.legacy-{$name}-" . bin2hex(random_bytes(8));
            }
            catch (Throwable) {
               continue;
            }
            if ($this->move($entry, $target)) {
               $this->remove($target);
            }
            continue;
         }
         // A renamed legacy tree stays this collector's responsibility. The
         // rename above is its one irreversible step and nothing else in the
         // base carries this name, so ignoring it turns an interrupted removal
         // — SIGKILL, ENOSPC, a shutdown deadline — into a permanent orphan.
         // Removing in place used to be resumed by the next sweep; renaming
         // first is only safe while the renamed form is collected too.
         if (preg_match('/^\.legacy-[a-f0-9]{32}-[a-f0-9]{16}$/', $name) === 1) {
            $this->remove($entry);
            continue;
         }
         if (
            preg_match('/^[a-f0-9]{16}$/', $name) === 1
            && $name !== $this->instance
         ) {
            $this->reclaim($entry, $name);
            continue;
         }
         if (preg_match('/^\.gc-([a-f0-9]{16})-[a-f0-9]{16}$/', $name, $matches) === 1) {
            $instance = $matches[1];
            if ($instance !== $this->instance) {
               $this->reclaim($entry, $instance, true);
            }
         }
      }

      // A process can die after creating its lease or namespace but before the
      // atomic marker commit. Recover only structurally provable partial claims;
      // every ambiguous/mismatched tree is preserved for offline inspection.
      foreach ($names as $name) {
         if (preg_match('/^([a-f0-9]{16})\.owner\.lock$/', $name, $matches) !== 1) {
            continue;
         }
         $instance = $matches[1];
         if ($instance === $this->instance) {
            continue;
         }
         $this->recover($instance);
      }
   }

   /**
    * Return exact non-dot entries, or false when the directory is unreadable.
    *
    * @return list<string>|false
    * @phpstan-impure Filesystem contents can change between observations.
    */
   private function scan (string $directory): array|false
   {
      if ($this->validate($directory, true) === false || is_link($directory)) {
         return false;
      }
      $names = @scandir($directory);
      if ($names === false) {
         return false;
      }

      return array_values(array_diff($names, ['.', '..']));
   }

   /** Recover an exclusively owned, structurally uncommitted claim or GC tail. */
   private function recover (string $instance): void
   {
      $lease = "{$this->base}{$instance}.owner.lock";
      $Handle = $this->seize($instance);
      if ($Handle === null) {
         return;
      }

      try {
         $original = "{$this->base}{$instance}";
         $tombstones = $this->locate($instance);
         // A crash can occur between x+b and the first token flush. With no
         // namespace or tombstone, the exact exclusively locked lease inode is
         // the entire partial claim and can be discarded even if empty.
         if ($this->inspect($original) === false && $tombstones === []) {
            $this->discard($Handle, $lease);
            return;
         }
         if ($this->decode($Handle) === null) {
            return;
         }

         // An original claim can only be incomplete before any request exists.
         // Move that exact private empty/temporary-only tree into the normal GC
         // state first, retaining the exclusive lease through the transition.
         if ($this->inspect($original) !== false && $tombstones === []) {
            $state = $this->classify($original, $Handle);
            if ($state !== 'empty' && $state !== 'partial') {
               return;
            }
            try {
               $target = "{$this->base}.gc-{$instance}-" . bin2hex(random_bytes(8));
            }
            catch (Throwable) {
               return;
            }
            $before = $this->inspect($original);
            if (
               $this->verify($before, true) === false
               || $this->move($original, $target) === false
            ) {
               return;
            }
            $after = $this->inspect($target);
            if (
               $this->verify($after, true) === false
               || $before['dev'] !== $after['dev']
               || $before['ino'] !== $after['ino']
            ) {
               return;
            }
            $tombstones = [$target];
         }

         if ($this->inspect($original) !== false || count($tombstones) !== 1) {
            return;
         }
         $tombstone = $tombstones[0];
         $state = $this->classify($tombstone, $Handle);
         if ($state !== 'empty' && $state !== 'partial') {
            return;
         }
         if ($state === 'partial') {
            $entries = $this->scan($tombstone);
            if ($entries === false || count($entries) !== 1) {
               return;
            }
            $partial = "{$tombstone}/{$entries[0]}";
            $identity = $this->inspect($partial);
            if ($this->erase($partial, $identity) === false) {
               return;
            }
         }
         if ($this->scan($tombstone) !== [] || @rmdir($tombstone) === false) {
            return;
         }
         $this->discard($Handle, $lease);
      }
      finally {
         fclose($Handle);
      }
   }

   /** Classify only proof-safe incomplete states while the lease is exclusive. */
   private function classify (string $directory, mixed $Handle): string
   {
      $status = $this->inspect($directory);
      $names = $this->scan($directory);
      if ($this->verify($status, true) === false || $names === false) {
         return 'ambiguous';
      }
      if ($names === []) {
         return 'empty';
      }
      if (count($names) !== 1) {
         return 'ambiguous';
      }

      $name = $names[0];
      if (preg_match(self::TEMPORARY, $name) === 1) {
         $temporary = "{$directory}/{$name}";
         $identity = $this->inspect($temporary);
         if (
            $this->verify($identity, false) === false
            || is_link($temporary)
            || $identity['size'] > 64
         ) {
            return 'ambiguous';
         }

         return 'partial';
      }
      if ($name !== self::MARKER) {
         return 'ambiguous';
      }

      $marker = "{$directory}/" . self::MARKER;
      $identity = $this->inspect($marker);
      if ($this->verify($identity, false) === false || is_link($marker)) {
         return 'ambiguous';
      }
      $Marker = @fopen($marker, 'rb');
      if ($Marker === false) {
         return 'ambiguous';
      }
      try {
         $opened = fstat($Marker);
         $stored = stream_get_contents($Marker, 65);
         $current = $this->inspect($marker);
         if (
            $this->verify($opened, false) === false
            || $this->verify($current, false) === false
            || $identity['dev'] !== $opened['dev']
            || $identity['ino'] !== $opened['ino']
            || $opened['dev'] !== $current['dev']
            || $opened['ino'] !== $current['ino']
            || is_string($stored) === false
         ) {
            return 'ambiguous';
         }
      }
      finally {
         fclose($Marker);
      }

      $token = $this->decode($Handle);
      if (preg_match('/^[a-f0-9]{64}$/D', $stored) === 1) {
         return $token !== null && hash_equals($token, $stored)
            ? 'bound'
            : 'mismatch';
      }

      // owner.token is published only by an atomic hard link after the full
      // token was flushed. A malformed final marker is therefore foreign or
      // from an older implementation and cannot be deleted safely online.
      return 'ambiguous';
   }

   /** Recursively remove one bounded rendezvous tree (2 levels of dirs). */
   private function remove (string $directory, int $depth = 0): bool
   {
      if (is_link($directory) || is_dir($directory) === false || $depth > 2) {
         return false;
      }
      $names = @scandir($directory);
      if ($names === false) {
         return false;
      }

      foreach ($names as $name) {
         if ($name === '.' || $name === '..') {
            continue;
         }
         // owner.token is the durable binding between a tombstone and the
         // exclusively held lease. Keep it until every other entry is gone so
         // an interrupted collection can always resume safely.
         if ($depth === 0 && (
            $name === self::MARKER
            || preg_match(self::TEMPORARY, $name) === 1
         )) {
            continue;
         }
         $child = "{$directory}/{$name}";
         if (is_link($child)) {
            if (@unlink($child) === false) {
               return false;
            }
            continue;
         }
         if (is_dir($child)) {
            if ($depth >= 2 || $this->remove($child, $depth + 1) === false) {
               return false;
            }
            continue;
         }
         if (@unlink($child) === false) {
            return false;
         }
      }

      if ($depth === 0) {
         foreach ($this->scan($directory) ?: [] as $name) {
            if ($name !== self::MARKER && preg_match(self::TEMPORARY, $name) !== 1) {
               return false;
            }
            if (@unlink("{$directory}/{$name}") === false) {
               return false;
            }
         }
      }

      return @rmdir($directory);
   }

   /** Reclaim one namespace only while its exact owner lease is exclusive. */
   private function reclaim (string $directory, string $instance, bool $tombstone = false): void
   {
      $directory = rtrim($directory, '/');
      $tombstones = $tombstone ? $this->locate($instance) : [];
      if (
         preg_match('/^[a-f0-9]{16}$/', $instance) !== 1
         || $instance === $this->instance
         || $this->validate($directory, true) === false
         || is_link($directory)
         || ($tombstone === false && $this->detect($instance))
         || ($tombstone && (
            $this->inspect("{$this->base}{$instance}") !== false
            || count($tombstones) !== 1
            || $tombstones[0] !== $directory
         ))
      ) {
         return;
      }

      $Handle = $this->seize($instance, $directory);
      if ($Handle === null) {
         return;
      }
      try {
         // Multiple tombstones are ambiguous. Holding the lease exclusively
         // prevents legitimate owners from changing this set while it is
         // inspected, but recheck it so a prior interrupted collector can
         // never orphan a competing tree by discarding their shared lease.
         if ($tombstone) {
            $tombstones = $this->locate($instance);
            if (count($tombstones) !== 1 || $tombstones[0] !== $directory) {
               return;
            }
         }
         // @phpstan-ignore identical.alwaysFalse (intentional post-flock pathname race recheck)
         if ($this->validate($directory, true) === false) {
            return;
         }
         clearstatcache(true, $directory);
         $before = $this->inspect($directory);
         if ($this->verify($before, true) === false) {
            return;
         }

         if ($tombstone === false) {
            try {
               $target = "{$this->base}.gc-{$instance}-" . bin2hex(random_bytes(8));
            }
            catch (Throwable) {
               return;
            }
            if ($this->move($directory, $target) === false) {
               return;
            }
            clearstatcache(true, $target);
            $after = $this->inspect($target);
            if (
               is_array($after) === false
               || $after['dev'] !== $before['dev']
               || $after['ino'] !== $before['ino']
            ) {
               return;
            }
            $directory = $target;
         }

         if ($this->purge($directory, $Handle) === false) {
            return;
         }
         if (
            $this->inspect("{$this->base}{$instance}") !== false
            || $this->locate($instance) !== []
         ) {
            return;
         }
         $this->discard($Handle, "{$this->base}{$instance}.owner.lock");
      }
      finally {
         fclose($Handle);
      }
   }

   /**
    * Exclusively seize one retired lease, optionally bound to its tree.
    *
    * @return resource|null
    */
   private function seize (string $instance, null|string $directory = null): mixed
   {
      $lease = "{$this->base}{$instance}.owner.lock";
      if ($this->validate($lease, true) === false || is_link($lease)) {
         return null;
      }
      $before = $this->inspect($lease);
      if ($this->verify($before, false) === false) {
         return null;
      }

      $Handle = @fopen($lease, 'r+b');
      if ($Handle === false) {
         return null;
      }
      $opened = fstat($Handle);
      if (
         $this->verify($opened, false) === false
         || $before['dev'] !== $opened['dev']
         || $before['ino'] !== $opened['ino']
         || flock($Handle, LOCK_EX | LOCK_NB) === false
      ) {
         fclose($Handle);
         return null;
      }

      clearstatcache(true, $lease);
      $after = $this->inspect($lease);
      if (
         $this->verify($after, false) === false
         || $after['dev'] !== $opened['dev']
         || $after['ino'] !== $opened['ino']
      ) {
         fclose($Handle);
         return null;
      }
      if ($directory !== null) {
         $tree = $this->inspect(rtrim($directory, '/'));
         $token = $this->decode($Handle);
         if (
            $this->validate($directory, true) === false
            || is_link($directory)
            || $this->verify($tree, true) === false
            || $token === null
            || $this->match($directory, $token) === false
         ) {
            fclose($Handle);
            return null;
         }
         clearstatcache(true, $directory);
         $current = $this->inspect(rtrim($directory, '/'));
         if (
            $this->verify($current, true) === false
            || $tree['dev'] !== $current['dev']
            || $tree['ino'] !== $current['ino']
         ) {
            fclose($Handle);
            return null;
         }
      }

      return $Handle;
   }

   /** Remove a bound tombstone while retaining its marker until the last step. */
   private function purge (string $directory, mixed $Handle): bool
   {
      $token = $this->decode($Handle);
      if ($token === null || $this->match($directory, $token) === false) {
         return false;
      }
      $names = $this->scan($directory);
      if ($names === false) {
         return false;
      }
      foreach ($names as $name) {
         if ($name === self::MARKER) {
            continue;
         }
         if (preg_match(self::TEMPORARY, $name) === 1) {
            $temporary = "{$directory}/{$name}";
            $marker = "{$directory}/" . self::MARKER;
            $markerStatus = $this->inspect($marker);
            $temporaryStatus = $this->inspect($temporary);
            if (
               $this->verify($markerStatus, false, true) === false
               || $this->verify($temporaryStatus, false, true) === false
               || $markerStatus['dev'] !== $temporaryStatus['dev']
               || $markerStatus['ino'] !== $temporaryStatus['ino']
               || @unlink($temporary) === false
            ) {
               return false;
            }
            continue;
         }
         $child = "{$directory}/{$name}";
         if (is_link($child)) {
            if (@unlink($child) === false) {
               return false;
            }
            continue;
         }
         if (is_dir($child)) {
            if ($this->remove($child, 1) === false) {
               return false;
            }
            continue;
         }
         if (@unlink($child) === false) {
            return false;
         }
      }

      // Re-establish binding after the destructive walk; then remove the
      // marker last. A crash after this unlink leaves an empty, EX-recoverable
      // tombstone rather than an unauthenticated non-empty tree.
      if (
         $this->scan($directory) !== [self::MARKER]
         || $this->match($directory, $token) === false
      ) {
         return false;
      }
      $marker = "{$directory}/" . self::MARKER;
      if (@unlink($marker) === false || $this->scan($directory) !== []) {
         return false;
      }

      return @rmdir($directory);
   }

   /** Remove a lease pathname only while it still names the locked inode. */
   private function discard (mixed $Handle, string $lease): bool
   {
      clearstatcache(true, $lease);
      $opened = is_resource($Handle) ? fstat($Handle) : false;
      $current = $this->inspect($lease);
      if (
         is_array($opened) === false
         || is_array($current) === false
         || $opened['dev'] !== $current['dev']
         || $opened['ino'] !== $current['ino']
      ) {
         return false;
      }

      return @unlink($lease);
   }

   /** Detect a retained tombstone for an interrupted collection. */
   private function detect (string $instance): bool
   {
      return $this->locate($instance) !== [];
   }

   /**
    * Locate exact real tombstone directories for one instance.
    *
    * @return list<string>
    * @phpstan-impure Filesystem contents can change between observations.
    */
   private function locate (string $instance): array
   {
      // Enumerated, never globbed: the base is operator-configured and a glob
      // metacharacter in it does not merely skip work — a blind lookup reports
      // "no tombstone", which lets recover() discard a lease whose tombstone is
      // still standing. Comparing literal names also keeps the instance out of
      // a pattern it would otherwise be interpolated into.
      $tombstones = [];
      $prefix = ".gc-{$instance}-";
      foreach ($this->scan($this->base) ?: [] as $name) {
         if (
            str_starts_with($name, $prefix)
            && preg_match('/^[a-f0-9]{16}$/D', substr($name, strlen($prefix))) === 1
            && $this->inspect("{$this->base}{$name}") !== false
         ) {
            $tombstones[] = "{$this->base}{$name}";
         }
      }
      sort($tombstones);

      return $tombstones;
   }

   /** Move a tree without replacing a pre-existing tombstone. */
   private function move (string $source, string $target): bool
   {
      return $this->inspect($target) === false && @rename($source, $target);
   }

   /** Persist one fixed-width ownership token on an already-open lease. */
   private function encode (mixed $Handle, string $token): bool
   {
      if (
         is_resource($Handle) === false
         || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
         || ftruncate($Handle, 0) === false
         || rewind($Handle) === false
      ) {
         return false;
      }

      $offset = 0;
      while ($offset < 64) {
         $written = fwrite($Handle, substr($token, $offset));
         if ($written === false || $written === 0) {
            return false;
         }
         $offset += $written;
      }

      return fflush($Handle)
         && (!function_exists('fsync') || fsync($Handle))
         && rewind($Handle);
   }

   /** Read one exact ownership token from an open lease. */
   private function decode (mixed $Handle): null|string
   {
      if (is_resource($Handle) === false || rewind($Handle) === false) {
         return null;
      }
      $token = stream_get_contents($Handle, 65);

      return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1
         ? $token
         : null;
   }

   /** Publish one private regular marker containing the lease token. */
   private function publish (string $file, string $token): bool
   {
      if (
         preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
         || $this->validate($file) === false
         || $this->inspect($file) !== false
      ) {
         return false;
      }

      try {
         $temporary = dirname($file) . '/.owner-' . bin2hex(random_bytes(32)) . '.tmp';
      }
      catch (Throwable) {
         return false;
      }
      if (preg_match(self::TEMPORARY, basename($temporary)) !== 1) {
         return false;
      }

      $previousMask = umask(0077);
      try {
         $Handle = @fopen($temporary, 'x+b');
      }
      finally {
         umask($previousMask);
      }
      if ($Handle === false) {
         return false;
      }

      $published = false;
      try {
         $opened = fstat($Handle);
         $published = @chmod($temporary, 0600)
            && $this->encode($Handle, $token);
         clearstatcache(true, $temporary);
         $current = $this->inspect($temporary);
         $published = $published
            && $this->verify($opened, false)
            && $this->verify($current, false)
            && $opened['dev'] === $current['dev']
            && $opened['ino'] === $current['ino'];
      }
      finally {
         fclose($Handle);
         if ($published === false) {
            @unlink($temporary);
         }
      }

      if ($published === false || @link($temporary, $file) === false) {
         @unlink($temporary);
         return false;
      }

      // The hard link is the irreversible commit. Best-effort removal leaves
      // at most one token-named temporary link; join/reclaim accept nlink=2 so
      // a crash here cannot strand an otherwise committed namespace.
      @unlink($temporary);

      return true;
   }

   /**
    * Unlink only when a pathname still names an inode this operation opened.
    *
    * @param FileStatus|false $identity
    */
   private function erase (string $file, array|false $identity): bool
   {
      if ($identity === false) {
         return false;
      }
      $current = $this->inspect($file);
      if (
         $current === false
         || $identity['dev'] !== $current['dev']
         || $identity['ino'] !== $current['ino']
      ) {
         return false;
      }

      return @unlink($file);
   }

   /** Match a namespace marker to one exact lease token. */
   private function match (string $directory, string $token): bool
   {
      $directory = rtrim($directory, '/') . '/';
      $marker = $directory . self::MARKER;
      $identity = $this->inspect($marker);
      if (
         preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
         || $this->validate($marker, true) === false
         || is_link($marker)
         || $this->verify($identity, false, true) === false
      ) {
         return false;
      }
      $Marker = @fopen($marker, 'rb');
      if ($Marker === false) {
         return false;
      }
      try {
         $opened = fstat($Marker);
         $stored = stream_get_contents($Marker, 65);
         $current = $this->inspect($marker);
         if (
            $this->verify($opened, false, true) === false
            || $this->verify($current, false, true) === false
            || $identity['dev'] !== $opened['dev']
            || $identity['ino'] !== $opened['ino']
            || $opened['dev'] !== $current['dev']
            || $opened['ino'] !== $current['ino']
         ) {
            return false;
         }

         // A crash immediately after the atomic hard-link commit can leave
         // both names. Accept only the exact temp grammar and the same inode;
         // unrelated extra links make the ownership proof ambiguous.
         if ($opened['nlink'] === 2) {
            $matches = [];
            foreach ($this->scan($directory) ?: [] as $name) {
               if (preg_match(self::TEMPORARY, $name) !== 1) {
                  continue;
               }
               $temporary = $this->inspect("{$directory}{$name}");
               if (
                  $this->verify($temporary, false, true)
                  && $temporary['dev'] === $opened['dev']
                  && $temporary['ino'] === $opened['ino']
               ) {
                  $matches[] = $name;
               }
            }
            if (count($matches) !== 1) {
               return false;
            }
         }
      }
      finally {
         fclose($Marker);
      }

      return is_string($stored)
         && strlen($stored) === 64
         && hash_equals($token, $stored);
   }

   /**
    * Verify one owned private lease/marker or namespace directory.
    *
    * @param FileStatus|false $status
    * @phpstan-assert-if-true FileStatus $status
    */
   private function verify (array|false $status, bool $directory, bool $linked = false): bool
   {
      if (
         $status === false
         || $status['uid'] !== posix_geteuid()
         || ($directory === false && (
            $status['nlink'] !== 1
            && ($linked === false || $status['nlink'] !== 2)
         ))
      ) {
         return false;
      }

      return $directory
         ? ($status['mode'] & 0170000) === 0040000
            && ($status['mode'] & 0777) === 0700
         : ($status['mode'] & 0170000) === 0100000
            && ($status['mode'] & 0777) === 0600
            ;
   }

   /** @return null|array{attempt:string,generation:string,certificateHash:string,keyHash:null|string,requested:int} */
   public function fetch (): null|array
   {
      $record = $this->read("{$this->path}request.json");
      if ($record === null) {
         return null;
      }
      $attempt = $record['attempt'] ?? null;
      $generation = $record['generation'] ?? null;
      $certificateHash = $record['certificateHash'] ?? null;
      $keyHash = $record['keyHash'] ?? null;
      $requested = $record['requested'] ?? null;
      if (
         is_string($attempt) === false || preg_match('/^[a-f0-9]{32}$/', $attempt) !== 1
         || is_string($generation) === false || preg_match('/^[a-f0-9]{32}$/', $generation) !== 1
         || is_string($certificateHash) === false || preg_match('/^[a-f0-9]{64}$/', $certificateHash) !== 1
         || ($keyHash !== null && (is_string($keyHash) === false || preg_match('/^[a-f0-9]{64}$/', $keyHash) !== 1))
         || is_int($requested) === false
      ) {
         return null;
      }

      return [
         'attempt' => $attempt,
         'generation' => $generation,
         'certificateHash' => $certificateHash,
         'keyHash' => $keyHash,
         'requested' => $requested
      ];
   }

   public function acknowledge (
      string $attempt,
      string $generation,
      int $PID,
      bool $success,
      string $certificateHash,
      null|string $keyHash,
      string $error = ''
   ): bool
   {
      if (
         preg_match('/^[a-f0-9]{32}$/', $attempt) !== 1
         || preg_match('/^[a-f0-9]{32}$/', $generation) !== 1
         || $PID < 1
         || preg_match('/^[a-f0-9]{64}$/', $certificateHash) !== 1
         || ($keyHash !== null && preg_match('/^[a-f0-9]{64}$/', $keyHash) !== 1)
      ) {
         return false;
      }

      return $this->write("{$this->path}{$generation}/{$attempt}/{$PID}.json", [
         'attempt' => $attempt,
         'generation' => $generation,
         'pid' => $PID,
         'success' => $success,
         'certificateHash' => $certificateHash,
         'keyHash' => $keyHash,
         'error' => substr($error, 0, 512),
         'acknowledged' => time()
      ]);
   }

   /**
    * @return array<int,array{attempt:string,generation:string,pid:int,success:bool,certificateHash:string,keyHash:null|string,error:string,acknowledged:int}>
    */
   public function collect (string $generation, string $attempt): array
   {
      if (
         preg_match('/^[a-f0-9]{32}$/', $generation) !== 1
         || preg_match('/^[a-f0-9]{32}$/', $attempt) !== 1
         || $this->enter(false) === false
      ) {
         return [];
      }
      $acks = [];
      $directory = "{$this->path}{$generation}/{$attempt}/";
      if ($this->validate($directory) === false || is_link($directory) || is_dir($directory) === false) {
         return [];
      }
      foreach ($this->scan($directory) ?: [] as $name) {
         if (str_ends_with($name, '.json') === false) {
            continue;
         }
         $file = "{$directory}{$name}";
         if (is_link($file)) {
            continue;
         }
         $record = $this->read($file);
         $PID = $record['pid'] ?? null;
         if (
            $record === null
            || ($record['attempt'] ?? null) !== $attempt
            || ($record['generation'] ?? null) !== $generation
            || is_int($PID) === false || $PID < 1
            || basename($file) !== "{$PID}.json"
            || is_bool($record['success'] ?? null) === false
            || is_string($record['certificateHash'] ?? null) === false
            || preg_match('/^[a-f0-9]{64}$/', $record['certificateHash']) !== 1
            || (($record['keyHash'] ?? null) !== null && (
               is_string($record['keyHash']) === false
               || preg_match('/^[a-f0-9]{64}$/', $record['keyHash']) !== 1
            ))
            || is_string($record['error'] ?? null) === false
            || is_int($record['acknowledged'] ?? null) === false
         ) {
            continue;
         }
         /** @var array{attempt:string,generation:string,pid:int,success:bool,certificateHash:string,keyHash:null|string,error:string,acknowledged:int} $record */
         $acks[$PID] = $record;
      }

      return $acks;
   }

   public function complete (CertificateSnapshot $Snapshot, string $attempt): bool
   {
      $request = $this->fetch();
      if (
         preg_match('/^[a-f0-9]{32}$/', $attempt) !== 1
         || $request === null
         || $request['attempt'] !== $attempt
         || $request['generation'] !== $Snapshot->generation
         || $request['certificateHash'] !== $Snapshot->certificateHash
         || $request['keyHash'] !== $Snapshot->keyHash
      ) {
         return false;
      }

      return $this->write("{$this->path}applied.json", [
         'attempt' => $attempt,
         'generation' => $Snapshot->generation,
         'certificateHash' => $Snapshot->certificateHash,
         'keyHash' => $Snapshot->keyHash,
         'applied' => time()
      ]);
   }

   public function resolve (): null|string
   {
      $record = $this->read("{$this->path}applied.json");
      $generation = $record['generation'] ?? null;

      return is_string($generation) && preg_match('/^[a-f0-9]{32}$/', $generation) === 1
         ? $generation
         : null;
   }

   /** Remove acknowledgement files from superseded generations. */
   public function prune (string $keep, string $attempt): void
   {
      if ($this->enter(false) === false) {
         return;
      }
      // Enumerated, never globbed — see locate(). Here a metacharacter in the
      // operator-configured path expands into a DIFFERENT real directory, and
      // this is the one deletion path with neither validate() nor verify().
      foreach ($this->scan($this->path) ?: [] as $name) {
         $entry = "{$this->path}{$name}";
         if (preg_match('/^[a-f0-9]{32}$/', $name) !== 1 || is_link($entry)) {
            continue;
         }

         foreach ($this->scan($entry) ?: [] as $attemptName) {
            $attemptDirectory = "{$entry}/{$attemptName}";
            // Upgrade cleanup: round-8 ACKs lived directly under the
            // generation as `<PID>.json` before attempts were introduced.
            if (
               is_link($attemptDirectory) === false
               && is_file($attemptDirectory)
               && preg_match('/^[1-9][0-9]*\.json$/', $attemptName) === 1
            ) {
               @unlink($attemptDirectory);
               continue;
            }
            if (
               $name === $keep && $attemptName === $attempt
               || preg_match('/^[a-f0-9]{32}$/', $attemptName) !== 1
               || is_link($attemptDirectory)
               || is_dir($attemptDirectory) === false
            ) {
               continue;
            }
            foreach ($this->scan($attemptDirectory) ?: [] as $file) {
               if (str_ends_with($file, '.json')) {
                  @unlink("{$attemptDirectory}/{$file}");
               }
            }
            @rmdir($attemptDirectory);
         }
         if ($name !== $keep) {
            @rmdir($entry);
         }
      }
   }

   /** @param array<string,mixed> $record */
   private function write (string $file, array $record): bool
   {
      if ($this->enter(false) === false) {
         return false;
      }
      $directory = dirname($file) . '/';
      if ($this->validate($directory) === false) {
         return false;
      }
      if (is_dir($directory) === false && @mkdir($directory, 0700, true) === false && is_dir($directory) === false) {
         return false;
      }
      // @phpstan-ignore identical.alwaysFalse (intentional post-mkdir TOCTOU recheck)
      if ($this->validate($directory) === false || chmod($directory, 0700) === false) {
         return false;
      }

      try {
         $JSON = json_encode($record, JSON_THROW_ON_ERROR);
      }
      catch (JsonException) {
         return false;
      }

      $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
      $previousMask = umask(0077);
      try {
         $Handle = @fopen($temporary, 'x+b');
      }
      finally {
         umask($previousMask);
      }
      if ($Handle === false) {
         return false;
      }
      $written = false;
      try {
         $length = strlen($JSON);
         $offset = 0;
         while ($offset < $length) {
            $bytes = fwrite($Handle, substr($JSON, $offset));
            if ($bytes === false || $bytes === 0) {
               break;
            }
            $offset += $bytes;
         }
         $written = $offset === $length
            && fflush($Handle)
            && (!function_exists('fsync') || fsync($Handle));
      }
      finally {
         fclose($Handle);
         if ($written === false) {
            @unlink($temporary);
         }
      }

      if ($written === false) {
         return false;
      }
      if (rename($temporary, $file) === false) {
         @unlink($temporary);
         return false;
      }

      return true;
   }

   /** @return null|array<string,mixed> */
   private function read (string $file): null|array
   {
      if (
         $this->enter(false) === false
         || $this->validate($file) === false
         || is_link($file)
         || is_file($file) === false
      ) {
         return null;
      }
      $JSON = file_get_contents($file, false, null, 0, 65537);
      if (is_string($JSON) === false || strlen($JSON) > 65536) {
         return null;
      }
      $record = json_decode($JSON, true);
      if (is_array($record) === false) {
         return null;
      }
      foreach ($record as $key => $_) {
         if (is_string($key) === false) {
            return null;
         }
      }
      /** @var array<string,mixed> $record */

      return $record;
   }

   /**
    * Read uncached path identity without turning a missing entry into a warning.
    *
    * @return FileStatus|false
    * @phpstan-impure Filesystem identity can change between observations.
    */
   private function inspect (string $path): array|false
   {
      clearstatcache(true, $path);

      return @lstat($path);
   }

   /** @phpstan-impure A path segment may be replaced between validations. */
   private function validate (string $file, bool $shared = false): bool
   {
      $root = $shared ? $this->base : $this->path;
      if (
         str_starts_with($file, $root) === false
         || str_contains("{$file}/", '/../')
         || str_contains("{$file}/", '/./')
      ) {
         return false;
      }
      $walk = '';
      foreach (explode('/', trim($file, '/')) as $segment) {
         if ($segment === '') {
            continue;
         }
         $walk .= "/{$segment}";
         if (is_link($walk)) {
            return false;
         }
      }

      return true;
   }

   public function __destruct ()
   {
      $this->release();
   }

   private function __clone ()
   {
   }
}
