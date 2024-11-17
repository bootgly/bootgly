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
use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Assertion\Snapshot;


class InFileStorage implements Snapshot
{
   private const SNAPSHOT_DIR = BOOTGLY_WORKING_DIR . 'workdata/tests/snapshots/';

   // * Config
   /**
    * @var string|null $name The snapshot name.
    */
   public ?string $name = null;

   // * Metadata
   public readonly bool $captured;
   public readonly bool $restored;

   /**
    * @var array<string, int>
    */
   private static array $indexes = [];


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
         $data = unserialize($File->read());
         $File->close();

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

      // @ restore
      $this->restore($snapshot, $actual);

      // @ assert
      $assertion = $actual == $expected;

      // @ capture
      if ($assertion === true) {
         $this->capture($snapshot, $actual);
      }

      return $assertion;
   }
   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): array
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
