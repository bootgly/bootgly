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


use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Assertion\Snapshot;


class FileStorageSnapshot extends Snapshot
{
   private const SNAPSHOT_DIR = BOOTGLY_WORKING_DIR . 'workdata/tests/snapshots/';

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
      $File = new File(
         path: self::SNAPSHOT_DIR . $snapshot . '.snap'
      );

      if ($File->create(true)) {
         $File->open(File::CREATE_TRUNCATE_WRITEONLY_MODE);
         $File->write(serialize($data));
         $File->close();

         $this->captured = true;

         return true;
      }

      $this->captured = false;

      return false;
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
      $File = new File(
         path: self::SNAPSHOT_DIR . $snapshot . '.snap'
      );

      if ($File->open(File::READONLY_MODE)) {
         $file_data = $File->read();
         $File->close();

         if ($file_data !== false) {
            $data = unserialize($file_data);

            $this->restored = true;

            return true;
         }
      }

      $this->restored = false;

      return false;
   }
}
