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

use Bootgly\ACI\Tests\Assertion\Snapshots\InMemoryDefault;

trait Snapshots
{
   // * Config
   /**
    * The Snapshot instance to be used in the Assertion.
    */
   public Snapshot $Snapshot {
      get => $this->Snapshot ??= new InMemoryDefault;
   }

   /**
    * Capture a snapshot of the current actual value.
    * 
    * @param string $snapshot The snapshot name.
    * 
    * @return self Returns the current instance for method chaining.
    */
   public function capture (string $snapshot): self
   {
      $this->Snapshot ??= new InMemoryDefault;

      $this->Snapshot->capture($snapshot, $this->actual);

      return $this;
   }

   /**
    * Restore a snapshot value into the current actual value.
    * 
    * @param string $snapshot The snapshot name.
    * 
    * @return self Returns the current instance for method chaining.
    */
   public function restore (string $snapshot): self
   {
      $this->Snapshot ??= new InMemoryDefault;

      $this->Snapshot->restore($snapshot, $this->actual);

      return $this;
   }

   /**
    * Capture and restore a snapshot value into the current actual value.
    *
    * @param string $name The snapshot name.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function snapshot (string $name): self
   {
      $this->Snapshot->capture($name, $this->actual);

      $this->Snapshot->restore($name, $this->actual);

      return $this;
   }
}
