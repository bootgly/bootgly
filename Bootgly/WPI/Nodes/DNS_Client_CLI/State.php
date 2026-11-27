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


use function array_key_first;
use function array_values;
use function count;
use function getmypid;
use function implode;
use function spl_object_id;

use Bootgly\ABI\IO\Resolver;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\WPI\Events;


/**
 * The resolver state one configuration shares in a process: its settings, hosts
 * table, address ordering, system resolver, the queries in flight and the
 * system resolver's recent answers.
 *
 * A lookup holds its state object until it ends, so a state dropped from the
 * process's table meanwhile (the oldest one, past 16 configurations) still
 * serves the lookups already using it.
 */
final class State
{
   // * Config
   /** The configuration (held: its seams and what they capture stay alive, so no object id in this state's key is reused while it lives). */
   public private(set) Configs $Configs;
   /** The process's answer cache (shared by every state). */
   public private(set) Cache $Cache;
   /** This state's prefix of the cache keys. */
   public private(set) string $scope;

   // * Metadata
   public private(set) Settings $Settings;
   public private(set) Hosts $Hosts;
   public private(set) Precedence $Precedence;
   /** The blocking system resolver (the ladder). */
   public private(set) Resolver $Resolver;
   /** @var array<string,Query> Queries in flight, by `name|types`. */
   private array $Queries = [];
   /** @var array<string,array{list<string>, float}> The ladder's answers and their expiry, by host. */
   private array $ladder = [];
   /** The next `rotate` start. */
   private int $rotation = 0;
   /** @var array<int,Query> Every query in flight in this process, whatever its state. */
   private static array $Flights = [];
   /** The process `$Flights` belongs to. */
   private static int $pid = 0;

   /** Queries in flight per process (every configuration together), at most. */
   public const int FLIGHTS = 64;


   /**
    * @param Configs $Configs The configuration.
    * @param Cache $Cache The process's answer cache.
    * @param string $scope This state's prefix of the cache keys.
    */
   public function __construct (Configs $Configs, Cache $Cache, string $scope)
   {
      // * Config
      $this->Configs = $Configs;
      $this->Cache = $Cache;
      $this->scope = $scope;

      // * Metadata
      $this->Settings = new Settings($Configs);
      $this->Hosts = new Hosts($Configs->hosts);
      $this->Precedence = new Precedence($Configs->Probe);
      $this->Resolver = $Configs->Resolver ?? new Resolver();
   }

   /**
    * Create a query with this state's settings.
    *
    * @param list<int> $types
    */
   public function build (string $name, array $types): Query
   {
      $Settings = $this->Settings;

      // ! `rotate`: each query starts at the next nameserver
      $start = 0;
      if ($Settings->rotate) {
         $start = $this->rotation++ % count($Settings->nameservers);
      }

      return new Query(
         name: $name,
         types: $types,
         nameservers: $Settings->nameservers,
         port: $this->Configs->port,
         timeout: $Settings->timeout,
         attempts: $Settings->attempts,
         start: $start,
         EDNS: $Settings->EDNS,
         Cache: $this->Cache,
         scope: $this->scope
      );
   }

   /**
    * Join the query in flight for `$name` and `$types`, or start one on the reactor.
    *
    * @param list<int> $types
    *
    * @return null|Query `null` past `FLIGHTS` queries in flight in the process.
    */
   public function join (string $name, array $types, Events & Loops & Scheduler $Reactor): null|Query
   {
      // ! A forked process starts with no flight of its own
      $pid = (int) getmypid();
      if (self::$pid !== $pid) {
         self::$pid = $pid;
         self::$Flights = [];
      }
      // ! Drop the settled ones, and any past its deadline (a driver that never came back) — here
      //   and in the process's count, which outlives a state dropped from the node's table
      foreach ($this->Queries as $key => $Query) {
         if ($Query->settled || $Query->expired) {
            unset($this->Queries[$key]);
         }
      }
      foreach (self::$Flights as $id => $Query) {
         if ($Query->settled || $Query->expired) {
            unset(self::$Flights[$id]);
         }
      }

      $joined = implode(',', $types);
      $key = "{$name}|{$joined}";
      $Query = $this->Queries[$key] ?? null;
      if ($Query !== null) {
         return $Query;
      }

      // ? Bounded per process: past the cap a lookup is unresolved at once, nothing cached
      if (count(self::$Flights) >= self::FLIGHTS) {
         return null;
      }

      // @ Start it in its own driver Fiber
      $Query = $this->build($name, $types);
      $this->Queries[$key] = $Query;
      self::$Flights[spl_object_id($Query)] = $Query;
      $Query->drive($Reactor);

      return $Query;
   }

   /**
    * Ask the system resolver (blocking), keeping its answer 5 seconds.
    *
    * @param bool $negative Whether an unresolved name is kept too (on a worker loop).
    *
    * @return list<string>
    */
   public function climb (string $host, float $now, bool $negative = true): array
   {
      $Entry = $this->ladder[$host] ?? null;
      if ($Entry !== null && $Entry[1] > $now) {
         return $Entry[0];
      }

      $addresses = array_values($this->Resolver->resolve($host) ?? []);
      // ? Off a worker an unresolved name is not kept: the next call asks again (a transient
      //   failure of the system resolver must not outlive it). On a worker it is, so a name that
      //   does not resolve does not block every request
      if ($addresses === [] && $negative === false) {
         return [];
      }

      // @ Keep it 5 seconds, at most 64 names
      if (count($this->ladder) >= 64) {
         unset($this->ladder[array_key_first($this->ladder)]);
      }
      $this->ladder[$host] = [$addresses, Query::clock() + 5.0];

      return $addresses;
   }
}
