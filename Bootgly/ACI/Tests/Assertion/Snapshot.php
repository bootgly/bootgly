<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion;


/**
 * Implementation / Repository
 * Snapshot       / Snapshots
 */
interface Snapshot extends Comparator
{
   // * Metadata
   public bool $captured {
      get;
   }
   public bool $restored {
      get;
   }

   /**
    * Capture the snapshot
    *
    * @param string $snapshot The snapshot name.
    * @param mixed $data The data value to be captured.
    *
    * @return bool Returns true if the snapshot was captured successfully.
    */
   public function capture (string $snapshot, mixed $data): bool;

   /**
    * Restore the value captured by the snapshot
    *
    * @param string $snapshot The snapshot name.
    * @param mixed $data The data value to be restored.
    *
    * @return bool Returns true if the snapshot was restored successfully.
    */
   public function restore (string $snapshot, mixed &$data): bool;
}
