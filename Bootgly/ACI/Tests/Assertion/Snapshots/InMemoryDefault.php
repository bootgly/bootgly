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


use Bootgly\ABI\Debugging\Backtrace;
use Bootgly\ACI\Tests\Assertion\Snapshot;


class InMemoryDefault implements Snapshot
{
   // * Config
   /**
    * @var string|null $name The snapshot name.
    */
   public ?string $name = null;

   // * Data
   protected static array $snapshots = [];

   // * Metadata
   /**
    * @var array<string, int>
    */
   private static array $indexes = [];
   // ---
   public readonly bool $captured;
   public readonly bool $restored;


   public function __construct (?string $name = null)
   {
      // * Config
      $this->name = $name ?? new Backtrace()->file;

      // * Metadata
      self::$indexes[$this->name] ??= 0;
      self::$indexes[$this->name]++;
   }

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

   public function compare (mixed &$actual, mixed &$expected): bool
   {
      $index = (string) self::$indexes[$this->name];
      $snapshot = "{$this->name}.{$index}";

      $this->restore($snapshot, $actual);

      $assertion = $actual === $expected;

      return $assertion && $this->capture($snapshot, $actual);
   }
   public function fail (mixed $actual, mixed $expected): array
   {
      return [
         'format' => 'Failed asserting that the snapshot value is equal to the expected value.',
         'values' => [
            'actual' => $actual,
            'expected' => $expected
         ]
      ];
   }
}
