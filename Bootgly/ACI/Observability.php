<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI;


use function dirname;
use function file_get_contents;
use function filemtime;
use function glob;
use function is_array;
use function is_dir;
use function json_decode;
use function mkdir;
use function time;
use function umask;

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Observability\Collectors;
use Bootgly\ACI\Observability\Collectors\Process;
use Bootgly\ACI\Observability\Collectors\Runtime;
use Bootgly\ACI\Observability\Data\Snapshot;
use Bootgly\ACI\Observability\Exporter;
use Bootgly\ACI\Observability\Metrics;


class Observability
{
   // * Data
   public Metrics $Metrics;
   public Collectors $Collectors;
   // @ Optional global instance (set by the app/WPI), so routes and the metric bridge share one registry.
   public static null|self $Instance = null;


   /**
    * Build an observability registry: user metric instruments plus the default health collectors.
    *
    * @param bool $collectors When true (default), auto-register the Process + Runtime health collectors.
    */
   public function __construct (bool $collectors = true)
   {
      // * Data
      $this->Metrics = new Metrics;
      $this->Collectors = new Collectors;

      // @ Default self-health collectors
      if ($collectors === true) {
         $this->Collectors
            ->push(new Process)
            ->push(new Runtime);
      }
   }

   /**
    * Gather a point-in-time snapshot from every instrument and collector.
    *
    * @return Snapshot
    */
   public function gather (): Snapshot
   {
      // ! Instruments first, then fold in collector sources by name
      $metrics = $this->Metrics->read();
      foreach ($this->Collectors->collect() as $name => $metric) {
         $metrics[$name] = $metric;
      }

      // :
      return new Snapshot($metrics);
   }

   /**
    * Encode the current snapshot through an exporter.
    *
    * @param Exporter $Exporter The exporter to encode with.
    * @return string The encoded snapshot.
    */
   public function export (Exporter $Exporter): string
   {
      // :
      return $Exporter->export($this->gather());
   }

   /**
    * Gather + encode the current snapshot and atomically write it to a file.
    *
    * Each worker calls this on a timer with its own per-PID path (e.g. `…/worker-123.json`); the
    * write goes through `File->replace()` (synced temporary + rename) so readers never observe a
    * partial document. The file gets the mode a plain write would give it (`0666` minus the umask).
    *
    * @param Exporter $Exporter The exporter to encode with.
    * @param string $path Destination file path (its directory is created if missing).
    * @return bool True on success.
    */
   public function dump (Exporter $Exporter, string $path): bool
   {
      // ? Ensure the destination directory exists
      $dir = dirname($path);
      if ( is_dir($dir) === false ) {
         @mkdir($dir, 0775, true);
      }

      // @ Encode + atomic write (synced temporary + rename)
      $bytes = $this->export($Exporter);
      // ? Encoder failed (e.g. non-finite values) — keep the previous good snapshot
      if ( $bytes === '' ) {
         return false;
      }
      // ! The mode a plain write would give it (`0666` minus the umask)
      $permissions = 0666 & ~umask();

      // :
      return new File($path)->replace($bytes, $permissions);
   }

   /**
    * Read and merge every JSON snapshot matching a glob pattern into one cluster snapshot.
    *
    * Used by the `/metrics` route to fold per-worker files into a fleet-wide view. Files older than
    * `$maxAge` seconds (e.g. from dead workers) are skipped.
    *
    * @param string $pattern Glob pattern (e.g. `…/metrics/worker-*.json`).
    * @param float $maxAge When > 0, skip files whose mtime is older than this many seconds.
    * @return Snapshot The merged cluster snapshot (empty when nothing matches).
    */
   public static function aggregate (string $pattern, float $maxAge = 0.0): Snapshot
   {
      $Cluster = new Snapshot;

      $files = glob($pattern);
      if ( $files === false ) {
         return $Cluster;
      }

      $now = time();
      foreach ($files as $file) {
         // ? Skip stale files (dead workers)
         if ( $maxAge > 0.0 ) {
            $mtime = filemtime($file);
            if ( $mtime !== false && ($now - $mtime) > $maxAge ) {
               continue;
            }
         }

         // ? Unreadable / invalid — skip
         $raw = file_get_contents($file);
         if ( $raw === false ) {
            continue;
         }
         $data = json_decode($raw, true);
         if ( is_array($data) === false ) {
            continue;
         }

         // @ Merge this worker's snapshot into the cluster
         $Cluster->merge(Snapshot::import($data));
      }

      // :
      return $Cluster;
   }
}
