<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Process;


use const BOOTGLY_WORKING_DIR;
use const LOCK_EX;
use const LOCK_UN;
use function chown;
use function chgrp;
use function clearstatcache;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function strrpos;
use function substr;
use function unlink;
use RuntimeException;


class State
{
   // * Config
   // ...

   // * Data
   public string $pidFile;
   public string $pidLockFile;
   public string $commandFile;

   // * Metadata
   private string $pidsDir;
   /** @var resource|null */
   private mixed $lockHandle = null;


   public function __construct (string $id, null|string $instance = null)
   {
      $this->pidsDir = BOOTGLY_WORKING_DIR . '/workdata/pids/';

      if (is_dir($this->pidsDir) === false) {
         @mkdir($this->pidsDir, 0755, true);
      }

      // @ Extract short class name from FQCN (e.g. Bootgly\WPI\Nodes\HTTP_Server_CLI → HTTP_Server_CLI)
      $pos = strrpos($id, '\\');
      if ($pos !== false) {
         $id = substr($id, $pos + 1);
      }

      // @ Append instance qualifier (e.g. HTTP_Server_CLI.test)
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
    * @return void
    */
   public function lock (int $flag = LOCK_EX): void
   {
      $lock_file = $this->pidLockFile;

      $this->lockHandle = $this->lockHandle ?: (fopen($lock_file, 'a+') ?: null);

      if ($this->lockHandle) {
         flock($this->lockHandle, $flag);

         if ($flag === LOCK_UN) {
            fclose($this->lockHandle);

            $this->lockHandle = null;

            clearstatcache();

            if ( is_file($lock_file) ) {
               unlink($lock_file);
            }
         }
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
      $json = json_encode($data);

      if ($json === false || file_put_contents($this->pidFile, $json) === false) {
         throw new RuntimeException('Can not save process state to ' . $this->pidFile);
      }
   }

   /**
    * Read process state data from the PID file.
    *
    * @return array<string,mixed>|null
    */
   public function read (): null|array
   {
      if (is_file($this->pidFile) === false) {
         return null;
      }

      $contents = file_get_contents($this->pidFile);

      if ($contents === false) {
         return null;
      }

      $data = json_decode($contents, true);

      /** @var array<string,mixed>|null */
      return is_array($data) ? $data : null;
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
    * @param int $uid
    * @param int $gid
    *
    * @return void
    */
   public function own (int $uid, int $gid): void
   {
      foreach ([$this->pidFile, $this->pidLockFile, $this->commandFile] as $file) {
         if (is_file($file)) {
            @chown($file, $uid);
            @chgrp($file, $gid);
         }
      }
   }

   /**
    * Clean all per-project state files (PID, lock, command).
    *
    * @return void
    */
   public function clean (): void
   {
      @unlink($this->commandFile);
      @unlink($this->pidFile);

      $this->lock(LOCK_UN);
   }
}
