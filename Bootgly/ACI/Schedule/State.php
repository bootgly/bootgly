<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Schedule;


use const BOOTGLY_STORAGE_DIR;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_numeric;
use function json_decode;
use function json_encode;
use function mkdir;


/**
 * Last-run persistence for scheduled jobs.
 *
 * A JSON map `id => unix-timestamp` at `storage/schedule/state.json`, used by
 * the catch-up policy to detect missed runs. Same JSON idiom as
 * `ACI/Process/State`.
 */
final class State
{
   // * Data
   /**
    * The state file path.
    */
   public private(set) string $file;

   // * Metadata
   /** @var array<string,int> */
   private array $runs;


   public function __construct ()
   {
      $dir = BOOTGLY_STORAGE_DIR . 'schedule/';

      // ! Ensure the state directory exists
      if (is_dir($dir) === false) {
         @mkdir($dir, 0755, true);
      }

      $this->file = "{$dir}state.json";

      // @
      $this->runs = $this->read();
   }

   /**
    * Last-run timestamp for a job (0 if it never ran).
    */
   public function fetch (string $id): int
   {
      // :
      return $this->runs[$id] ?? 0;
   }

   /**
    * Record a job's last-run timestamp (persisted immediately).
    */
   public function update (string $id, int $timestamp): void
   {
      $this->runs[$id] = $timestamp;

      // @
      $this->save();
   }

   /**
    * Read the state file into the in-memory map.
    *
    * @return array<string,int>
    */
   private function read (): array
   {
      // ?
      if (is_file($this->file) === false) {
         return [];
      }

      $contents = file_get_contents($this->file);

      if ($contents === false) {
         return [];
      }

      $data = json_decode($contents, true);

      if (is_array($data) === false) {
         return [];
      }

      $runs = [];
      foreach ($data as $key => $value) {
         if (is_numeric($value)) {
            $runs[(string) $key] = (int) $value;
         }
      }

      // :
      return $runs;
   }

   /**
    * Persist the in-memory map to the state file.
    */
   private function save (): void
   {
      file_put_contents($this->file, json_encode($this->runs));
   }
}
