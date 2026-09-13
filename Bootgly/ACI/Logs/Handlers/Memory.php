<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Handlers;


use function array_shift;
use function count;
use function getmypid;

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatter;
use Bootgly\ACI\Logs\Handler;


/**
 * In-memory handler: holds records instead of writing them anywhere.
 *
 * A holding sink for a window in which no destination may be written yet —
 * a root launch keeps its pre-demote records here and replays them through
 * the real sink once privileges are dropped. The hold is per process: a
 * forked child starts empty, it never replays what its parent held — unless
 * it adopt()s the inheritance, as the detached daemon master does.
 */
class Memory extends Handler
{
   /** Records held at most — the oldest gives way past it. */
   public const int LIMIT = 10000;

   // * Data
   /** @var array<int,Record> Records held, in arrival order. */
   public private(set) array $Records = [];
   /** @var array<int,Handler> The handlers this hold stands in for — installed once privileges are dropped. */
   public private(set) array $Withheld = [];

   // * Metadata
   /** The hold a privileged launch installed in this process — one at a time, by identity. */
   private static null|self $Hold = null;
   /** The process holding the records — a fork inherits the array, not the ownership. */
   private int $pid;


   public function __construct (null|Formatter $Formatter = null, Levels $Level = Levels::Debug)
   {
      parent::__construct($Formatter, $Level);

      $this->pid = (int) getmypid();
   }

   /**
    * Register — or read — the hold a privileged launch installed in this process.
    *
    * A hold is known by IDENTITY, never by class: a project may push a Memory
    * handler of its own into the global sinks, and that one is held and
    * replayed like any other handler.
    *
    * @param null|self $Hold The hold to register, with the handlers it stands in for; null only reads.
    * @param array<int,Handler> $Withheld The handlers withheld — re-registering the hold in place with none named keeps them.
    * @return null|self The hold in place.
    */
   public static function hold (null|self $Hold = null, array $Withheld = []): null|self
   {
      if ($Hold !== null) {
         if ($Hold !== self::$Hold || $Withheld !== []) {
            $Hold->Withheld = $Withheld;
         }
         self::$Hold = $Hold;
      }

      return self::$Hold;
   }

   /**
    * Forget the hold in place — its handlers were installed.
    */
   public static function release (): void
   {
      self::$Hold = null;
   }

   /**
    * Hand every held record to other handlers, in order, and forget them.
    *
    * @param Handler ...$Handlers The destination handlers.
    * @return int Records replayed.
    */
   public function replay (Handler ...$Handlers): int
   {
      $this->own();

      $replayed = 0;
      foreach ($this->Records as $Record) {
         foreach ($Handlers as $Handler) {
            $Handler->handle($Record);
         }
         $replayed++;
      }
      $this->Records = [];

      // :
      return $replayed;
   }

   /**
    * Keep what this process inherited: the records become its own to replay.
    *
    * The daemon master is forked from the launcher that started the hold;
    * unlike a worker, it is the one that will persist those records.
    */
   public function adopt (): void
   {
      $this->pid = (int) getmypid();
   }

   protected function write (string $formatted, Record $Record): bool
   {
      $this->own();

      // ? Bounded: the hold spans a window nobody controls the length of
      //   (Auto-TLS issuance runs inside it) — the oldest record gives way
      if (count($this->Records) >= self::LIMIT) {
         array_shift($this->Records);
      }
      $this->Records[] = $Record;

      return true;
   }

   /**
    * Keep the hold per process: a forked child inherits its parent's array
    * and must not replay records that were never its own.
    */
   private function own (): void
   {
      $pid = (int) getmypid();
      if ($pid !== $this->pid) {
         $this->Records = [];
         $this->pid = $pid;
      }
   }
}
