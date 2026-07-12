<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Process;


use const BOOTGLY_STORAGE_DIR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_SH;
use const LOCK_UN;
use function bin2hex;
use function chmod;
use function fclose;
use function fflush;
use function flock;
use function fopen;
use function fstat;
use function fsync;
use function ftruncate;
use function function_exists;
use function fwrite;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function lchgrp;
use function lchown;
use function lstat;
use function mkdir;
use function posix_geteuid;
use function random_bytes;
use function rename;
use function rewind;
use function rtrim;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function strrpos;
use function substr;
use function umask;
use function unlink;
use RuntimeException;


class State
{
   // * Config
   // ...

   // * Data
   private string $id;
   public string $pidFile;
   public string $pidLockFile;
   public string $commandFile;

   // * Metadata
   private string $pidsDir;
   /** @var resource|null */
   private mixed $lockHandle = null;
   /** The local handle represents an acquired instance lock. */
   private bool $locked = false;


   public function __construct (string $id, null|string $instance = null)
   {
      $this->pidsDir = BOOTGLY_STORAGE_DIR . 'pids/';

      if (is_dir($this->pidsDir) === false) {
         @mkdir($this->pidsDir, 0755, true);
      }
      // ! Upgrade repair: an older release delegated the shared directory to
      //   the configured runtime UID. A privileged launch reclaims that exact
      //   inode for the trusted storage administrator before any per-instance
      //   pathname is opened; otherwise the old owner could still replace
      //   another service's lock/state during boot.
      if ($this->protect() === false) {
         throw new RuntimeException("Unsafe process state directory at {$this->pidsDir}");
      }

      // @ Extract short class name from FQCN (e.g. Bootgly\WPI\Nodes\HTTP_Server_CLI → HTTP_Server_CLI)
      $pos = strrpos($id, '\\');
      if ($pos !== false) {
         $id = substr($id, $pos + 1);
      }

      $this->id = $id;

      $this->qualify($instance);
   }

   /**
    * Qualify the state file names with an instance qualifier.
    * Rebuilds the PID, lock and command file paths from the base id
    * (e.g. instance `8080` → `<id>.8080.json`). Servers call this once the
    * bound port is known — before any lock or save.
    *
    * @param null|string $instance The instance qualifier (null = unqualified).
    *
    * @return void
    */
   public function qualify (null|string $instance): void
   {
      $id = $this->id;

      // @ Append instance qualifier (e.g. HTTP_Server_CLI.8080)
      if ($instance !== null) {
         $id .= ".$instance";
      }

      $this->pidFile     = "{$this->pidsDir}$id.json";
      $this->pidLockFile = "{$this->pidsDir}$id.lock";
      $this->commandFile = "{$this->pidsDir}$id.command";
   }

   /**
    * Lock the process to prevent multiple instances.
    *
    * @param int<0,7> $flag The lock flag (default: LOCK_EX).
    *
    * @return bool false when the lock is held by another process (LOCK_NB).
    */
   public function lock (int $flag = LOCK_EX): bool
   {
      $file = $this->pidLockFile;

      if ($flag === LOCK_UN) {
         if ($this->lockHandle === null) {
            $this->locked = false;
            return true;
         }
         flock($this->lockHandle, LOCK_UN);
         fclose($this->lockHandle);
         $this->lockHandle = null;
         $this->locked = false;

         return true;
      }

      $this->lockHandle = $this->lockHandle ?: $this->open($file);

      // ! Lock file unavailable/unsafe: fail closed. Proceeding unguarded
      //   would permit two SO_REUSEPORT masters for one instance identity.
      if ($this->lockHandle === null) {
         return false;
      }

      $locked = flock($this->lockHandle, $flag);

      // ? Lock held by another process (LOCK_NB): release the handle
      if ($locked === false) {
         fclose($this->lockHandle);

         $this->lockHandle = null;
      }
      // Only the exclusive instance lock is eligible for exec handoff; a
      // shared/read lock must never be promoted into serving authority.
      $this->locked = $locked && ($flag & LOCK_EX) === LOCK_EX;

      // :
      return $locked;
   }

   /**
    * Close only this process's inherited lock descriptor.
    *
    * Long-running forked auxiliaries that do not represent the serving process
    * must not retain the master instance lock. They also must not call LOCK_UN:
    * flock state is shared by the inherited open-file description and an
    * explicit unlock would release the master's lock too. Workers deliberately
    * retain their descriptor until their parent watchdog stops them.
    */
   public function detach (): void
   {
      if ($this->lockHandle !== null) {
         fclose($this->lockHandle);
         $this->lockHandle = null;
      }
      $this->locked = false;
   }

   /**
    * Return the exact acquired lock descriptor for an internal exec handoff.
    *
    * The caller must transfer this descriptor without closing or unlocking the
    * final live duplicate. Returning null fails closed if qualification changed,
    * the pathname was replaced, or this State never acquired the lock.
    *
    * @return resource|null
    */
   public function export (): mixed
   {
      if (
         $this->locked === false
         || $this->lockHandle === null
         || $this->verify($this->lockHandle) === false
      ) {
         return null;
      }

      return $this->lockHandle;
   }

   /**
    * Adopt a transferred lock descriptor for the already-qualified instance.
    *
    * SCM_RIGHTS preserves the sender's open-file description, and therefore
    * its flock. The non-blocking flock below is also an atomic fail-closed
    * check: a descriptor that did not carry the lock can only be adopted if no
    * competing master acquired the exact stable inode first.
    *
    * @param resource $Handle
    */
   public function adopt (mixed $Handle): bool
   {
      if (
         $this->lockHandle !== null
         || is_resource($Handle) === false
         || $this->verify($Handle) === false
         || flock($Handle, LOCK_EX | LOCK_NB) === false
      ) {
         return false;
      }
      // @phpstan-ignore identical.alwaysFalse (intentional post-flock pathname race recheck)
      if ($this->verify($Handle) === false) {
         return false;
      }

      $this->lockHandle = $Handle;
      $this->locked = true;

      return true;
   }

   /**
    * Save process state data to the PID file.
    *
    * @param array<string,mixed> $data
    *
    * @return void
    */
   public function save (array $data): void
   {
      $JSON = json_encode($data);
      if ($JSON === false || is_link($this->pidFile)) {
         throw new RuntimeException('Can not save process state to ' . $this->pidFile);
      }

      $temporary = $this->pidFile . '.' . bin2hex(random_bytes(8)) . '.tmp';
      $previousMask = umask(0022);
      try {
         $Handle = @fopen($temporary, 'x+b');
      }
      finally {
         umask($previousMask);
      }
      if ($Handle === false) {
         if ($this->rewrite($this->pidFile, $JSON)) {
            return;
         }
         throw new RuntimeException('Can not safely update process state at ' . $this->pidFile);
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

      if ($written === false || rename($temporary, $this->pidFile) === false) {
         @unlink($temporary);
         if ($written && $this->rewrite($this->pidFile, $JSON)) {
            return;
         }
         throw new RuntimeException('Can not commit process state to ' . $this->pidFile);
      }
   }

   /**
    * Read process state data from the PID file.
    *
    * @return array<string,mixed>|null
    */
   public function read (): null|array
   {
      if (is_link($this->pidFile) || is_file($this->pidFile) === false) {
         return null;
      }

      $before = @lstat($this->pidFile);
      $Handle = @fopen($this->pidFile, 'rb');
      if ($Handle === false || flock($Handle, LOCK_SH) === false) {
         is_resource($Handle) && fclose($Handle);
         return null;
      }

      try {
         $opened = fstat($Handle);
         $after = @lstat($this->pidFile);
         if (
            is_array($before) === false || is_array($opened) === false || is_array($after) === false
            || $before['dev'] !== $opened['dev']
            || $before['ino'] !== $opened['ino']
            || $after['dev'] !== $opened['dev']
            || $after['ino'] !== $opened['ino']
         ) {
            return null;
         }
         $contents = stream_get_contents($Handle, 65537);
      }
      finally {
         flock($Handle, LOCK_UN);
         fclose($Handle);
      }

      if (is_string($contents) === false || strlen($contents) > 65536) {
         return null;
      }

      $data = json_decode($contents, true);
      if (is_array($data) === false) {
         return null;
      }
      foreach ($data as $key => $_) {
         if (is_string($key) === false) {
            return null;
         }
      }
      /** @var array<string,mixed> $data */

      return $data;
   }

   /**
    * Check whether the PID file contains valid process state.
    *
    * @return bool
    */
   public function check (): bool
   {
      // ? A clean state intentionally keeps the per-instance inode in place:
      //   a demoted process may own the file but cannot unlink it from the
      //   protected, storage-admin-owned `pids/` directory. Empty/invalid
      //   content is the tombstone, so pathname existence alone must never
      //   advertise a live process.
      return $this->read() !== null;
   }

   /**
    * Transfer ownership of this instance's state files to the given user/group.
    * Must be called while the process still has privileges (e.g. root)
    * before demoting, so the demoted user can later rewrite/tombstone them.
    *
    * The shared `pids/` directory deliberately belongs to the trusted storage
    * administrator. Handing it to one runtime UID would let that identity
    * replace or remove every other service's state and lock pathnames. clean()
    * therefore clears owned PID/command inodes in place instead of requiring
    * directory writes.
    *
    * @param int $UID
    * @param int $GID
    *
    * @return bool Whether every existing regular state file was handed off.
    */
   public function own (int $UID, int $GID): bool
   {
      // ? Re-run immediately before handoff as a final identity/mode check and
      //   to repair a legacy runtime-owned directory on an upgraded root boot.
      if ($this->protect() === false) {
         return false;
      }
      $pids = rtrim($this->pidsDir, '/');
      $directory = @lstat($pids);
      if (
         is_array($directory) === false
         || ((int) $directory['mode'] & 0170000) !== 0040000
      ) {
         return false;
      }

      foreach ([$this->pidFile, $this->pidLockFile, $this->commandFile] as $file) {
         $before = @lstat($file);
         // ? A state file may not exist yet (workers can demote before the
         //   final master publishes its PID document). The master performs its
         //   own handoff after save(), so absence here is valid.
         if ($before === false) {
            continue;
         }
         if (
            ((int) $before['mode'] & 0170000) !== 0100000
            || @lchown($file, $UID) === false
            || @lchgrp($file, $GID) === false
         ) {
            return false;
         }
         $after = @lstat($file);
         if (
            is_array($after) === false
            || $before['dev'] !== $after['dev']
            || $before['ino'] !== $after['ino']
            || ((int) $after['mode'] & 0170000) !== 0100000
            || (int) $after['uid'] !== $UID
            || (int) $after['gid'] !== $GID
         ) {
            return false;
         }
      }

      $after = @lstat($pids);

      // ! The directory itself must remain the exact same inode, owner and
      //   group throughout the handoff. Only the per-instance regular files
      //   are delegated to the runtime identity.
      return is_array($after)
         && $directory['dev'] === $after['dev']
         && $directory['ino'] === $after['ino']
         && $directory['uid'] === $after['uid']
         && $directory['gid'] === $after['gid']
         && ((int) $after['mode'] & 0170000) === 0040000;
   }

   /**
    * Clean the replaceable per-project state (PID and command).
    *
    * The lock inode deliberately remains in place. Unlinking a flock file after
    * unlock lets a concurrent opener retain the old inode while a third process
    * creates and locks a new inode under the same path, defeating exclusivity.
    * PID and command inodes normally remain too: after privilege demotion their
    * owner can truncate them, but cannot safely receive write permission on the
    * shared parent directory merely to unlink them. The trusted storage admin
    * may remove a foreign-owned state inode after a hard kill. An empty PID file
    * is a tombstone; check()/read() therefore report it as absent.
    *
    * @return void
    */
   public function clean (): void
   {
      $this->tombstone($this->commandFile);
      $this->tombstone($this->pidFile);

      $this->lock(LOCK_UN);
   }

   /** Clear an existing owned state inode without requiring parent-directory writes. */
   private function tombstone (string $file): bool
   {
      if (is_link($file)) {
         return false;
      }
      if (is_file($file) === false) {
         return true;
      }
      if ($this->rewrite($file, '')) {
         return true;
      }

      // ? A project/storage administrator can own the protected directory but
      //   not a state file delegated to another runtime UID (e.g. cleanup after
      //   SIGKILL). It already has pathname authority by contract, so allow it
      //   to remove only a still-regular, contained PID/command inode when an
      //   in-place tombstone is impossible. The lock file never takes this path.
      $storage = @lstat(rtrim(BOOTGLY_STORAGE_DIR, '/'));
      $directory = @lstat(rtrim($this->pidsDir, '/'));
      $state = @lstat($file);
      if (
         str_starts_with($file, $this->pidsDir) === false
         || is_array($storage) === false
         || is_array($directory) === false
         || is_array($state) === false
         || ((int) $state['mode'] & 0170000) !== 0100000
         || $directory['uid'] !== $storage['uid']
         || (int) $directory['uid'] !== posix_geteuid()
         || ((int) $directory['mode'] & 0022) !== 0
      ) {
         return false;
      }

      return @unlink($file);
   }

   /**
    * Validate the shared state directory and reclaim legacy ownership on a
    * privileged launch. Its trusted owner/group are inherited from the storage
    * root, preserving an ordinary non-root project administrator's ability to
    * create new instances without granting a distinct runtime UID authority
    * over every service pathname.
    */
   private function protect (): bool
   {
      $storage = rtrim(BOOTGLY_STORAGE_DIR, '/');
      $pids = rtrim($this->pidsDir, '/');
      if (is_link($storage) || is_link($pids)) {
         return false;
      }
      $administrator = @lstat($storage);
      $before = @lstat($pids);
      if (
         is_array($administrator) === false
         || ((int) $administrator['mode'] & 0170000) !== 0040000
         || ((int) $administrator['mode'] & 0022) !== 0
         || is_array($before) === false
         || ((int) $before['mode'] & 0170000) !== 0040000
      ) {
         return false;
      }

      $EUID = posix_geteuid();
      if ($EUID === 0) {
         if (
            @lchown($pids, (int) $administrator['uid']) === false
            || @lchgrp($pids, (int) $administrator['gid']) === false
            || @chmod($pids, 0755) === false
         ) {
            return false;
         }
      }

      $after = @lstat($pids);
      if (
         is_array($after) === false
         || $before['dev'] !== $after['dev']
         || $before['ino'] !== $after['ino']
         || ((int) $after['mode'] & 0170000) !== 0040000
         || ((int) $after['mode'] & 0022) !== 0
         || $after['uid'] !== $administrator['uid']
         || $after['gid'] !== $administrator['gid']
      ) {
         return false;
      }

      return true;
   }

   /** @return resource|null Open a regular lock file without following links. */
   private function open (string $file): mixed
   {
      if (is_link($file)) {
         return null;
      }

      $before = @lstat($file);
      $previousMask = umask(0077);
      try {
         $Handle = $before === false
            ? @fopen($file, 'x+b')
            : @fopen($file, 'c+b');
         // @phpstan-ignore identical.alwaysTrue (intentional final-link race recheck)
         if ($Handle === false && $before === false && is_link($file) === false) {
            // Another safe creator may have won the exclusive race.
            $before = @lstat($file);
            $Handle = @fopen($file, 'c+b');
         }
      }
      finally {
         umask($previousMask);
      }
      if ($Handle === false) {
         return null;
      }

      $opened = fstat($Handle);
      $after = @lstat($file);
      if (is_array($opened) === false || is_array($after) === false) {
         fclose($Handle);
         return null;
      }
      $same = $opened['dev'] === $after['dev']
         && $opened['ino'] === $after['ino']
         && ((int) $opened['mode'] & 0170000) === 0100000;
      if (is_array($before)) {
         $same = $same
            && $before['dev'] === $opened['dev']
            && $before['ino'] === $opened['ino'];
      }
      if ($same === false) {
         fclose($Handle);
         return null;
      }

      return $Handle;
   }

   /** @param resource $Handle */
   private function verify (mixed $Handle): bool
   {
      if (is_resource($Handle) === false || is_link($this->pidLockFile)) {
         return false;
      }

      $before = @lstat($this->pidLockFile);
      $opened = @fstat($Handle);
      $after = @lstat($this->pidLockFile);

      return is_array($before)
         && is_array($opened)
         && is_array($after)
         && ((int) $before['mode'] & 0170000) === 0100000
         && ((int) $opened['mode'] & 0170000) === 0100000
         && ((int) $after['mode'] & 0170000) === 0100000
         && $before['dev'] === $opened['dev']
         && $before['ino'] === $opened['ino']
         && $after['dev'] === $opened['dev']
         && $after['ino'] === $opened['ino'];
   }

   /** Rewrite an existing state inode when its directory is not writable. */
   private function rewrite (string $file, string $contents): bool
   {
      $Handle = $this->open($file);
      if ($Handle === null || flock($Handle, LOCK_EX) === false) {
         is_resource($Handle) && fclose($Handle);
         return false;
      }

      $written = false;
      try {
         if (ftruncate($Handle, 0) === false || rewind($Handle) === false) {
            return false;
         }
         $length = strlen($contents);
         $offset = 0;
         while ($offset < $length) {
            $bytes = fwrite($Handle, substr($contents, $offset));
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
         flock($Handle, LOCK_UN);
         fclose($Handle);
      }

      return $written;
   }
}
