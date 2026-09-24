<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data;


use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;
use const PREG_UNMATCHED_AS_NULL;
use function array_pop;
use function count;
use function explode;
use function filter_var;
use function implode;
use function inet_ntop;
use function inet_pton;
use function preg_match;
use function str_ends_with;
use function str_starts_with;
use function strcspn;
use function strlen;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use Stringable;
use ValueError;


/**
 * RFC 3986 URI value — one absolute, hierarchical URI with a host.
 *
 * `scheme://[userinfo@]host[:port]path[?query]`, parsed into its components
 * and resolved against URI-references by §5.2 (strict parser, §5.2.3 merge,
 * §5.2.4 dot-segment removal). This is the one resolver every redirect
 * follower uses, so a `Location` header means the same thing everywhere.
 *
 * Normalized on parse: the scheme and the host are lowercased and an IPv6
 * literal is canonicalized (`[0:0::1]` → `[::1]`). Path and query are kept as
 * sent; dot-segments are removed only when a reference is resolved.
 *
 * Refused (`ValueError` from the constructor, `null` from `parse()` and
 * `resolve()`): control bytes, space, DEL and backslash anywhere; a URI
 * without a scheme or an authority; an empty or non-ASCII host; a port
 * outside 1–65535; IPv6 zone identifiers, IPvFuture and bracketed IPv4.
 *
 * Deliberate deviations from the RFC 3986 §5.4 examples: an opaque or
 * authority-less result (`g:h`, `http:g`, `javascript:x`, `file:///x`) is
 * refused — nothing here can be dialed — and fragments are dropped, since
 * they never travel in a request. The scheme itself is the caller's policy:
 * `gopher://h/x` is a valid URI.
 */
final class URI implements Stringable
{
   /** RFC 3986 Appendix B: scheme, authority, path, query (the fragment is matched and dropped). */
   private const string PATTERN = '~^(?:([^:/?#]+):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#.*)?$~sD';
   /** C0 controls, space, DEL and backslash — never part of a URI. */
   private const string INVALID =
      "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F"
      . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F"
      . "\x20\x7F\\";
   /** RFC 3986 §3.1 scheme. */
   private const string SCHEME = '/^[a-z][a-z0-9+.\-]*$/iD';
   /** RFC 3986 §3.2.1 userinfo. */
   private const string USERINFO = "/^(?:[a-z0-9\\-._~!$&'()*+,;=:]|%[0-9a-f]{2})*$/iD";
   /** RFC 3986 §3.2.2 reg-name (IPv4 included), never empty. */
   private const string HOST = "/^(?:[a-z0-9\\-._~!$&'()*+,;=]|%[0-9a-f]{2})+$/iD";

   // * Data
   // # Components
   /** Scheme, lowercased (`https`). */
   public private(set) string $scheme;
   /** User information before the `@`, as sent; null when absent. */
   public private(set) null|string $userinfo;
   /** Host, lowercased and never empty; an IPv6 literal canonical and bracketed (`[::1]`). */
   public private(set) string $host;
   /** Port as written (1–65535); null when absent or empty — the scheme's default applies. */
   public private(set) null|int $port;
   /** Path as sent: empty or starting with `/`. */
   public private(set) string $path;
   /** Query after the `?`, as sent; null when absent, `''` when present and empty. */
   public private(set) null|string $query;
   // # Derived
   /** Authority: `[userinfo@]host[:port]`. */
   public string $authority {
      get {
         $authority = $this->userinfo === null ? $this->host : "{$this->userinfo}@{$this->host}";

         return $this->port === null ? $authority : "{$authority}:{$this->port}";
      }
   }
   /**
    * Host in comparison form: an IPv6 literal without its brackets and a
    * fully-qualified name without its trailing dot (`[::1]` → `::1`,
    * `example.com.` → `example.com`). Two URIs name the same host when their
    * `hostname`s are equal.
    */
   public string $hostname {
      get {
         $hostname = $this->host[0] === '[' ? substr($this->host, 1, -1) : $this->host;

         return str_ends_with($hostname, '.') ? substr($hostname, 0, -1) : $hostname;
      }
   }


   /**
    * Parse one absolute hierarchical URI.
    *
    * @param string $URI `scheme://[userinfo@]host[:port]path[?query][#fragment]`.
    *
    * @throws ValueError When the string is not one (the message never echoes it).
    */
   public function __construct (string $URI)
   {
      // ? Control bytes, space, DEL and backslash are never part of a URI
      if (strcspn($URI, self::INVALID) !== strlen($URI)) {
         throw new ValueError('Invalid URI: it carries a control byte, a space or a backslash.');
      }

      // ! Appendix B always matches — the components are judged below
      preg_match(self::PATTERN, $URI, $matches, PREG_UNMATCHED_AS_NULL);
      $scheme = $matches[1] ?? null;
      $authority = $matches[2] ?? null;

      // ? Absolute and hierarchical: a scheme and an authority
      if ($scheme === null || preg_match(self::SCHEME, $scheme) !== 1) {
         throw new ValueError('Invalid URI: no valid scheme.');
      }
      if ($authority === null) {
         throw new ValueError('Invalid URI: no authority.');
      }

      // ! User information ends at the first `@` (a second one fails the host)
      $userinfo = null;
      $at = strpos($authority, '@');
      if ($at !== false) {
         $userinfo = substr($authority, 0, $at);
         $authority = substr($authority, $at + 1);

         if (preg_match(self::USERINFO, $userinfo) !== 1) {
            throw new ValueError('Invalid URI: malformed user information.');
         }
      }

      // @ Host and port
      if (str_starts_with($authority, '[')) {
         // # IP literal — IPv6 only (no zone identifier, no IPvFuture)
         $close = strpos($authority, ']');
         if ($close === false) {
            throw new ValueError('Invalid URI: unterminated IP literal.');
         }

         $literal = substr($authority, 1, $close - 1);
         $rest = substr($authority, $close + 1);
         if ($rest !== '' && $rest[0] !== ':') {
            throw new ValueError('Invalid URI: junk after the IP literal.');
         }

         $packed = filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false
            ? false
            : inet_pton($literal);
         $canonical = $packed === false ? false : inet_ntop($packed);
         if ($canonical === false) {
            throw new ValueError('Invalid URI: malformed IPv6 literal.');
         }

         $host = "[{$canonical}]";
         $port = $rest === '' ? null : substr($rest, 1);
      }
      else {
         // # Registered name or IPv4 — the port follows the last `:`
         $colon = strrpos($authority, ':');
         $host = $colon === false ? $authority : substr($authority, 0, $colon);
         $port = $colon === false ? null : substr($authority, $colon + 1);

         if (preg_match(self::HOST, $host) !== 1 || $host === '.') {
            throw new ValueError('Invalid URI: malformed host.');
         }

         $host = strtolower($host);
      }

      // ? An empty port is the scheme's default; any other one is 1–65535
      if ($port === '') {
         $port = null;
      }
      if ($port !== null) {
         if (preg_match('/^\d{1,5}$/D', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            throw new ValueError('Invalid URI: port out of range.');
         }

         $port = (int) $port;
      }

      // * Data
      $this->scheme = strtolower($scheme);
      $this->userinfo = $userinfo;
      $this->host = $host;
      $this->port = $port;
      $this->path = $matches[3] ?? '';
      $this->query = $matches[4] ?? null;
   }

   /**
    * Parse one absolute hierarchical URI, or nothing when the string is not one.
    *
    * The form for untrusted input — a `Location` header, a configured
    * endpoint — where "not a URI" is an ordinary answer rather than an error.
    *
    * @param string $URI A candidate string.
    *
    * @return null|self
    */
   public static function parse (string $URI): null|self
   {
      try {
         return new self($URI);
      }
      catch (ValueError) {
         return null;
      }
   }

   /**
    * Resolve a URI-reference against this URI (RFC 3986 §5.2.2, strict).
    *
    * Absolute and network-path references replace the authority; relative
    * ones are merged against this path; every result has its dot-segments
    * removed. This URI is not changed.
    *
    * @param string $reference A URI-reference, e.g. a `Location` header value.
    *
    * @return null|self The target, or null when it is not an absolute
    *                   hierarchical URI with a host (`g:h`, `http:g`,
    *                   `file:///x`, control bytes, a malformed authority).
    */
   public function resolve (string $reference): null|self
   {
      // ? The same bytes a URI never carries
      if (strcspn($reference, self::INVALID) !== strlen($reference)) {
         return null;
      }

      // !
      preg_match(self::PATTERN, $reference, $matches, PREG_UNMATCHED_AS_NULL);
      $scheme = $matches[1] ?? null;
      $authority = $matches[2] ?? null;
      $path = $matches[3] ?? '';
      $query = $matches[4] ?? null;

      // @ RFC 3986 §5.2.2
      if ($scheme !== null) {
         // ? Opaque or authority-less (`g:h`, `http:g`) — nothing to dial
         if ($authority === null) {
            return null;
         }

         $reduced = self::reduce($path);
         $target = "{$scheme}://{$authority}{$reduced}";
      }
      else if ($authority !== null) {
         // # Network-path reference — this scheme, its authority
         $reduced = self::reduce($path);
         $target = "{$this->scheme}://{$authority}{$reduced}";
      }
      else {
         $origin = "{$this->scheme}://{$this->authority}";

         if ($path === '') {
            // # Same document — the query falls back to this one
            $target = "{$origin}{$this->path}";
            $query ??= $this->query;
         }
         else if ($path[0] === '/') {
            $reduced = self::reduce($path);
            $target = "{$origin}{$reduced}";
         }
         else {
            // # §5.2.3 merge — an empty base path merges as `/`
            $slash = strrpos($this->path, '/');
            $directory = $slash === false ? '/' : substr($this->path, 0, $slash + 1);
            $reduced = self::reduce("{$directory}{$path}");
            $target = "{$origin}{$reduced}";
         }
      }

      if ($query !== null) {
         $target = "{$target}?{$query}";
      }

      // : One validation path — the target is parsed like any other URI
      return self::parse($target);
   }

   /**
    * The URI as a string (RFC 3986 §5.3 recomposition, fragment-free).
    */
   public function __toString (): string
   {
      $URI = "{$this->scheme}://{$this->authority}{$this->path}";

      // :
      return $this->query === null ? $URI : "{$URI}?{$this->query}";
   }

   /**
    * RFC 3986 §5.2.4 remove_dot_segments, in one linear pass.
    *
    * Paths here are empty or absolute (a merge always starts at `/`). Segments
    * are kept verbatim, so meaningful empty segments (`//`) survive and
    * percent-encoded dots (`%2e`) are left alone; `..` never climbs above the
    * root.
    */
   private static function reduce (string $path): string
   {
      // !
      $Segments = explode('/', $path);
      $last = count($Segments) - 1;
      $output = [];

      // @@
      foreach ($Segments as $index => $segment) {
         if ($segment === '.' || $segment === '..') {
            // ? `..` drops the previous segment — the leading root one excepted
            if ($segment === '..' && count($output) > 1) {
               array_pop($output);
            }
            // ? A final `.` or `..` still ends the path with a `/`
            if ($index === $last) {
               $output[] = '';
            }

            continue;
         }

         $output[] = $segment;
      }

      // :
      return implode('/', $output);
   }
}
