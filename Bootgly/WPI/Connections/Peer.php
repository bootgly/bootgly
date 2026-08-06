<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Connections;


use const FILTER_FLAG_IPV4;
use const FILTER_VALIDATE_IP;
use function filter_var;
use function strncasecmp;
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
    * canonical unbracketed literal, and an IPv4-mapped address
    * (RFC 4291 §2.5.5.2 — every dual-stack listener presents an IPv4 hop
    * as `::ffff:a.b.c.d`) canonicalizes to the dotted quad, so ONE
    * representation of a client IP crosses the layer.
    *
    * @param string $peer `stream_socket_get_name()` output.
    *
    * @return array{string, int} `[ip, port]`; port is 0 on malformed input.
    */
   public static function parse (string $peer): array
   {
      if (isset($peer[0]) && $peer[0] === '[') {
         $rb = strpos($peer, ']');
         if ($rb === false) {
            return [$peer, 0];
         }

         $ip = substr($peer, 1, $rb - 1);
         $port = (int) substr($peer, $rb + 2);
      }
      else {
         $separator = strrpos($peer, ':');
         if ($separator === false) {
            return [$peer, 0];
         }

         $ip = substr($peer, 0, $separator);
         $port = (int) substr($peer, $separator + 1);
      }

      // ? An IPv4-mapped peer — Bootgly listeners are dual-stack
      //   ('ipv6_v6only' => false), so on a '[::]' bind every IPv4 hop
      //   arrives as '::ffff:a.b.c.d' — canonicalizes to the dotted quad
      //   the operator actually writes in TrustedProxy lists, blacklists
      //   and ACLs (MW-6). Only the dotted textual form getpeername()
      //   produces is unwrapped; anything else passes through verbatim.
      if (strncasecmp($ip, '::ffff:', 7) === 0) {
         $mapped = substr($ip, 7);
         if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ip = $mapped;
         }
      }

      // :
      return [$ip, $port];
   }
}
