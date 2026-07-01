<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP2;


use function pack;
use function strlen;


/**
 * HTTP/2 frame serializer (RFC 9113 §4.1).
 *
 * Only the packing direction lives here: frame-header *parsing* is inlined in
 * the server decoder loop on purpose — decoding allocates no per-frame object
 * on the hot path.
 */
final class Frame
{
   /**
    * Serialize one frame: 9-byte header + payload.
    *
    * Header layout: 24-bit payload length, 8-bit type, 8-bit flags,
    * 1 reserved bit + 31-bit stream identifier.
    */
   public static function pack (int $type, int $flags, int $stream, string $payload = ''): string
   {
      // : Length and type share the first uint32: (length << 8) | type.
      return pack('NcN', (strlen($payload) << 8) | $type, $flags, $stream) . $payload;
   }
}
