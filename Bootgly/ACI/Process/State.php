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
use const LOCK_SH;
use const LOCK_UN;
use function bin2hex;
use function fclose;
use function fflush;
use function flock;
use function fopen;
use function fsync;
use function fstat;
use function ftruncate;
use function function_exists;
use function fwrite;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
use function json_decode;
use function json_encode;
use function lchgrp;
use function lchown;
use function lstat;
use function mkdir;
use function random_bytes;
use function rename;
use function rewind;
use function strlen;
use function stream_get_contents;
use function strrpos;
use function substr;
use function unlink;
use function umask;
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


   public function __construct (string $id, null|string $instance = null)
   {
      $this->pidsDir = BOOTGLY_STORAGE_DIR . 'pids/';

      if (is_dir($this->pidsDir) === false) {
         @mkdir($this->pidsDir, 0755, true);
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
            return true;
         }
         flock($this->lockHandle, LOCK_UN);
         fclose($this->lockHandle);
         $this->lockHandle = null;

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
    * Check if a PID file exists.
    *
    * @return bool
    */
   public function check (): bool
   {
      return is_file($this->pidFile);
   }

   /**
    * Transfer ownership of all state files to the given user/group.
    * Must be called while the process still has privileges (e.g. root)
    * before demoting, so the demoted user can later rewrite/unlink them.
    *
    * @param int $UID
    * @param int $GID
    *
    * @return bool Whether every existing regular state file was handed off.
    */
   public function own (int $UID, int $GID): bool
   {
      foreach ([$this->pidFile, $this->pidLockFile, $this->commandFile] as $file) {
         if (is_link($file)) {
            return false;
         }
         if (is_file($file)) {
            if (@lchown($file, $UID) === false || @lchgrp($file, $GID) === false) {
               return false;
            }
         }
      }

      return true;
   }

   /**
    * Clean the replaceable per-project state files (PID and command).
    *
    * The lock inode deliberately remains in place. Unlinking a flock file after
    * unlock lets a concurrent opener retain the old inode while a third process
    * creates and locks a new inode under the same path, defeating exclusivity.
    *
    * @return void
    */
   public function clean (): void
   {
      @unlink($this->commandFile);
      @unlink($this->pidFile);

      $this->lock(LOCK_UN);
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
