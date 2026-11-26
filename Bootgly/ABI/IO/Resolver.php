<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\IO;


use const DNS_AAAA;
use const SOCK_STREAM;
use function array_unique;
use function array_values;
use function dns_get_record;
use function function_exists;
use function gethostbynamel;
use function inet_ntop;
use function inet_pton;
use function is_array;
use function is_string;
use function rtrim;
use function socket_addrinfo_explain;
use function socket_addrinfo_lookup;
use function str_contains;
use function str_ends_with;
use function strlen;
use function strpbrk;
use function strtolower;
use function substr;
use Closure;
use Throwable;


/**
 * The system's blocking name resolver: a host name to its IP addresses,
 * synchronously, through the resolver PHP itself dials with.
 *
 * One ladder: an IP literal needs no lookup (returned canonical, an
 * IPv4-mapped IPv6 address as its IPv4 address); a name goes to
 * `getaddrinfo()` (ext-sockets), or without ext-sockets to the system's IPv4
 * answers (hosts file included) followed by the name's DNS AAAA records.
 * Every call blocks until the system answers: inside a server worker, a
 * non-blocking resolver belongs on the worker's reactor instead.
 *
 * It only resolves — whether an address may be dialed (loopback, private,
 * reserved ranges) is the caller's policy.
 */
final class Resolver
{
   // * Data
   /**
    * Stand-in for `getaddrinfo()`, answering in `socket_addrinfo_explain()`'s
    * shape; `null` for the real one (when ext-sockets is loaded); `false` for none.
    * @var false|null|Closure(string): (false|array<int,mixed>)
    */
   private false|null|Closure $Lookup;
   /**
    * Stand-in for the system's IPv4 answers (`gethostbynamel()`); `null` for the real one.
    * @var null|Closure(string): (false|array<int,mixed>)
    */
   private null|Closure $List;
   /**
    * Stand-in for the DNS AAAA records of an absolute name (`dns_get_record()`'s
    * shape); `null` for the real one.
    * @var null|Closure(string): (false|array<int,mixed>)
    */
   private null|Closure $Query;


   /**
    * Create a resolver of the system's resolver — or of the given sources,
    * each one a stand-in for the PHP function it names.
    *
    * The real sources are bound at each lookup, never stored: a resolver of
    * the system's resolver serializes.
    *
    * @param false|null|Closure $Lookup `getaddrinfo()` (`socket_addrinfo_lookup()` + `socket_addrinfo_explain()`):
    *    `null` for the real one when ext-sockets is loaded; `false` to resolve without it.
    * @param null|Closure $List `gethostbynamel()`; `null` for the real one.
    * @param null|Closure $Query `dns_get_record($name, DNS_AAAA)` for an absolute name; `null` for the real one.
    */
   public function __construct (
      false|null|Closure $Lookup = null,
      null|Closure $List = null,
      null|Closure $Query = null
   )
   {
      // * Data
      $this->Lookup = $Lookup;
      $this->List = $List;
      $this->Query = $Query;
   }

   /**
    * Canonicalize an IP literal: IPv4 dotted quad, or IPv6 in its shortest
    * form; an IPv4-mapped IPv6 address becomes the IPv4 address it carries.
    *
    * @param string $IP An IPv4 or IPv6 literal, an IPv6 one without brackets or zone.
    *
    * @return null|string The canonical literal; `null` when `$IP` is not one.
    */
   public static function normalize (string $IP): null|string
   {
      // ? A NUL byte is never part of a literal
      if (str_contains($IP, "\0")) {
         return null;
      }

      $packed = @inet_pton($IP);
      if ($packed === false) {
         return null;
      }
      // ! An IPv4-mapped IPv6 address (`::ffff:0:0/96`) is its IPv4 address
      if (
         strlen($packed) === 16
         && substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff"
      ) {
         $packed = substr($packed, 12);
      }

      $normalized = inet_ntop($packed);

      // :
      return is_string($normalized) ? $normalized : null;
   }

   /**
    * Resolve a host to its canonical IP addresses — blocking until the
    * system answers.
    *
    * An IP literal is returned canonical without a lookup. A name goes to
    * `getaddrinfo()` when ext-sockets is loaded — its answer is final, a
    * failed lookup never falls back. Without ext-sockets: the system's IPv4
    * answers (hosts file included) first, then the name's DNS AAAA records,
    * asked for the absolute name (no search list) and never for `localhost`
    * or a `*.localhost` name.
    *
    * @param string $host A name (a terminal dot kept: no search list) or an IP literal (IPv6 without brackets).
    *
    * @return null|array<int,string> The canonical addresses in resolver order, each once; `[]` when
    *    nothing resolves (also an empty name, one longer than 253 characters, or one holding a NUL byte,
    *    `%`, `[` or `]` — refused without a lookup); `null` when an answer is not an IP address (void).
    */
   public function resolve (string $host): null|array
   {
      // ? An IP literal needs no lookup
      $literal = self::normalize($host);
      if ($literal !== null) {
         return [$literal];
      }

      // ? A host no resolver should be asked about
      if (
         $host === ''
         || strlen(str_ends_with($host, '.') ? substr($host, 0, -1) : $host) > 253
         || strpbrk($host, "\0%[]") !== false
      ) {
         return [];
      }

      // ! The sources — `SOCK_STREAM` is read only inside the real
      //   getaddrinfo, which exists only with ext-sockets
      $Lookup = $this->Lookup ?? (
         function_exists('socket_addrinfo_lookup')
            ? static function (string $host): false|array {
               try {
                  $AddressInfos = @socket_addrinfo_lookup($host, null, [
                     'ai_socktype' => SOCK_STREAM,
                  ]);
                  if (is_array($AddressInfos) === false) {
                     return false;
                  }

                  $answers = [];
                  foreach ($AddressInfos as $AddressInfo) {
                     $answers[] = socket_addrinfo_explain($AddressInfo);
                  }

                  return $answers;
               }
               catch (Throwable) {
                  return false;
               }
            }
            : false
      );

      // @ The system resolver — the one PHP's own dial would have used
      $answers = [];
      if ($Lookup !== false) {
         $infos = $Lookup($host);
         foreach (is_array($infos) ? $infos : [] as $info) {
            $socket = is_array($info) ? ($info['ai_addr'] ?? null) : null;
            $IP = is_array($socket)
               ? ($socket['sin_addr'] ?? ($socket['sin6_addr'] ?? null))
               : null;
            if (is_string($IP)) {
               $answers[] = $IP;
            }
         }
      }
      // ! Without ext-sockets: the IPv4 answers (hosts file included) first,
      //   then the DNS AAAA records of the absolute name — never `localhost`
      else {
         $List = $this->List ?? static function (string $host): false|array {
            try {
               return @gethostbynamel($host);
            }
            catch (Throwable) {
               return false;
            }
         };
         $IPv4 = $List($host);
         foreach (is_array($IPv4) ? $IPv4 : [] as $IP) {
            $answers[] = $IP;
         }

         $name = strtolower(rtrim($host, '.'));
         if ($name !== '' && $name !== 'localhost' && str_ends_with($name, '.localhost') === false) {
            $Query = $this->Query ?? static function (string $name): false|array {
               try {
                  return @dns_get_record($name, DNS_AAAA);
               }
               catch (Throwable) {
                  return false;
               }
            };
            $records = $Query("{$name}.");
            foreach (is_array($records) ? $records : [] as $record) {
               $IP = is_array($record) ? ($record['ipv6'] ?? null) : null;
               if (is_string($IP)) {
                  $answers[] = $IP;
               }
            }
         }
      }

      // ! An answer that is not an address voids the whole lookup
      $addresses = [];
      foreach ($answers as $IP) {
         $address = is_string($IP) ? self::normalize($IP) : null;
         if ($address === null) {
            return null;
         }
         $addresses[] = $address;
      }

      // :
      return array_values(array_unique($addresses));
   }
}
