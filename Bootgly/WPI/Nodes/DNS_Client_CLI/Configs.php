<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI;


use function array_is_list;
use function count;
use function explode;
use function inet_pton;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function rtrim;
use function str_contains;
use function strlen;
use Closure;
use InvalidArgumentException;

use Bootgly\ABI\Argument;
use Bootgly\ABI\Configs as Configuring;
use Bootgly\ABI\IO\Resolver;


/**
 * Configuration of the DNS resolver node.
 *
 * Every value left `null` comes from the system: `resolv.conf` (nameservers,
 * search list, options), the `LOCALDOMAIN` and `RES_OPTIONS` environment
 * variables, `nsswitch.conf` (which sources answer which names) and the hosts
 * file — the files glibc's `getaddrinfo()` reads. A value given here wins over
 * the system's.
 *
 * Lookups share their state (cache, queries in flight) per configuration:
 * equal values, and seams that do the same thing (`Seams`): a `Probe` closure,
 * or each source of a `Resolver`, counts by its code, its `$this` (or the
 * class it was called on) and its captured values, so a Configs rebuilt per
 * request with a seam from the same line shares the state. A seam that
 * captures by reference or a wide array, holds a `static` variable, comes from
 * `eval()`'d code or a file changed in or after the second the process started, or shares its
 * first line with another closure counts by its object identity — a new one
 * per request is a new state.
 *
 * Named arguments only: `$Named` is a guard slot no named call ever fills.
 */
final class Configs implements Configuring
{
   // * Config
   /**
    * Nameserver IP literals (at most 3; an IPv6 link-local one may carry a zone id), or `null`
    * for `resolv.conf`'s.
    *
    * @var null|list<string>
    */
   public private(set) null|array $nameservers;
   /** The nameservers' port (UDP and TCP). */
   public private(set) int $port;
   /**
    * Search domains (at most 6), or `null` for `resolv.conf`'s (and `LOCALDOMAIN`).
    *
    * @var null|list<string>
    */
   public private(set) null|array $search;
   /** Dots a name needs to be tried as-is before the search list (`0`–`15`), or `null`. */
   public private(set) null|int $ndots;
   /** Seconds each try waits for an answer (`> 0`, at most `30`), or `null`. */
   public private(set) null|int|float $timeout;
   /** Rounds over the nameservers (`1`–`5`), or `null`. */
   public private(set) null|int $attempts;
   /** Whether each query starts at the next nameserver, or `null`. */
   public private(set) null|bool $rotate;
   /** Whether queries advertise EDNS(0) (a 1232-byte UDP payload), or `null`. */
   public private(set) null|bool $EDNS;
   /** Path of `resolv.conf`; `null` reads none. */
   public private(set) null|string $resolv;
   /** Path of the hosts file; `null` reads none. */
   public private(set) null|string $hosts;
   /** Path of `nsswitch.conf`; `null` reads none (`files dns`). */
   public private(set) null|string $nsswitch;
   /**
    * `LOCALDOMAIN` / `RES_OPTIONS` values, or `null` for the process environment.
    *
    * @var null|array<string,string>
    */
   public private(set) null|array $environment;
   /** The machine's host name, or `null` for `gethostname()`. */
   public private(set) null|string $hostname;
   /** The blocking system resolver (the ladder), or `null` for a default `Resolver`. */
   public private(set) null|Resolver $Resolver;
   /**
    * The source-address probe: `Closure(int $family): null|string` (`4` or `6`) returns the local
    * address the system would use to reach a global destination of that family, `null` when none.
    * `null` for the default probe (a connected UDP socket's local name; no packet is sent).
    */
   public private(set) null|Closure $Probe;


   /**
    * @param Argument $Named Guard slot — never pass it; it only rejects positional calls.
    * @param null|array<mixed> $nameservers 1 to 3 nameserver IP literals.
    * @param null|array<mixed> $search Up to 6 search domains (`.`, the root, searches nothing).
    * @param null|array<mixed> $environment `LOCALDOMAIN` / `RES_OPTIONS` values (strings by name).
    *
    * @throws InvalidArgumentException On a value out of range.
    */
   public function __construct (
      Argument $Named = Argument::Undefined,
      null|array $nameservers = null,
      int $port = 53,
      null|array $search = null,
      null|int $ndots = null,
      null|int|float $timeout = null,
      null|int $attempts = null,
      null|bool $rotate = null,
      null|bool $EDNS = null,
      null|string $resolv = '/etc/resolv.conf',
      null|string $hosts = '/etc/hosts',
      null|string $nsswitch = '/etc/nsswitch.conf',
      null|array $environment = null,
      null|string $hostname = null,
      null|Resolver $Resolver = null,
      null|Closure $Probe = null
   )
   {
      // ? Nameservers: 1–3 IP literals
      $addresses = null;
      if ($nameservers !== null) {
         if ($nameservers === [] || count($nameservers) > 3 || array_is_list($nameservers) === false) {
            throw new InvalidArgumentException('Configs nameservers must list 1 to 3 IP literals.');
         }
         $addresses = [];
         foreach ($nameservers as $nameserver) {
            if (is_string($nameserver) === false || self::check($nameserver) === false) {
               throw new InvalidArgumentException('Configs nameservers must be IP literals (an IPv6 one without brackets).');
            }
            $addresses[] = $nameserver;
         }
      }
      if ($port < 1 || $port > 65535) {
         throw new InvalidArgumentException('Configs port must be 1 to 65535.');
      }
      // ? Search: up to 6 names
      $domains = null;
      if ($search !== null) {
         if (count($search) > 6 || array_is_list($search) === false) {
            throw new InvalidArgumentException('Configs search must list at most 6 domains.');
         }
         $domains = [];
         foreach ($search as $domain) {
            // ? The root (`.`) is accepted and searches nothing, as in resolv.conf
            if ($domain === '.') {
               continue;
            }
            if (
               is_string($domain) === false || strlen(rtrim($domain, '.')) > 253
               || preg_match('/^[A-Za-z0-9_-]{1,63}(?:\.[A-Za-z0-9_-]{1,63})*\.?$/D', $domain) !== 1
            ) {
               throw new InvalidArgumentException('Configs search domains must be domain names (labels of 1 to 63 characters).');
            }
            $domains[] = $domain;
         }
      }
      if ($ndots !== null && ($ndots < 0 || $ndots > 15)) {
         throw new InvalidArgumentException('Configs ndots must be 0 to 15.');
      }
      if (
         $timeout !== null
         && ((is_float($timeout) && is_finite($timeout) === false) || $timeout <= 0 || $timeout > 30)
      ) {
         throw new InvalidArgumentException('Configs timeout must be above 0 and at most 30 seconds.');
      }
      if ($attempts !== null && ($attempts < 1 || $attempts > 5)) {
         throw new InvalidArgumentException('Configs attempts must be 1 to 5.');
      }
      $variables = null;
      if ($environment !== null) {
         $variables = [];
         foreach ($environment as $variable => $value) {
            if (is_string($variable) === false || is_string($value) === false) {
               throw new InvalidArgumentException('Configs environment must map names to strings.');
            }
            $variables[$variable] = $value;
         }
      }

      // * Config
      $this->nameservers = $addresses;
      $this->port = $port;
      $this->search = $domains;
      $this->ndots = $ndots;
      $this->timeout = is_int($timeout) ? (float) $timeout : $timeout;
      $this->attempts = $attempts;
      $this->rotate = $rotate;
      $this->EDNS = $EDNS;
      $this->resolv = $resolv;
      $this->hosts = $hosts;
      $this->nsswitch = $nsswitch;
      $this->environment = $variables;
      $this->hostname = $hostname;
      $this->Resolver = $Resolver;
      $this->Probe = $Probe;
   }

   /**
    * Check that a value is a nameserver IP literal: IPv4, or IPv6 without brackets with an optional
    * zone id.
    */
   public static function check (string $nameserver): bool
   {
      $address = $nameserver;
      if (str_contains($nameserver, '%')) {
         [$address, $zone] = explode('%', $nameserver, 2);
         if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $zone) !== 1) {
            return false;
         }
         // ? A zone id belongs to an IPv6 address only
         if (@inet_pton($address) === false || str_contains($address, ':') === false) {
            return false;
         }
      }

      // :
      return @inet_pton($address) !== false;
   }
}
