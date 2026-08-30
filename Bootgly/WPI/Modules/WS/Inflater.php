<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\WS;


use const E_WARNING;
use const ZLIB_NO_FLUSH;
use const ZLIB_STREAM_END;
use const ZLIB_SYNC_FLUSH;
use function function_exists;
use function inflate_add;
use function inflate_get_status;
use function intdiv;
use function max;
use function min;
use function pcntl_async_signals;
use function pcntl_signal_dispatch;
use function restore_error_handler;
use function set_error_handler;
use function strlen;
use function substr;
use InflateContext;
use LogicException;


/**
 * Bounded RFC 7692 inflater shared by the WebSocket server and client.
 *
 * PHP's zlib binding has no output-length argument for `inflate_add()`. Feed
 * compressed input in bounded pieces so one call can never materialize the
 * complete attacker-selected expansion before the message budget is checked.
 */
final class Inflater
{
   /** Maximum compressed bytes handed to one `inflate_add()` call. */
   private const int CHUNK_SIZE = 4096;
   /**
    * Avoid one-byte zlib calls when an application configures a tiny message
    * allowance. At DEFLATE's maximum useful expansion, 32 compressed bytes
    * produce roughly 33 KiB — a small fixed transient ceiling that prevents
    * the limit itself from becoming a CPU-amplification control.
    */
   private const int MIN_CHUNK_SIZE = 32;
   /**
    * Conservative compressed-to-plain sizing divisor.
    *
    * DEFLATE's maximum useful expansion is approximately 1032:1. Dividing the
    * configured allowance by 512 balances hostile expansion against calls for
    * incompressible data: from 16 KiB to 2 MiB, one part is at most about 2x
    * that allowance; above it, the 4 KiB ceiling caps transient output near
    * 4.23 MiB. Below 16 KiB, the 32-byte floor caps it near 33 KiB while
    * bounding tiny-limit call counts.
    */
   private const int EXPANSION_GUARD = 512;
   /** RFC 6455 close code: Message Too Big. */
   private const int TOO_BIG = 1009;


   /**
    * Inflate one RFC 7692 message under its decompressed byte allowance.
    *
    * The caller owns the InflateContext so context takeover survives across
    * messages. `false` preserves zlib's invalid-data signal; the integer return
    * is always the WebSocket 1009 close code.
    *
    * @return string|int|false
    * @throws LogicException when async PCNTL is enabled but its dispatch
    *    primitive was selectively disabled
    */
   public static function inflate (
      InflateContext $Inflator,
      string $payload,
      int $limit
   ): string|int|false
   {
      $limit = max(0, $limit);
      $length = strlen($payload);

      // ? Compressed input is part of the same message allowance. Production
      //   decoders already enforce this; keeping it here closes direct callers.
      if ($length > $limit) {
         return self::TOO_BIG;
      }

      $chunkSize = min(
         self::CHUNK_SIZE,
         max(
            self::MIN_CHUNK_SIZE,
            intdiv(max(1, $limit), self::EXPANSION_GUARD)
         )
      );
      $output = '';
      $outputSize = 0;
      $offset = 0;
      $Handler = static function (
         int $level,
         string $message,
         string $file,
         int $line
      ): bool {
         return $level === E_WARNING;
      };

      // @@ A do/while is intentional: an empty compressed payload still needs
      //    the RFC 7692 tail to complete the current message.
      do {
         $remaining = $length - $offset;
         $size = min($chunkSize, max(0, $remaining));
         $last = $size === $remaining;
         $chunk = $size > 0
            ? (string) substr($payload, $offset, $size)
            : '';

         // @ Append the RFC 7692 §7.2.2 empty block only to the bounded final
         //   chunk — never copy the complete compressed payload to add four bytes.
         if ($last) {
            $chunk .= "\x00\x00\xff\xff";
         }

         // ? Malformed peer bytes warn before zlib returns false. A local
         //   handler keeps that expected warning inside the protocol even when
         //   the application handler ignores suppression or throws.
         $asyncAvailable = function_exists('pcntl_async_signals');
         $dispatchAvailable = function_exists('pcntl_signal_dispatch');

         $asyncEnabled = $asyncAvailable && pcntl_async_signals();
         // ? Without dispatch, changing the process-global error handler while
         //   async callbacks are live cannot be made safe. Fail before mutation.
         if ($asyncEnabled && $dispatchAvailable === false) {
            throw new LogicException(
               'Async PCNTL requires pcntl_signal_dispatch'
            );
         }
         $asyncSignals = $asyncAvailable
            ? pcntl_async_signals(false)
            : false;
         try {
            set_error_handler($Handler, E_WARNING);
            try {
               $part = inflate_add(
                  $Inflator,
                  $chunk,
                  $last ? ZLIB_SYNC_FLUSH : ZLIB_NO_FLUSH
               );
            }
            finally {
               restore_error_handler();
            }
         }
         finally {
            // @ Deliver deferred application signals only after its own
            //   error handler is back on top of the process-global stack.
            if ($asyncSignals) {
               pcntl_async_signals(true);
               pcntl_signal_dispatch();
            }
         }
         if ($part === false) {
            return false;
         }

         $partSize = strlen($part);
         // ? Check before concatenation: an oversized part is discarded while
         //   the bounded transient allocation is the only attacker output alive.
         if ($partSize > $limit - $outputSize) {
            return self::TOO_BIG;
         }
         if ($partSize > 0) {
            $output .= $part;
            $outputSize += $partSize;
         }

         $offset += $size;

         // ? RFC 7692 accepts BFINAL=1. PHP reports STREAM_END and lazily resets
         //   on a later call; stop like the former one-shot inflater instead of
         //   interpreting trailing bytes as another stream in this message.
         if (inflate_get_status($Inflator) === ZLIB_STREAM_END || $last) {
            break;
         }
      } while ($offset < $length);

      return $output;
   }
}
