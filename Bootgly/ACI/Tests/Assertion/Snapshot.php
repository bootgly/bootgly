<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion;


use Bootgly\ABI\Debugging\Backtrace;
use Bootgly\ACI\Tests\Asserting;
use Bootgly\ACI\Tests\Asserting\Fallback;


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
   /**
    * The key it captures and restores under: its name, or — unnamed — its
    * file and its creation order in that file.
    */
   public private(set) string $key;
   // ---
   // (protected(set): concrete Snapshots — subclasses — flip these flags,
   //  which readonly forbids outside the declaring class)
   public protected(set) bool $captured = false;
   public protected(set) bool $restored = false;


   public function __construct (null|string $name = null)
   {
      // * Config
      $this->name = $name ?? new Backtrace()->file;

      // * Metadata
      // ? Named: its own key
      if ($name !== null) {
         $this->key = $name;

         return;
      }
      // @ Unnamed: numbered in its file, so two never share a key
      self::$indexes[$this->name] ??= 0;
      self::$indexes[$this->name]++;
      $index = self::$indexes[$this->name];
      $this->key = "{$this->name}.{$index}";
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
      $snapshot = $this->key;

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
