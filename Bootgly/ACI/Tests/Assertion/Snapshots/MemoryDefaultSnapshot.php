<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Snapshots;


use Bootgly\ACI\Tests\Assertion\Snapshot;


class MemoryDefaultSnapshot extends Snapshot
{
   // * Config
   // ..Snapshot

   // * Data
   // ..Snapshot

   // * Metadata
   // ..Snapshot

   /**
    * Capture the snapshot
    *
    * @param string $snapshot The snapshot name.
    * @param mixed $data The data value to be captured.
    *
    * @return bool Returns true if the snapshot was captured successfully.
    */
   public function capture (string $snapshot, mixed $data): bool
   {
      self::$snapshots[$snapshot] = $data;

      $this->captured = true;

      return true;
   }

   /**
    * Restore the value captured by the snapshot
    *
    * @param string $snapshot The snapshot name.
    * @param mixed $data The data value to be restored.
    *
    * @return bool Returns true if the snapshot was restored successfully.
    */
   public function restore (string $snapshot, mixed &$data): bool
   {
      if (isSet(self::$snapshots[$snapshot])) {
         $data = self::$snapshots[$snapshot];

         $this->restored = true;

         return true;
      }

      $this->restored = false;

      return false;
   }
}
