<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Events;


use function array_keys;
use function count;
use function error_clear_last;
use function error_get_last;
use function get_debug_type;
use function get_resource_type;
use function gettype;
use function intdiv;
use function is_array;
use function is_resource;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function stream_select;
use function time_nanosleep;
use ErrorException;
use TypeError;
use ValueError;

use Bootgly\ABI\Clock;
use Bootgly\ABI\Events\Selector\Interests;
use Bootgly\ABI\Events\Selector\Reasons;
use Bootgly\ABI\Events\Selector\Refusal;


/**
 * Stream readiness with a deadline: watch streams for read and/or write
 * interest, then wait until one is ready, a deadline passes or a signal
 * arrives.
 *
 * The deadline is absolute and monotonic (`Clock::$tick`, nanoseconds), so a
 * wait that is interrupted and called again with the same deadline resumes
 * without extending it. One system call backs it: `stream_select()`.
 *
 * ```php
 * $Selector = new Selector;
 * $Selector->watch($socket, Interests::Read, payload: $Connection);
 *
 * $deadline = $Selector->Clock->tick + 5_000_000_000; // 5 s from now
 * while ($Selector->wait($deadline) === 0 && $Selector->interrupted) {
 *    pcntl_signal_dispatch();
 * }
 * foreach ($Selector->readable as $id => $Connection) {
 *    // ...
 * }
 * ```
 *
 * It only reports readiness: reading, writing, scheduling and Fibers belong
 * to the caller.
 */
final class Selector
{
   /**
    * The error number of an interrupted system call (`EINTR`) — the same on
    * Linux, macOS and the BSDs.
    */
   private const int EINTR = 4;
   /**
    * The longest single wait handed to the system, in seconds (about three
    * years): the largest timeout every supported kernel accepts.
    */
   private const int HORIZON = 100_000_000;

   // * Config
   /**
    * The clock deadlines are measured on (`$tick`); a system Clock unless one
    * is given.
    */
   public private(set) Clock $Clock;

   // * Data
   /**
    * The streams watched for read interest, by id (`(int) $stream`).
    *
    * @var array<int,resource>
    */
   public private(set) array $reads = [];
   /**
    * The streams watched for write interest, by id (`(int) $stream`).
    *
    * @var array<int,resource>
    */
   public private(set) array $writes = [];
   /**
    * The payload of each stream watched for read interest, by id.
    *
    * @var array<int,mixed>
    */
   private array $reading = [];
   /**
    * The payload of each stream watched for write interest, by id.
    *
    * @var array<int,mixed>
    */
   private array $writing = [];

   // * Metadata
   /**
    * The payloads of the streams the last `wait()` found ready to read, by
    * stream id. A snapshot: it changes only on the next `wait()`.
    *
    * @var array<int,mixed>
    */
   public private(set) array $readable = [];
   /**
    * The payloads of the streams the last `wait()` found ready to write, by
    * stream id. A snapshot: it changes only on the next `wait()`.
    *
    * @var array<int,mixed>
    */
   public private(set) array $writable = [];
   /**
    * Whether a signal cut the last `wait()` short. Dispatch the pending
    * signals and wait again with the same deadline to resume.
    */
   public private(set) bool $interrupted = false;


   /**
    * Create a Selector watching nothing.
    *
    * @param null|Clock $Clock The clock deadlines are measured on; a system Clock by default.
    */
   public function __construct (null|Clock $Clock = null)
   {
      // * Config
      $this->Clock = $Clock ?? new Clock;
   }

   /**
    * Watch a stream for one interest. Watching it again for the same
    * interest replaces its payload; call it once per interest to watch a
    * stream for both.
    *
    * The stream is checked once, when first watched, so a stream the system
    * cannot wait on is refused here and never at `wait()`.
    *
    * @param resource $Stream An open stream with a descriptor (socket, pipe, file, terminal).
    * @param Interests $Interest What to watch the stream for.
    * @param mixed $payload What the ready sets carry for this stream; the stream itself by default.
    *
    * @throws Refusal `Reasons::Invalid` (not an open stream),
    *    `Reasons::Unselectable` (no descriptor to wait on),
    *    `Reasons::Overflow` (descriptor beyond the `select()` limit) or
    *    `Reasons::Failure`.
    */
   public function watch (mixed $Stream, Interests $Interest = Interests::Read, mixed $payload = null): void
   {
      // ? Not an open stream (a closed one is not a resource any more)
      if (is_resource($Stream) === false || str_ends_with(get_resource_type($Stream), 'stream') === false) {
         $type = get_debug_type($Stream);

         throw new Refusal(
            Reasons::Invalid,
            "Selector cannot watch a value of type `$type`: an open stream is required."
         );
      }

      $id = (int) $Stream;

      // ? Checked once, on its first admission
      if (isSet($this->reads[$id]) === false && isSet($this->writes[$id]) === false) {
         $this->probe($Stream, $id);
      }

      // @
      if ($Interest === Interests::Read) {
         $this->reads[$id] = $Stream;
         $this->reading[$id] = $payload ?? $Stream;
      }
      else {
         $this->writes[$id] = $Stream;
         $this->writing[$id] = $payload ?? $Stream;
      }
   }

   /**
    * Stop watching a stream — for one interest, or for both when none is
    * given. A stream already closed can still be forgotten.
    *
    * @param resource|closed-resource $Stream The stream given to `watch()`.
    * @param null|Interests $Interest The interest to drop; both by default.
    *
    * @return bool Whether the stream was watched for that interest.
    */
   public function forget (mixed $Stream, null|Interests $Interest = null): bool
   {
      // ?: Only a stream — open or already closed — has an id to look up
      if (str_starts_with(gettype($Stream), 'resource') === false) {
         return false;
      }

      $id = (int) $Stream;
      $forgotten = false;

      // @
      if ($Interest !== Interests::Write && isSet($this->reads[$id])) {
         unset($this->reads[$id], $this->reading[$id]);

         $forgotten = true;
      }
      if ($Interest !== Interests::Read && isSet($this->writes[$id])) {
         unset($this->writes[$id], $this->writing[$id]);

         $forgotten = true;
      }

      // :
      return $forgotten;
   }

   /**
    * Wait until a watched stream is ready, the deadline passes or a signal
    * arrives, and fill the ready sets (`$readable`, `$writable`).
    *
    * The answer tells the three apart: a positive count means streams are
    * ready; zero with `$interrupted` set means a signal arrived first; zero
    * without it means the deadline passed.
    *
    * With nothing watched, a deadline makes it a plain interruptible sleep.
    *
    * An exception thrown by an asynchronous signal handler
    * (`pcntl_async_signals(true)`) during the wait is not caught: it reaches
    * the caller.
    *
    * @param null|int $deadline When to give up, on the monotonic clock
    *    (`$Selector->Clock->tick`, nanoseconds). `null` waits indefinitely; a
    *    deadline that is not in the future — `0`, for one — polls without
    *    waiting.
    *
    * @throws Refusal `Reasons::Closed` (watched streams were closed without
    *    `forget()`; they were dropped, so the next wait runs on the rest),
    *    `Reasons::Empty` (nothing watched and no deadline) or
    *    `Reasons::Failure`.
    *
    * @return int How many ready entries the two sets hold.
    */
   public function wait (null|int $deadline = null): int
   {
      // * Metadata
      $this->readable = [];
      $this->writable = [];
      $this->interrupted = false;

      // ! Timeout: what is left until the deadline, or null to block
      $seconds = null;
      $nanoseconds = 0;
      if ($deadline !== null) {
         $remaining = $deadline - $this->Clock->tick;

         $seconds = 0;
         if ($remaining > 0) {
            $seconds = intdiv($remaining, 1_000_000_000);
            $nanoseconds = $remaining % 1_000_000_000;

            // ? Beyond the horizon: the remainder is dropped with it, so
            //   rounding it up can never carry past what the system takes.
            if ($seconds >= self::HORIZON) {
               $seconds = self::HORIZON;
               $nanoseconds = 0;
            }
         }
      }

      // ! `stream_select()` rewrites each set with its ready subset, so it
      //   works on copies; an empty set is handed over as null.
      $read = $this->reads ?: null;
      $write = $this->writes ?: null;
      $except = null;

      // ? Nothing watched
      if ($read === null && $write === null) {
         if ($seconds === null) {
            throw new Refusal(
               Reasons::Empty,
               'Selector watches no stream and was given no deadline: the wait could never return.'
            );
         }

         // @ A plain sleep until the deadline, cut short by a signal
         if ($seconds > 0 || $nanoseconds > 0) {
            $this->interrupted = is_array(time_nanosleep($seconds, $nanoseconds));
         }

         // :
         return 0;
      }

      // ! Microseconds rounded up: a wait that times out never ends before
      //   the deadline, so a caller looping on it cannot spin.
      $microseconds = null;
      if ($seconds !== null) {
         $microseconds = intdiv($nanoseconds + 999, 1_000);

         if ($microseconds === 1_000_000) {
            $seconds++;
            $microseconds = 0;
         }
      }

      // ? A stream closed while still watched. PHP raises the error for it
      //   only after `select()` returns — it would sit out the whole wait
      //   first (forever, without a deadline) — so a wait that can block
      //   looks for closed streams before it starts.
      $closed = $seconds === 0 && $microseconds === 0
         ? []
         : $this->sweep();

      // @
      $selected = false;
      if ($closed === []) {
         try {
            $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
         }
         catch (TypeError | ValueError | ErrorException) {
            // ...a poll finds its closed streams below. Only what the call
            //    itself raises is caught: an exception thrown by an
            //    asynchronous signal handler belongs to the caller.
         }
      }

      if ($selected === false) {
         $closed = $closed ?: $this->sweep();
         if ($closed !== []) {
            $count = count($closed);

            throw new Refusal(
               Reasons::Closed,
               "Selector dropped $count stream(s) closed while still watched: forget a stream before closing it.",
               $closed
            );
         }

         // ! PHP reports an interrupted wait and a failed one as the same
         //   false. A retry without waiting tells them apart: the watched
         //   set is intact, so it only fails again when the set itself is
         //   refused.
         $this->interrupted = true;

         $read = $this->reads ?: null;
         $write = $this->writes ?: null;
         $except = null;
         $error = '';

         error_clear_last();
         try {
            $selected = @stream_select($read, $write, $except, 0, 0);
         }
         catch (TypeError | ValueError | ErrorException $Throwable) {
            $selected = false;
            $error = $Throwable->getMessage();
         }

         if ($selected === false) {
            $error = $error ?: (error_get_last()['message'] ?? 'no error reported');

            // ?: Another signal raced the retry
            if ($this->check($error)) {
               return 0;
            }

            throw new Refusal(
               Reasons::Failure,
               "Selector could not wait on the watched streams: $error."
            );
         }
      }

      // @ Ready streams → their payloads. With asynchronous signals a
      //   handler may run right after the system call returns and forget a
      //   stream that was ready: it is not reported.
      $ready = 0;
      if ($read) {
         foreach ($read as $id => $Stream) {
            if (isSet($this->reading[$id])) {
               $this->readable[$id] = $this->reading[$id];

               $ready++;
            }
         }
      }
      if ($write) {
         foreach ($write as $id => $Stream) {
            if (isSet($this->writing[$id])) {
               $this->writable[$id] = $this->writing[$id];

               $ready++;
            }
         }
      }

      // :
      return $ready;
   }

   /**
    * Check whether a `stream_select()` error message reports an interrupted
    * system call.
    */
   private function check (string $error): bool
   {
      $number = self::EINTR;

      // :
      return str_contains($error, "Unable to select [$number]");
   }

   /**
    * Probe one stream without waiting, so a stream the system cannot wait on
    * is refused at registration.
    *
    * PHP resource ids are not descriptor numbers, and counting the watched
    * streams cannot tell a descriptor at or above `FD_SETSIZE`: only the
    * system call itself can.
    *
    * @param resource $Stream
    *
    * @throws Refusal
    */
   private function probe (mixed $Stream, int $id): void
   {
      $error = '';

      // @@ A signal can race even a wait of zero: try twice
      for ($attempt = 0; $attempt < 2; $attempt++) {
         $read = [$Stream];
         $write = null;
         $except = null;

         error_clear_last();
         try {
            $selected = @stream_select($read, $write, $except, 0, 0);
         }
         catch (ValueError) {
            throw new Refusal(
               Reasons::Unselectable,
               "Selector cannot watch stream #$id: it has no descriptor the system can wait on.",
               [$id]
            );
         }
         catch (TypeError | ErrorException $Throwable) {
            $selected = false;
            $error = $Throwable->getMessage();
         }

         // ?:
         if ($selected !== false) {
            return;
         }

         $error = $error ?: (error_get_last()['message'] ?? 'no error reported');

         // ? Only an interruption is worth another attempt
         if ($this->check($error) === false) {
            break;
         }
      }

      // ? Descriptor at or above FD_SETSIZE
      if (str_contains($error, 'FD_SETSIZE')) {
         throw new Refusal(
            Reasons::Overflow,
            "Selector cannot watch stream #$id: its descriptor is beyond what select() takes in this PHP build (FD_SETSIZE) — the process holds too many open descriptors.",
            [$id]
         );
      }

      throw new Refusal(
         Reasons::Failure,
         "Selector could not probe stream #$id: $error.",
         [$id]
      );
   }

   /**
    * Drop every watched stream that was closed.
    *
    * @return array<int> The ids dropped.
    */
   private function sweep (): array
   {
      $closed = [];

      // @@
      foreach ($this->reads as $id => $Stream) {
         if (is_resource($Stream) === false) {
            unset($this->reads[$id], $this->reading[$id]);

            $closed[$id] = true;
         }
      }
      foreach ($this->writes as $id => $Stream) {
         if (is_resource($Stream) === false) {
            unset($this->writes[$id], $this->writing[$id]);

            $closed[$id] = true;
         }
      }

      // :
      return array_keys($closed);
   }
}
