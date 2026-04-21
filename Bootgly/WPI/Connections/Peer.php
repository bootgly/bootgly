<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Connections;


use function strpos;
use function strrpos;
use function substr;


class Peer
{
   /**
    * Parse a `stream_socket_get_name()` peer string into `[ip, port]`.
    *
    * IPv4 peers arrive as `"127.0.0.1:8080"` and IPv6 peers as
    * `"[::1]:8080"`. A naïve `explode(':', $peer, 2)` on IPv6 yields
    * `$ip = "[::1"` and `$port = "8080]"` (cast to 0), which silently
    * breaks every downstream IP-based security control: blacklist/ACL,
    * RateLimit buckets, TrustedProxy matching against `'::1'`, and
    * forensic logs. Bracketed IPv6 is unwrapped so `$ip` matches the
    * canonical unbracketed literal.
    *
    * @param string $peer `stream_socket_get_name()` output.
    *
    * @return array{string, int} `[ip, port]`; port is 0 on malformed input.
    */
   public static function parse (string $peer): array
   {
      if (isset($peer[0]) && $peer[0] === '[') {
         $rb = strpos($peer, ']');
         if ($rb !== false) {
            return [
               substr($peer, 1, $rb - 1),
               (int) substr($peer, $rb + 2),
            ];
         }
         return [$peer, 0];
      }

      $separator = strrpos($peer, ':');
      if ($separator === false) {
         return [$peer, 0];
      }
      return [
         substr($peer, 0, $separator),
         (int) substr($peer, $separator + 1),
      ];
   }
}
