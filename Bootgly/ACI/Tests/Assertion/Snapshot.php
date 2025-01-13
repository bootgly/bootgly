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


use Bootgly\ABI\Debugging\Backtrace;
use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Asserting;


/**
 * Implementation / Repository
 * Snapshot       / Snapshots
 */
abstract class Snapshot implements Asserting
{
   // * Config
   /**
    * @var string|null $name The snapshot name.
    */
   public null|string $name = null;

   // * Data
   /**
    * @var array<string, mixed> $snapshots The snapshots data.
    */
   protected static array $snapshots = [];

   // * Metadata
   /**
    * @var array<string, int>
    */
   protected static array $indexes = [];
   // ---
   public readonly bool $captured;
   public readonly bool $restored;


   public function __construct (null|string $name = null)
   {
      // * Config
      $this->name = $name ?? new Backtrace()->file;

      // * Metadata
      // indexes
      if ($name !== null) {
         self::$indexes[$this->name] ??= 0;
         self::$indexes[$this->name]++;
      }
   }

   /**
    * Capture the snapshot
    *
    * @param string $snapshot The snapshot name.
    * @param mixed $data The data value to be captured.
    *
    * @return bool Returns true if the snapshot was captured successfully.
    */
   abstract public function capture (string $snapshot, mixed $data): bool;
   /**
    * Restore the value captured by the snapshot
    *
    * @param string $snapshot The snapshot name.
    * @param mixed $data The data value to be restored.
    *
    * @return bool Returns true if the snapshot was restored successfully.
    */
   abstract public function restore (string $snapshot, mixed &$data): bool;

   public function assert (mixed &$actual, mixed &$expected): bool
   {
      if ($this->name !== null) {
         $snapshot = $this->name;
      }
      else {
         $index = (string) self::$indexes[$this->name];
         $snapshot = "{$this->name}.{$index}";
      }

      $this->restore($snapshot, $actual);

      $assertion = $actual === $expected;

      return $assertion && $this->capture($snapshot, $actual);
   }
   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      return new Fallback(
         'Failed asserting that the snapshot value is equal to the expected value.',
         [
            'actual' => $actual,
            'expected' => $expected
         ],
         $verbosity
      );
   }
}
