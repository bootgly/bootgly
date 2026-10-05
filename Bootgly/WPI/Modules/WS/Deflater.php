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


use const ZLIB_FULL_FLUSH;
use const ZLIB_SYNC_FLUSH;
use function deflate_add;
use function str_ends_with;
use function substr;
use DeflateContext;


/**
 * RFC 7692 message compressor shared by the WebSocket server and client.
 *
 * One call compresses one whole message and returns its RSV1 payload.
 */
final class Deflater
{
   /**
    * Compress one RFC 7692 message.
    *
    * With `$takeover`, the message ends in a sync flush and the context keeps
    * its LZ77 window for the next message (context takeover). Without it, the
    * message ends in a full flush: the context forgets everything it saw, so
    * the output is decodable on its own and the same context can compress for
    * many peers without one message referencing another's bytes.
    *
    * The trailing `00 00 ff ff` of the flush is stripped (§7.2.1); an empty
    * message is the single `0x00` octet (§7.2.3.6).
    *
    * @return string|false The compressed payload, or `false` when zlib fails.
    */
   public static function deflate (
      DeflateContext $Deflator,
      string $payload,
      bool $takeover
   ): string|false
   {
      $out = deflate_add(
         $Deflator,
         $payload,
         $takeover ? ZLIB_SYNC_FLUSH : ZLIB_FULL_FLUSH
      );
      // ?
      if ($out === false) {
         return false;
      }

      // @ Strip the RFC 7692 §7.2.1 trailing empty block.
      if (str_ends_with($out, "\x00\x00\xff\xff")) {
         $out = (string) substr($out, 0, -4);
      }

      // ?: A flush with no new input emits nothing; an empty message still
      //    needs its one empty-block octet, or a peer keeping its window
      //    reads the next message out of step.
      if ($out === '') {
         return "\x00";
      }

      // :
      return $out;
   }
}
