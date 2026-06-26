<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI\Message;


use function ord;
use function preg_match;
use function strlen;
use function substr;


/**
 * Incremental UTF-8 stream validator.
 *
 * Validates a text message split across frames as the bytes arrive, so an
 * invalid sequence fails fast (RFC 6455 §8.1, Autobahn 6.4.*) instead of only
 * after the final fragment. A trailing multibyte sequence that spans a frame
 * boundary is carried as `pending` to the next chunk.
 *
 * Implementation note: the actual well-formedness check is delegated to PCRE's
 * `//u` (a C routine) rather than a per-byte PHP DFA loop. A hot per-byte loop
 * that indexes a constant table is mis-compiled by the OPcache JIT in a
 * long-running worker (valid 3/4-byte sequences get wrongly rejected); the only
 * PHP-level loop here scans at most three trailing bytes and never gets hot.
 */
final class UTF8
{
   /**
    * Validate the next chunk of a UTF-8 text stream.
    *
    * @param string $pending The (≤3-byte) incomplete trailing sequence carried
    *   from the previous chunk, or '' to start a message.
    * @param string $bytes The new chunk.
    *
    * @return null|string The new pending tail to carry, or `null` when the data
    *   is definitely not valid UTF-8. At end of message the caller requires the
    *   returned pending to be '' — a non-empty tail is a truncated sequence.
    */
   public static function validate (string $pending, string $bytes): null|string
   {
      $data = "{$pending}{$bytes}";
      $length = strlen($data);
      // ?
      if ($length === 0) {
         return '';
      }

      // @ Walk back over up to three trailing continuation bytes (10xxxxxx) to
      //   the lead byte of the last sequence.
      $start = $length - 1;
      $seen = 0;
      while ($start >= 0 && $seen < 3 && (ord($data[$start]) & 0xC0) === 0x80) {
         $start--;
         $seen++;
      }

      // @ Split off an incomplete trailing sequence (a valid lead with fewer
      //   continuation bytes than it needs) to carry to the next chunk.
      $complete = $data;
      $tail = '';
      if ($start >= 0) {
         $lead = ord($data[$start]);
         $needed = match (true) {
            $lead >= 0xF0 => 4,
            $lead >= 0xE0 => 3,
            $lead >= 0xC0 => 2,
            default       => 1,   // ASCII (lone continuations fail in PCRE below)
         };
         if ($needed > 1 && ($length - $start) < $needed) {
            $complete = substr($data, 0, $start);
            $tail = substr($data, $start);
         }
      }

      // ? The complete portion must be well-formed UTF-8 (PCRE, JIT-immune).
      if ($complete !== '' && preg_match('//u', $complete) !== 1) {
         return null;
      }

      // ? A carried tail must begin with a valid lead byte (0xC2..0xF4); a lone
      //   continuation or an invalid/overlong lead fails fast here.
      if ($tail !== '' && preg_match('/\A[\xC2-\xF4]/', $tail) !== 1) {
         return null;
      }

      // :
      return $tail;
   }
}
