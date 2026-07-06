<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Queues\Drivers;


use function bin2hex;
use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function getmypid;
use function is_dir;
use function is_file;
use function mkdir;
use function preg_replace;
use function random_bytes;
use function rename;
use function scandir;
use function serialize;
use function sprintf;
use function str_ends_with;
use function time;
use function unlink;
use function unserialize;

use Bootgly\ACI\Queues\Driver;
use Bootgly\ACI\Queues\Job;


/**
 * Filesystem queue driver (default).
 *
 * Always available — no extension required. Per queue:
 * `storage/queues/<name>/{ready,reserved,failed}/`. A job is one file named
 * `<available-ts>-<id>.job` (zero-padded ts → lexical order == FIFO/due order).
 *
 * enqueue   = serialize + atomic temp+rename into `ready/`.
 * reserve   = scan `ready/` in order, atomic rename of the first due file into
 *             `reserved/` — the rename IS the claim, so competing workers never
 *             pick the same job (the loser's rename fails and it moves on).
 * complete  = unlink the reserved file. release = rewrite into `ready/` with a
 *             future ts (and one more attempt). bury = move into `failed/`.
 * recover   = reserved files older than the visibility timeout return to `ready/`.
 */
class File extends Driver
{
   // * Metadata
   /**
    * Current Unix timestamp (honours the Config clock override).
    */
   private int $now {
      get {
         $clock = $this->Config->clock;

         return $clock === null ? time() : (int) $clock();
      }
   }


   /**
    * Write the job as an atomic `ready/` file, stamping its availability.
    *
    * @param string $queue Target queue name.
    * @param Job $Job Job to enqueue.
    */
   public function enqueue (string $queue, Job $Job): bool
   {
      // ! Stamp availability so the file name matches the serialized state
      if ($Job->available <= 0) {
         $Job->postpone($this->now);
      }

      // @
      $ready = $this->locate($queue, 'ready');

      // :
      return $this->write($ready, $this->format($Job->available, $Job->id), serialize($Job));
   }

   /**
    * Atomically claim the next due `ready/` file by renaming it into `reserved/`.
    *
    * @param string $queue Queue to claim from.
    */
   public function reserve (string $queue): null|Job
   {
      $now = $this->now;

      $ready = $this->locate($queue, 'ready');
      // ?
      if (is_dir($ready) === false) {
         return null;
      }

      $reserved = $this->locate($queue, 'reserved');

      // @ Files come back lexically sorted == by due timestamp
      foreach ($this->scan($ready) as $file) {
         // ? Not due yet — every later file is due even later (sorted), so stop
         if ((int) explode('-', $file, 2)[0] > $now) {
            break;
         }

         $this->prepare($reserved);

         // ? Atomic claim: the rename winner owns the job
         if (@rename("{$ready}/{$file}", "{$reserved}/{$file}") === false) {
            continue;
         }

         $Job = $this->load("{$reserved}/{$file}");
         // ? Corrupt record — drop the claim and keep scanning
         if ($Job === null) {
            @unlink("{$reserved}/{$file}");
            continue;
         }

         // :
         return $Job;
      }

      // :
      return null;
   }

   /**
    * Delete the job's reserved file.
    *
    * @param string $queue Queue the job belongs to.
    * @param Job $Job Reserved job to acknowledge.
    */
   public function complete (string $queue, Job $Job): bool
   {
      $file = "{$this->locate($queue, 'reserved')}/{$this->format($Job->available, $Job->id)}";

      // ?
      if (is_file($file) === false) {
         return true;
      }

      // :
      return @unlink($file);
   }

   /**
    * Requeue the job into `ready/` with a later availability and one more attempt.
    *
    * @param string $queue Queue the job belongs to.
    * @param Job $Job Reserved job to requeue.
    * @param int $delay Seconds until the job becomes due again.
    */
   public function release (string $queue, Job $Job, int $delay = 0): bool
   {
      $old = "{$this->locate($queue, 'reserved')}/{$this->format($Job->available, $Job->id)}";

      // ! Bump the attempt count and the due timestamp
      $Job->attempt();
      $Job->postpone($this->now + $delay);

      $ready = $this->locate($queue, 'ready');
      $written = $this->write($ready, $this->format($Job->available, $Job->id), serialize($Job));

      // @ Drop the stale reserved file once the new ready file is in place
      if ($written === true && is_file($old) === true) {
         @unlink($old);
      }

      // :
      return $written;
   }

   /**
    * Move the job's reserved file into the `failed/` dead-letter directory.
    *
    * @param string $queue Queue the job belongs to.
    * @param Job $Job Reserved job to dead-letter.
    */
   public function bury (string $queue, Job $Job): bool
   {
      $name = $this->format($Job->available, $Job->id);
      $old = "{$this->locate($queue, 'reserved')}/{$name}";

      $failed = $this->locate($queue, 'failed');
      $this->prepare($failed);

      // ?: Never reserved — persist the job straight into the dead-letter store
      if (is_file($old) === false) {
         return $this->write($failed, $name, serialize($Job));
      }

      // :
      return @rename($old, "{$failed}/{$name}");
   }

   /**
    * Return `reserved/` files older than the visibility timeout to `ready/`.
    *
    * @param string $queue Queue to recover stale claims for.
    */
   public function recover (string $queue): int
   {
      $reserved = $this->locate($queue, 'reserved');
      // ?
      if (is_dir($reserved) === false) {
         return 0;
      }

      $now = $this->now;
      // ! Staleness is wall-clock vs filemtime (both real time), never the Config clock
      $deadline = time() - $this->Config->visibility;

      $ready = $this->locate($queue, 'ready');
      $count = 0;

      // @
      foreach ($this->scan($reserved) as $file) {
         $path = "{$reserved}/{$file}";

         // ? Still within the visibility window
         $mtime = @filemtime($path);
         if ($mtime === false || $mtime > $deadline) {
            continue;
         }

         $Job = $this->load($path);
         if ($Job === null) {
            continue;
         }

         // @ Make it due now and move it back to ready
         $Job->postpone($now);
         if ($this->write($ready, $this->format($now, $Job->id), serialize($Job)) === true) {
            @unlink($path);
            $count++;
         }
      }

      // :
      return $count;
   }

   /**
    * Count the `ready/` files for a queue.
    *
    * @param string $queue Queue to count.
    */
   public function count (string $queue): int
   {
      $ready = $this->locate($queue, 'ready');
      // ?
      if (is_dir($ready) === false) {
         return 0;
      }

      // :
      return count($this->scan($ready));
   }

   /**
    * Delete every `ready/`, `reserved/` and `failed/` file for a queue.
    *
    * @param string $queue Queue to clear.
    */
   public function clear (string $queue): bool
   {
      foreach (['ready', 'reserved', 'failed'] as $sub) {
         $dir = $this->locate($queue, $sub);
         if (is_dir($dir) === false) {
            continue;
         }

         foreach ($this->scan($dir) as $file) {
            @unlink("{$dir}/{$file}");
         }
      }

      // :
      return true;
   }

   // ---

   /**
    * Resolve a queue sub-directory, sanitising the queue name.
    */
   private function locate (string $queue, string $sub = ''): string
   {
      $name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $queue) ?? 'default';
      $base = "{$this->Config->path}/{$name}";

      // :
      return $sub === '' ? $base : "{$base}/{$sub}";
   }

   /**
    * Build a job file name: zero-padded due timestamp + id (lexical == due order).
    */
   private function format (int $available, string $id): string
   {
      // :
      return sprintf('%011d-%s.job', $available, $id);
   }

   /**
    * List `.job` files in a directory, lexically sorted (== due order).
    *
    * @return array<int,string>
    */
   private function scan (string $dir): array
   {
      $entries = @scandir($dir);
      // ?
      if ($entries === false) {
         return [];
      }

      $files = [];
      foreach ($entries as $entry) {
         if (str_ends_with($entry, '.job') === true) {
            $files[] = $entry;
         }
      }

      // :
      return $files;
   }

   /**
    * Ensure a directory exists.
    */
   private function prepare (string $dir): void
   {
      if (is_dir($dir) === false) {
         @mkdir($dir, 0775, true);
      }
   }

   /**
    * Atomic write: temp file + rename, creating the directory lazily on failure.
    */
   private function write (string $dir, string $name, string $bytes): bool
   {
      $file = "{$dir}/{$name}";
      $temp = "{$file}." . getmypid() . '.' . bin2hex(random_bytes(6)) . '.tmp';

      // ? Create the dir lazily — only when the first write fails
      $written = @file_put_contents($temp, $bytes);
      if ($written === false) {
         $this->prepare($dir);
         $written = @file_put_contents($temp, $bytes);
      }
      if ($written === false) {
         return false;
      }

      // @
      if (@rename($temp, $file) === false) {
         @unlink($temp);

         return false;
      }

      // :
      return true;
   }

   /**
    * Read and unserialize a job file; null on a missing or corrupt record.
    */
   private function load (string $path): null|Job
   {
      $bytes = @file_get_contents($path);
      // ?
      if ($bytes === false || $bytes === '') {
         return null;
      }

      // ! Restrict deserialization to Job only — never run object-injection gadgets
      //   from a tampered store; payloads are scalars/arrays by contract
      $Job = @unserialize($bytes, ['allowed_classes' => [Job::class]]);

      // :
      return $Job instanceof Job ? $Job : null;
   }
}
