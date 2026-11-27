<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Observability\Probes;


use Closure;

use Bootgly\ACI\Observability\Metrics;
use Bootgly\ACI\Observability\Metrics\Counter;
use Bootgly\ACI\Observability\Metrics\Gauge;


/**
 * Transport instruments of a server: connections accepted, closed, active and
 * refused (by reason), and the bytes — or datagrams — received and sent.
 *
 * The server keeps the totals itself, as plain integers bumped on its own
 * paths; every instrument here is *observable*: it reads one of those totals
 * through a closure only when a snapshot is gathered, never per connection or
 * per read. Building the probe registers its instruments on the registry; a
 * server that never builds it pays nothing.
 *
 * Every series is additive — summed across workers by `Snapshot::merge()`,
 * each total is the fleet's. The totals belong to one process: a worker that
 * recycles starts again from zero, read as a counter reset.
 */
class Transport
{
   // * Config
   /** @var array<string,string> Labels of every series (e.g. `['transport' => 'tcp']`). */
   public private(set) array $labels;
   /** @var list<string> Refusal reasons, one `server_connections_refused_total` series each. */
   public private(set) array $reasons;


   /**
    * Register the transport instruments on a registry.
    *
    * Each closure returns the server's current total; counters must never
    * decrease while the process lives.
    *
    * @param Metrics $Metrics The registry the instruments are pushed onto.
    * @param Closure():int $Accepted Connections (peers) admitted so far.
    * @param Closure():int $Closed Admitted connections (peers) closed so far.
    * @param Closure():int $Active Admitted connections (peers) open now.
    * @param Closure(string):int $Refused Connections (datagrams) refused so far for a reason.
    * @param list<string> $reasons Every reason `$Refused` answers for: each one is a series from
    *        the first scrape on, at zero until it happens.
    * @param Closure():int $Received Bytes received so far.
    * @param Closure():int $Sent Bytes sent so far.
    * @param array<string,string> $labels Labels of every series.
    * @param null|Closure():int $Arrivals Datagrams received so far (datagram transports only).
    * @param null|Closure():int $Departures Datagrams sent so far (datagram transports only).
    */
   public function __construct (
      Metrics $Metrics,
      Closure $Accepted,
      Closure $Closed,
      Closure $Active,
      Closure $Refused,
      array $reasons,
      Closure $Received,
      Closure $Sent,
      array $labels = [],
      null|Closure $Arrivals = null,
      null|Closure $Departures = null
   )
   {
      // * Config
      $this->labels = $labels;
      $this->reasons = $reasons;

      // @ Connections
      $Metrics
         ->push(new Counter(
            name: 'server_connections_accepted_total',
            help: 'Connections (peers) the server admitted.',
            labels: $labels,
            observe: $Accepted
         ))
         ->push(new Counter(
            name: 'server_connections_closed_total',
            help: 'Admitted connections (peers) closed.',
            labels: $labels,
            observe: $Closed
         ))
         ->push(new Gauge(
            name: 'server_connections_active',
            help: 'Admitted connections (peers) open now.',
            labels: $labels,
            observe: $Active
         ));

      // @@ One refusal series per reason, present before the first refusal
      foreach ($reasons as $reason) {
         $Metrics->push(new Counter(
            name: 'server_connections_refused_total',
            help: 'Connections (datagrams) the server refused, by reason.',
            labels: [...$labels, 'reason' => $reason],
            observe: static fn (): int => $Refused($reason)
         ));
      }

      // @ Throughput
      $Metrics
         ->push(new Counter(
            name: 'server_received_bytes_total',
            help: 'Bytes the server received.',
            labels: $labels,
            observe: $Received
         ))
         ->push(new Counter(
            name: 'server_sent_bytes_total',
            help: 'Bytes the server sent.',
            labels: $labels,
            observe: $Sent
         ));

      // # Datagrams
      if ($Arrivals !== null) {
         $Metrics->push(new Counter(
            name: 'server_received_datagrams_total',
            help: 'Datagrams the server received.',
            labels: $labels,
            observe: $Arrivals
         ));
      }
      if ($Departures !== null) {
         $Metrics->push(new Counter(
            name: 'server_sent_datagrams_total',
            help: 'Datagrams the server sent.',
            labels: $labels,
            observe: $Departures
         ));
      }
   }
}
