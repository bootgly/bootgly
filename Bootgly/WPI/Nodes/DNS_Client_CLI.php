<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes;


use function array_key_first;
use function count;
use function explode;
use function getmypid;
use function in_array;
use function is_string;
use function preg_match;
use function rtrim;
use function serialize;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpbrk;
use function strtolower;
use function substr;
use function substr_count;
use Closure;
use Fiber;

use Bootgly\ABI\Configs as Configuring;
use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\IO\Resolver;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\WPI\Endpoints\Configurable;
use Bootgly\WPI\Events;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Answer;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Answer\Outcomes;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Cache;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Configs;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Query;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Seams;
use Bootgly\WPI\Nodes\DNS_Client_CLI\State;


/**
 * Asynchronous DNS resolver.
 *
 * Inside a server worker, a lookup made from a Fiber with a wait bridge
 * (`fn ($value = null) => $Response->wait($value)`) parks on the worker's
 * event loop while the query is in flight: the worker keeps serving other
 * requests. Anywhere else — no worker loop, no bridge, outside a Fiber — the
 * lookup blocks: `resolve()` asks the system resolver (`getaddrinfo()`),
 * `query()` waits on its socket.
 *
 * Every instance in a process shares one state per configuration — the
 * cache, the queries in flight, the hosts table and the settings — so
 * creating an instance per request costs nothing and two requests asking the
 * same name at once send one query. A forked process starts with its own.
 *
 * Without `configure()`, the system's settings apply: `/etc/resolv.conf`,
 * `/etc/hosts`, `/etc/nsswitch.conf` and the `LOCALDOMAIN` / `RES_OPTIONS`
 * environment variables.
 */
final class DNS_Client_CLI
{
   use Configurable;


   /** The Configs carrying the resolver's settings. */
   protected const string TRANSPORT = Configs::class;
   /** @var array<int,class-string<Configuring>> Every Configs this node applies. */
   protected const array CONFIGS = [Configs::class];

   /** Addresses per family `resolve()` returns, at most. */
   public const int ADDRESSES = 32;

   // * Config
   /** The configuration in effect. */
   public private(set) Configs $Configs;

   // * Data
   // ...

   // * Metadata
   /** The worker's event loop lookups park on; `null` outside a server worker. */
   public null|(Events & Loops & Scheduler) $Reactor {
      get => isSet(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
   }
   /** The process the shared states belong to. */
   private static int $pid = 0;
   /** @var array<string,State> One state per configuration, by fingerprint (at most 16). */
   private static array $States = [];
   /** The process's answer cache, shared by every state. */
   private static null|Cache $Cache = null;
   /** States created in this process (each one's cache-key prefix). */
   private static int $serial = 0;


   public function __construct ()
   {
      // * Config
      $this->Configs = new Configs();
   }

   /**
    * Configure the resolver (one `DNS_Client_CLI\Configs`).
    */
   public function configure (Configuring ...$Configs): self
   {
      $this->apply($Configs);

      return $this;
   }

   /**
    * Apply one Configs.
    */
   protected function adopt (Configuring $Config): void
   {
      if ($Config instanceof Configs) {
         $this->Configs = $Config;
      }
   }

   /**
    * Resolve a host name to the IP addresses a dialer should try, in order.
    *
    * An IP literal (an IPv6 one bare or bracketed) is returned canonical,
    * without a lookup. Then, before any query: `invalid` and `*.invalid`
    * (nothing, not even from the hosts file); the hosts file; `localhost`
    * and `*.localhost` (`::1`, `127.0.0.1`). `*.local` names (when an mDNS source comes before `dns` in
    * `nsswitch.conf`), the machine's own name (with `myhostname`) and numeric
    * host forms (`127.1`) go to the system resolver. Every other name is
    * asked over DNS — A and AAAA at once — through the search list, the way
    * glibc's `res_search()` walks it. An unresolved name is never retried
    * through the system resolver on a worker loop.
    *
    * @param string $host A host name (one trailing dot: no search list) or an IP literal.
    * @param null|Closure $Wait The caller's wait bridge: with one, inside a Fiber, on a worker
    *    loop, the lookup parks instead of blocking.
    * @param null|int $deadline The caller's deadline on the monotonic clock (`hrtime(true)`
    *    nanoseconds): a parked lookup gives up there and returns `[]`; a blocking one cannot be cut.
    * @param null|(Events&Loops&Scheduler) $Reactor The loop the caller runs on: a lookup parks only
    *    when it is the worker's (or none is given); on another loop it blocks.
    *
    * @return list<string> Canonical addresses, ordered for dialing (at most 32 per family); `[]`
    *    when the name does not resolve (or is refused: empty, over 253 characters, a NUL byte,
    *    `%`, a stray bracket, an empty label or one over 63 characters).
    */
   public function resolve (
      string $host,
      null|Closure $Wait = null,
      null|int $deadline = null,
      null|(Events & Loops & Scheduler) $Reactor = null
   ): array
   {
      $now = Query::clock();
      // ! The caller's deadline on the resolver's clock (seconds)
      $until = $deadline === null ? null : $deadline / 1_000_000_000;

      // ? A bracketed IPv6 literal (never with a zone id)
      if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
         $address = Resolver::normalize(substr($host, 1, -1));

         return $address === null || str_contains($address, ':') === false ? [] : [$address];
      }
      // ? An IP literal needs no lookup
      $literal = Resolver::normalize($host);
      if ($literal !== null) {
         return [$literal];
      }
      // ? A name no resolver should be asked about
      $name = self::check($host);
      if ($name === null) {
         return [];
      }
      $absolute = str_ends_with($host, '.');

      // ! Held until the lookup ends, even if the process's table drops it meanwhile
      $State = $this->load($now);
      $Settings = $State->Settings;
      $Precedence = $State->Precedence;

      // ? A special-use name that never resolves (RFC 6761 §6.4): not even from the hosts file
      if ($name === 'invalid' || str_ends_with($name, '.invalid')) {
         return [];
      }
      // ? The hosts file wins
      if ($Settings->files) {
         $addresses = $State->Hosts->find($name, $now);
         if ($addresses !== []) {
            return $this->limit($Precedence->order($addresses, $now));
         }
      }
      // ? Loopback names (RFC 6761 §6.3)
      if ($name === 'localhost' || str_ends_with($name, '.localhost')) {
         return $Precedence->order(['::1', '127.0.0.1'], $now);
      }

      // ? The caller's deadline passed already: nothing is asked, by any resolver
      if ($until !== null && Query::clock() >= $until) {
         return [];
      }

      // ? Names only the system resolver answers, and lookups that cannot park — a caller on
      //   another loop than the worker's would wait on a driver nobody runs
      $Worker = $this->Reactor;
      $parking = $Wait !== null
         && $Worker !== null
         && ($Reactor === null || $Reactor === $Worker)
         && Fiber::getCurrent() !== null;
      if (
         $parking === false
         || ($Settings->mDNS && ($name === 'local' || str_ends_with($name, '.local')))
         || ($Settings->myhostname && ($name === $Settings->hostname || in_array($name, ['_gateway', '_outbound'], true)))
         || preg_match('/(?:^|\.)(?:\d+|0x[0-9a-f]*)$/D', $name) === 1
      ) {
         return $this->limit($State->climb($absolute ? "{$name}." : $name, $now, negative: $Worker !== null));
      }

      // @ DNS, through the search list (glibc res_search)
      $dots = substr_count($name, '.');
      $tried = false;
      if ($dots >= $Settings->ndots || $absolute) {
         [$addresses, $kind] = $this->find($State, $name, $Wait, $until);
         if ($addresses !== [] || $absolute) {
            return $this->limit($Precedence->order($addresses, Query::clock()));
         }
         $tried = true;
      }

      // @@ Each search domain
      foreach ($Settings->search as $domain) {
         $candidate = "{$name}.{$domain}";
         if (strlen($candidate) > 253) {
            continue;
         }

         // ? The caller's deadline passed: no further candidate
         if ($until !== null && Query::clock() >= $until) {
            return [];
         }

         [$addresses, $kind] = $this->find($State, $candidate, $Wait, $until);
         if ($addresses !== []) {
            return $this->limit($Precedence->order($addresses, Query::clock()));
         }
         // ? No nameserver listens: give up at once
         if ($kind === 'unreachable') {
            return [];
         }
         // ? NXDOMAIN, NODATA and SERVFAIL try the next domain; anything else stops the search
         if (in_array($kind, ['NXDOMAIN', 'NODATA', 'SERVFAIL'], true) === false) {
            break;
         }
      }

      // @ The name as-is, if not tried yet
      if ($tried === false) {
         // ? The caller's deadline passed
         if ($until !== null && Query::clock() >= $until) {
            return [];
         }

         [$addresses] = $this->find($State, $name, $Wait, $until);

         return $this->limit($Precedence->order($addresses, Query::clock()));
      }

      return [];
   }

   /**
    * Ask DNS for the records of one type at an absolute name.
    *
    * No search list, no hosts file, no system resolver: the name is asked as
    * given — except `invalid` and `*.invalid` (`Absent`, never asked) and,
    * when an mDNS source comes before `dns` in `nsswitch.conf`, `local` and
    * `*.local` (`Failed`, never asked over unicast DNS). Answers come from the
    * cache while fresh; a failed lookup (or a cached failure) returns an
    * expired positive answer kept for up to 300 seconds, when there is one.
    *
    * @param string $name An absolute name (a trailing dot is optional).
    * @param int $type A record type (`DNS::TYPE_MX`, `DNS::TYPE_TXT`, …).
    * @param null|Closure $Wait The caller's wait bridge (see `resolve()`).
    *
    * @return Answer The outcome, the canonical name, the records and their TTL.
    */
   public function query (string $name, int $type, null|Closure $Wait = null): Answer
   {
      $now = Query::clock();

      // ? A name no resolver should be asked about, or a type out of range
      $absolute = self::check($name);
      if ($absolute === null || $type < 1 || $type > 0xFFFF) {
         return new Answer(Outcomes::Failed, strtolower(rtrim($name, '.')));
      }

      $State = $this->load($now);
      // ? A special-use name that never resolves (RFC 6761 §6.4): absent, never asked
      if ($absolute === 'invalid' || str_ends_with($absolute, '.invalid')) {
         return new Answer(Outcomes::Absent, $absolute);
      }
      // ? An mDNS name (an mDNS source comes before `dns`) is never asked over unicast DNS (RFC 6762)
      if ($State->Settings->mDNS && ($absolute === 'local' || str_ends_with($absolute, '.local'))) {
         return new Answer(Outcomes::Failed, $absolute);
      }
      $Cache = $State->Cache;
      $key = "{$State->scope}{$type}:{$absolute}";

      // ? Fresh in the cache (a cached failure still falls back on a stale positive answer)
      $Cached = $Cache->find($key, $now);
      if ($Cached !== null) {
         if ($Cached->Outcome === Outcomes::Failed) {
            $Cached = $Cache->find($key, $now, stale: true) ?? $Cached;
         }

         return self::detach($Cached);
      }

      // @ Ask
      $parking = $Wait !== null && $this->Reactor !== null && Fiber::getCurrent() !== null;
      if ($parking) {
         $Query = $this->ask($State, $absolute, [$type], $Wait);
      }
      else {
         $Query = $State->build($absolute, [$type]);
         $Query->execute(null, null);
      }
      $Answer = $Query?->Answers[$type] ?? new Answer(Outcomes::Failed, $absolute);

      // ?: A failure falls back on a stale positive answer
      if ($Answer->Outcome === Outcomes::Failed) {
         return self::detach($Cache->find($key, Query::clock(), stale: true) ?? $Answer);
      }

      return self::detach($Answer);
   }

   /**
    * Copy an answer with copies of its records: the cache and the other waiters keep theirs.
    */
   private static function detach (Answer $Answer): Answer
   {
      $Records = [];
      foreach ($Answer->Records as $Record) {
         $Records[] = clone $Record;
      }

      return new Answer($Answer->Outcome, $Answer->name, $Records, $Answer->TTL);
   }

   /**
    * Check a name: the lowercased absolute form (no trailing dot), or `null` when it is refused.
    */
   private static function check (string $name): null|string
   {
      // ? Empty, a byte no name holds, a trailing dot pair
      if ($name === '' || strpbrk($name, "\0%[]") !== false || str_ends_with($name, '..')) {
         return null;
      }

      $name = strtolower(rtrim($name, '.'));
      if ($name === '' || strlen($name) > 253) {
         return null;
      }

      // ? No empty label, none over 63 characters
      foreach (explode('.', $name) as $label) {
         if ($label === '' || strlen($label) > 63) {
            return null;
         }
      }

      return $name;
   }

   /**
    * This configuration's shared state in this process (created on first use).
    */
   private function load (float $now): State
   {
      // ! A forked process starts with its own states and cache: never the parent's sockets or queries
      $pid = (int) getmypid();
      if (self::$pid !== $pid || self::$Cache === null) {
         self::$pid = $pid;
         self::$States = [];
         self::$Cache = new Cache();
      }

      // ! One state per configuration: values, plus what the seams it carries do (`Seams`)
      $Configs = $this->Configs;
      $fingerprint = serialize([
         $Configs->nameservers, $Configs->port, $Configs->search, $Configs->ndots,
         $Configs->timeout, $Configs->attempts, $Configs->rotate, $Configs->EDNS,
         $Configs->resolv, $Configs->hosts, $Configs->nsswitch, $Configs->environment,
         $Configs->hostname,
         Seams::identify($Configs->Resolver),
         Seams::identify($Configs->Probe),
      ]);

      $State = self::$States[$fingerprint] ?? null;
      if ($State === null) {
         // ! At most 16 configurations per process: the oldest goes
         if (count(self::$States) >= 16) {
            unset(self::$States[array_key_first(self::$States)]);
         }

         // ! Its own key prefix in the process's cache: another configuration's answers never leak in
         $serial = ++self::$serial;
         $State = new State($Configs, self::$Cache, "{$serial}|");
         self::$States[$fingerprint] = $State;
      }

      $State->Settings->reload($now);

      return $State;
   }

   /**
    * Ask DNS for A and AAAA of one candidate name: from the cache, or through a shared query.
    *
    * @return array{list<string>, string} The addresses, and how the candidate ended:
    *    `ANSWERED`, `NODATA`, `NXDOMAIN`, `SERVFAIL`, `unreachable` or `failed`.
    */
   private function find (State $State, string $name, Closure $Wait, null|float $until = null): array
   {
      $Cache = $State->Cache;
      $types = [DNS::TYPE_A, DNS::TYPE_AAAA];

      // ! Fresh answers from the cache; ask for the rest
      $Answers = [];
      $missing = [];
      foreach ($types as $type) {
         $key = "{$State->scope}{$type}:{$name}";
         $Cached = $Cache->find($key, Query::clock());
         if ($Cached === null) {
            $missing[] = $type;
            continue;
         }
         // ! A cached failure still falls back on a stale positive answer
         if ($Cached->Outcome === Outcomes::Failed) {
            $Cached = $Cache->find($key, Query::clock(), stale: true) ?? $Cached;
         }
         $Answers[$type] = $Cached;
      }

      $faults = [];
      if ($missing !== []) {
         $Query = $this->ask($State, $name, $missing, $Wait, $until);
         foreach ($missing as $type) {
            $Answer = $Query?->Answers[$type] ?? new Answer(Outcomes::Failed, $name);
            $faults[$type] = $Query === null ? 'local' : ($Query->faults[$type] ?? null);

            // ? A failure falls back on a stale positive answer
            if ($Answer->Outcome === Outcomes::Failed) {
               $Answer = $Cache->find("{$State->scope}{$type}:{$name}", Query::clock(), stale: true) ?? $Answer;
            }
            $Answers[$type] = $Answer;
         }
      }

      // ! Both answers (a type the lookup could not get is a failure)
      $A = $Answers[DNS::TYPE_A] ?? new Answer(Outcomes::Failed, $name);
      $AAAA = $Answers[DNS::TYPE_AAAA] ?? new Answer(Outcomes::Failed, $name);

      // @ The addresses of both families
      $addresses = [];
      foreach ([$A, $AAAA] as $Answer) {
         foreach ($Answer->Records as $Record) {
            $address = is_string($Record->data) ? Resolver::normalize($Record->data) : null;
            if ($address !== null && in_array($address, $addresses, true) === false) {
               $addresses[] = $address;
            }
         }
      }
      if ($addresses !== []) {
         return [$addresses, 'ANSWERED'];
      }

      // :
      foreach ($faults as $fault) {
         if ($fault === 'unreachable') {
            return [[], 'unreachable'];
         }
      }
      // ! The pair's outcome: A's, unless A has no data (glibc res_queriesmatch order)
      [$Answer, $type] = $A->Outcome === Outcomes::Empty ? [$AAAA, DNS::TYPE_AAAA] : [$A, DNS::TYPE_A];
      $kind = match ($Answer->Outcome) {
         Outcomes::Answered, Outcomes::Empty => 'NODATA',
         Outcomes::Absent => 'NXDOMAIN',
         Outcomes::Failed => ($faults[$type] ?? null) === 'SERVFAIL' ? 'SERVFAIL' : 'failed',
      };

      return [[], $kind];
   }

   /**
    * Join the query in flight for `$name` and `$types`, or start one; wait for it on its doorbell.
    *
    * @param list<int> $types
    *
    * @return null|Query The settled query; `null` when this lookup could not wait for it (its own
    *    deadline, a bridge that stopped suspending, the reactor's refusal, too many in flight).
    */
   private function ask (State $State, string $name, array $types, Closure $Wait, null|float $until = null): null|Query
   {
      $Reactor = $this->Reactor;
      if ($Reactor === null) {
         return null;
      }

      $Query = $State->join($name, $types, $Reactor);
      if ($Query === null) {
         return null;
      }

      // @ Wait on the doorbell
      $settled = $Query->await($Wait, $until);

      return $settled ? $Query : null;
   }

   /**
    * Keep at most `ADDRESSES` addresses per family, in order.
    *
    * @param list<string> $addresses
    *
    * @return list<string>
    */
   private function limit (array $addresses): array
   {
      $kept = [];
      $counts = [4 => 0, 6 => 0];
      foreach ($addresses as $address) {
         $family = str_contains($address, ':') ? 6 : 4;
         if ($counts[$family]++ < self::ADDRESSES) {
            $kept[] = $address;
         }
      }

      return $kept;
   }
}
