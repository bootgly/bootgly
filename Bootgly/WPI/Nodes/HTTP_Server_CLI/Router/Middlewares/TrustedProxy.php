<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares;


use const FILTER_VALIDATE_IP;
use function chr;
use function count;
use function ctype_digit;
use function explode;
use function filter_var;
use function in_array;
use function inet_pton;
use function intdiv;
use function preg_match;
use function str_pad;
use function str_repeat;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use Closure;
use InvalidArgumentException;

use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy\Strategies;


/**
 * Resolve the application-facing client address (`$Request->address`) and
 * scheme (`$Request->scheme`) from forwarding headers — only when the
 * immutable socket peer (`$Request->peer`) is in the trust list.
 *
 * Where the address is read from is the `strategy` (see `Strategies`): the
 * right-most untrusted `X-Forwarded-For` hop by default, the left-most entry,
 * or one named `header`. A `relay` names the visitor header of a trusted
 * front proxy (a CDN in front of the edge), read when the address the
 * strategy picked from forwarded data is itself a trusted proxy.
 *
 * Fail closed: a malformed or missing value never replaces the address, and
 * `$Request->peer` is never altered.
 */
class TrustedProxy implements Middleware
{
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class, global: true);
         }

         return $this->Logger;
      }
   }


   // * Config
   /** @var array<string> */
   public private(set) array $proxies;
   /**
    * Where the client address is read from once the socket peer is trusted.
    */
   public private(set) Strategies $strategy;
   /**
    * The header `Strategies::Header` reads (`X-Real-IP`, `CF-Connecting-IP`,
    * …); null under every other strategy.
    */
   public private(set) null|string $header;
   /**
    * The visitor header of a trusted front proxy (`CF-Connecting-IP`), read
    * when the address the strategy picked is itself a trusted proxy; null
    * disables the relay.
    */
   public private(set) null|string $relay;

   // * Data
   // ...

   // * Metadata
   //   True when the constructor fell back to the localhost default trust list
   //   (the caller passed no explicit `$proxies`); drives a one-time hardening
   //   warning when such a default actually trusts a peer (audit F-3).
   protected bool $localhostDefault;
   /**
    * Packed `[network, mask]` pairs compiled once from `$proxies` (4 bytes per
    * IPv4 entry, 16 per IPv6) — matching is a byte AND, never a per-request
    * parse of the configured list.
    *
    * @var array<array{string, string}>
    */
   private array $ranges;


   /**
    * @param null|array<string> $proxies Trusted proxy IP literals and CIDR
    *   ranges (`10.0.0.8`, `173.245.48.0/20`, `2400:cb00::/32`); a literal
    *   counts as `/32` | `/128`. When null, falls back to the localhost
    *   default (`127.0.0.1`, `::1`) and logs a one-time warning the first time
    *   it trusts a forwarded header — set an explicit list in production.
    * @param Strategies $strategy Where the client address is read from once
    *   the socket peer is trusted; `Rightmost` (the default) keeps the
    *   right-most `X-Forwarded-For` walk with the `X-Real-IP` fallback.
    * @param null|string $header The header `Strategies::Header` reads —
    *   required by that strategy, refused by the others.
    * @param null|string $relay The visitor header of a trusted front proxy,
    *   read when the address the strategy picked from forwarded data is itself
    *   a trusted proxy. Never read when nothing was picked (the socket peer);
    *   a visitor inside the trust list is refused. Every `proxies` entry is
    *   trusted to author this header — keep the list to real proxies.
    * @throws InvalidArgumentException When a `proxies` entry is not a valid
    *   IP literal or CIDR range, when `Strategies::Header` has no `header` (or
    *   another strategy has one), or when `header`/`relay` is not an RFC 9110
    *   field-name token.
    */
   public function __construct (
      null|array $proxies = null,
      Strategies $strategy = Strategies::Rightmost,
      null|string $header = null,
      null|string $relay = null
   )
   {
      // ? A strategy and its header come together: `Header` without one has
      //   nothing to read, and a `header` under another strategy would be
      //   silently ignored — both are config bugs that must fail at boot.
      if ($strategy === Strategies::Header && $header === null) {
         throw new InvalidArgumentException(
            'TrustedProxy: Strategies::Header needs a `header` name.'
         );
      }
      if ($strategy !== Strategies::Header && $header !== null) {
         throw new InvalidArgumentException(
            'TrustedProxy: `header` is read only by Strategies::Header.'
         );
      }
      // ? Field names are RFC 9110 §5.1 tokens — this also refuses NUL, CR,
      //   LF, whitespace and the empty string.
      foreach (['header' => $header, 'relay' => $relay] as $input => $field) {
         if (
            $field !== null
            && preg_match('/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/', $field) !== 1
         ) {
            throw new InvalidArgumentException(
               "TrustedProxy: `{$input}` must be an HTTP field name (RFC 9110 token)."
            );
         }
      }

      // * Config
      $this->strategy = $strategy;
      $this->header = $header;
      $this->relay = $relay;
      if ($proxies === null) {
         $this->proxies = ['127.0.0.1', '::1'];
         $this->localhostDefault = true;
      }
      else {
         $this->proxies = $proxies;
         $this->localhostDefault = false;
      }

      // * Metadata
      // ! Compile the trust list at construction — a malformed entry is a
      //   config bug and must fail at boot, not silently distrust in traffic.
      $ranges = [];
      foreach ($this->proxies as $proxy) {
         $ranges[] = self::parse($proxy);
      }
      $this->ranges = $ranges;

      // ! The route cache answers before middleware runs, so it must keep
      //   apart — and never store — requests that carry a forwarding field
      //   this instance reads. X-Forwarded-For, X-Forwarded-Proto and
      //   X-Real-IP are always covered; a configured `header`/`relay`
      //   (`CF-Connecting-IP`) joins them here.
      foreach ([$header, $relay] as $field) {
         if ($field !== null) {
            Cache::$forwarded[strtolower($field)] = true;
         }
      }
   }

   /**
    * Compile one trust-list entry — an IP literal (implied `/32` | `/128`) or
    * a CIDR range — into a packed `[network, mask]` pair.
    *
    * @param string $proxy The entry as configured.
    *
    * @return array{string, string} Packed network and mask, family-sized.
    *
    * @throws InvalidArgumentException When the entry is not a valid IP
    *   literal or CIDR range.
    */
   protected static function parse (string $proxy): array
   {
      // ! Whitespace-tolerant, like the chain walk — lists loaded from files
      //   routinely carry a trailing newline
      $entry = trim($proxy);
      $address = $entry;
      $prefix = null;
      $slash = strpos($entry, '/');
      if ($slash !== false) {
         $address = substr($entry, 0, $slash);
         $prefix = substr($entry, $slash + 1);
      }

      // ? The address part must be a valid IPv4/IPv6 literal. A NUL byte is
      //   rejected here because `inet_pton()` raises a ValueError on it — the
      //   documented InvalidArgumentException must be the only failure mode.
      $packed = strpos($address, "\x00") === false
         ? @inet_pton($address)
         : false;
      if ($packed === false) {
         throw new InvalidArgumentException(
            "TrustedProxy: invalid proxy entry `{$entry}` — expected an IP literal or a CIDR range."
         );
      }

      // ? The prefix must be canonical digits within the family bit length.
      //   Leading zeros are refused: `/00` would otherwise compile to `/0` and
      //   silently trust the whole family.
      $bits = strlen($packed) * 8;
      if ($prefix === null) {
         $length = $bits;
      }
      else if (
         ctype_digit($prefix) === true
         && ($prefix === '0' || $prefix[0] !== '0')
         && (int) $prefix <= $bits
      ) {
         $length = (int) $prefix;
      }
      else {
         throw new InvalidArgumentException(
            "TrustedProxy: invalid CIDR prefix in `{$entry}` — expected /0../{$bits}."
         );
      }

      // ! An IPv4-mapped entry (`::ffff:10.0.0.2`) unwraps to its embedded
      //   IPv4 — peers arrive canonicalized to the dotted quad (Peer::parse),
      //   so both sides must share one representation. A prefix below /96
      //   spans beyond the mapped block and stays a plain IPv6 range.
      if (
         strlen($packed) === 16 && $length >= 96
         && substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xFF\xFF"
      ) {
         $packed = substr($packed, 12);
         $length -= 96;
      }

      // @ Build the mask and zero the host bits of the network
      //   (the same prefix math as RateLimit::mask() — kept in sync by hand)
      $bytes = strlen($packed);
      $mask = str_repeat("\xFF", intdiv($length, 8));
      $remainder = $length % 8;
      if ($remainder !== 0) {
         $mask .= chr((0xFF << (8 - $remainder)) & 0xFF);
      }
      $mask = str_pad($mask, $bytes, "\x00");

      // :
      return [$packed & $mask, $mask];
   }

   /**
    * Check an IP against the compiled trust list.
    *
    * @param string $IP A textual IPv4/IPv6 address.
    *
    * @return bool True when the IP falls inside any configured range.
    */
   protected function trust (string $IP): bool
   {
      // ? Never trust what does not parse — a NUL byte is screened first
      //   because `inet_pton()` raises a ValueError instead of returning false
      $packed = strpos($IP, "\x00") === false
         ? @inet_pton($IP)
         : false;
      if ($packed === false) {
         return false;
      }

      // ! An IPv4-mapped candidate (`::ffff:203.0.113.7`) also answers to its
      //   embedded IPv4. The socket peer never arrives in this form —
      //   Peer::parse() canonicalizes it — but X-Forwarded-For/X-Real-IP
      //   values written by upstream proxies can.
      $mapped = null;
      if (
         strlen($packed) === 16
         && substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xFF\xFF"
      ) {
         $mapped = substr($packed, 12);
      }

      // @@
      foreach ($this->ranges as [$network, $mask]) {
         $bytes = strlen($network);
         if (strlen($packed) === $bytes && ($packed & $mask) === $network) {
            return true;
         }
         if ($mapped !== null && $bytes === 4 && ($mapped & $mask) === $network) {
            return true;
         }
      }

      // :
      return false;
   }

   /**
    * Resolve `$Request->address` (by `strategy`, then `relay`) and
    * `$Request->scheme` (from `X-Forwarded-Proto`) when the socket peer is
    * trusted; pass the request on untouched otherwise.
    *
    * @param object $Request The HTTP Request.
    * @param object $Response The HTTP Response.
    * @param Closure $next The next middleware or handler.
    *
    * @return object The Response returned by `$next`.
    */
   public function process (object $Request, object $Response, Closure $next): object
   {
      // ? Only resolve if request comes from trusted proxy.
      $peer = $Request->peer; // @phpstan-ignore-line
      if ($this->trust($peer) === false) {
         return $next($Request, $Response);
      }

      // ! One-time hardening warning (audit F-3): a peer is being trusted under
      //   the DEFAULT localhost list. In production the trust list MUST be set
      //   explicitly — otherwise anything able to reach the server from
      //   127.0.0.1/::1 (sidecar, SSRF pivot, dev port-forward) can spoof
      //   `$Request->address` via X-Forwarded-For.
      if ($this->localhostDefault) {
         static $warned = false;
         if ($warned === false) {
            $warned = true;
            $this->Logger->log(
               warning: 'TrustedProxy: trusting forwarded headers using the DEFAULT '
               . 'localhost proxy list (127.0.0.1, ::1). Set an explicit '
               . '`proxies` list in production to prevent X-Forwarded-For '
               . 'spoofing from co-located/localhost clients.'
            );
         }
      }

      // ! The candidate is a valid IP read from forwarded data, or null —
      //   never the socket peer itself
      $Header = $Request->Header; // @phpstan-ignore-line
      $candidate = null;

      // @ Resolve the candidate by strategy
      switch ($this->strategy) {
         case Strategies::Rightmost:
            $forwarded = $Header->get('X-Forwarded-For');
            if ($forwarded !== null) {
               // ? RFC 7239 §5.2 — walk the chain from the right, skipping
               //   trusted hops; the first untrusted entry is the real client.
               //   Returning the left-most entry trusts whatever an attacker
               //   wrote and is spoofable whenever there are ≥ 2 trusted hops.
               $IPs = explode(',', $forwarded);
               $hop = null;
               $i = count($IPs) - 1;
               // @@
               for (; $i >= 0; $i--) {
                  $entry = trim($IPs[$i]);
                  if ($entry === '') {
                     continue;
                  }
                  if ($this->trust($entry) === true) {
                     $hop = $entry;
                     continue;
                  }
                  // ! Fail closed: an invalid untrusted hop ends the walk
                  //   without a rewrite — every entry left of it is written
                  //   by whoever wrote it, so walking on lets the client choose
                  if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
                     $candidate = $entry;
                  }
                  break;
               }
               // ? A chain of trusted hops only: the left-most one is a
               //   trusted proxy picked from forwarded data — a candidate for
               //   the relay, never an address by itself (unchanged default)
               if ($i < 0 && $this->relay !== null) {
                  $candidate = $hop;
               }
            }
            else {
               // @ Try X-Real-IP
               $realIP = $Header->get('X-Real-IP');
               if ($realIP !== null) {
                  $entry = trim($realIP);
                  if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
                     $candidate = $entry;
                  }
               }
            }
            break;
         case Strategies::Leftmost:
            $forwarded = $Header->get('X-Forwarded-For');
            if ($forwarded !== null) {
               // ! The first entry only — garbage there is never skipped to
               //   reach a later (equally client-writable) entry
               $comma = strpos($forwarded, ',');
               $entry = trim($comma === false ? $forwarded : substr($forwarded, 0, $comma));
               if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
                  $candidate = $entry;
               }
            }
            break;
         case Strategies::Header:
            // ! A repeated field arrives comma-joined and fails validation
            $value = $Header->get((string) $this->header);
            if ($value !== null) {
               $entry = trim($value);
               if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
                  $candidate = $entry;
               }
            }
            break;
      }

      // @ Relay: the picked address is a trusted front proxy — its visitor
      //   header names the client. A missing or malformed visitor leaves the
      //   address alone (the socket peer), never the front proxy's own; so
      //   does a visitor inside the trust list — a front proxy names the
      //   client, never another proxy (an internal address).
      if ($candidate !== null && $this->relay !== null && $this->trust($candidate) === true) {
         $candidate = null;
         $visitor = $Header->get($this->relay);
         if ($visitor !== null) {
            $entry = trim($visitor);
            if (
               filter_var($entry, FILTER_VALIDATE_IP) !== false
               && $this->trust($entry) === false
            ) {
               $candidate = $entry;
            }
         }
      }

      if ($candidate !== null) {
         $Request->address = $candidate; // @phpstan-ignore-line
      }

      // @ Try X-Forwarded-Proto
      $proto = $Header->get('X-Forwarded-Proto');
      if ($proto !== null) {
         $candidate = strtolower(trim($proto));
         // ! Only accept valid HTTP schemes
         if (in_array($candidate, ['http', 'https'], true)) {
            $Request->scheme = $candidate; // @phpstan-ignore-line
         }
      }

      // :
      return $next($Request, $Response);
   }
}
