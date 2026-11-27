<?php

use Bootgly\ACI\Observability\Metrics;
use Bootgly\ACI\Observability\Probes\Transport;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Transport probe registers observable connection and throughput series, read at scrape time',
   test: function () {
      // ! Totals the server would keep
      $accepted = 0;
      $closed = 0;
      $active = 0;
      $refused = ['peer' => 0, 'ip' => 0];
      $received = 0;
      $sent = 0;
      $arrivals = 0;
      $departures = 0;

      $Metrics = new Metrics;
      $Transport = new Transport(
         Metrics: $Metrics,
         Accepted: function () use (&$accepted): int { return $accepted; },
         Closed: function () use (&$closed): int { return $closed; },
         Active: function () use (&$active): int { return $active; },
         Refused: function (string $reason) use (&$refused): int { return $refused[$reason] ?? 0; },
         reasons: ['peer', 'ip'],
         Received: function () use (&$received): int { return $received; },
         Sent: function () use (&$sent): int { return $sent; },
         labels: ['transport' => 'udp'],
         Arrivals: function () use (&$arrivals): int { return $arrivals; },
         Departures: function () use (&$departures): int { return $departures; }
      );

      yield assert(
         assertion: $Transport->labels === ['transport' => 'udp']
            && $Transport->reasons === ['peer', 'ip'],
         description: 'labels and reasons are readable Config'
      );

      // # Every series exists before the first event, at zero
      $metrics = $Metrics->read();
      $names = [
         'server_connections_accepted_total' => 'counter',
         'server_connections_closed_total' => 'counter',
         'server_connections_active' => 'gauge',
         'server_connections_refused_total' => 'counter',
         'server_received_bytes_total' => 'counter',
         'server_sent_bytes_total' => 'counter',
         'server_received_datagrams_total' => 'counter',
         'server_sent_datagrams_total' => 'counter',
      ];
      $typed = true;
      foreach ($names as $name => $type) {
         $typed = $typed && isSet($metrics[$name]) && $metrics[$name]['type'] === $type;
      }
      yield assert(
         assertion: $typed && count($metrics) === count($names),
         description: 'eight metrics, of the expected types'
      );
      $refusals = $metrics['server_connections_refused_total']['series'];
      yield assert(
         assertion: count($refusals) === 2
            && $refusals[0]['labels'] === ['transport' => 'udp', 'reason' => 'peer']
            && $refusals[1]['labels'] === ['transport' => 'udp', 'reason' => 'ip']
            && $refusals[0]['value'] === 0.0 && $refusals[1]['value'] === 0.0,
         description: 'one refusal series per reason, present at zero'
      );
      yield assert(
         assertion: $metrics['server_connections_accepted_total']['series'][0]['labels'] === ['transport' => 'udp'],
         description: 'series carry the transport labels and no pid (additive)'
      );

      // # Values are read when gathered, not when bumped
      $accepted = 3;
      $closed = 2;
      $active = 1;
      $refused['ip'] = 4;
      $received = 100;
      $sent = 70;
      $arrivals = 5;
      $departures = 6;
      $metrics = $Metrics->read();
      $value = static fn (string $name, int $index = 0): float => $metrics[$name]['series'][$index]['value'];
      yield assert(
         assertion: $value('server_connections_accepted_total') === 3.0
            && $value('server_connections_closed_total') === 2.0
            && $value('server_connections_active') === 1.0,
         description: 'accepted, closed and active read the current totals'
      );
      yield assert(
         assertion: $value('server_connections_refused_total', 0) === 0.0
            && $value('server_connections_refused_total', 1) === 4.0,
         description: 'each refusal series reads its own reason'
      );
      yield assert(
         assertion: $value('server_received_bytes_total') === 100.0
            && $value('server_sent_bytes_total') === 70.0
            && $value('server_received_datagrams_total') === 5.0
            && $value('server_sent_datagrams_total') === 6.0,
         description: 'bytes and datagrams read the current totals'
      );

      // # A stream transport registers no datagram series
      $Stream = new Metrics;
      new Transport(
         Metrics: $Stream,
         Accepted: static fn (): int => 0,
         Closed: static fn (): int => 0,
         Active: static fn (): int => 0,
         Refused: static fn (string $reason): int => 0,
         reasons: [],
         Received: static fn (): int => 0,
         Sent: static fn (): int => 0,
         labels: ['transport' => 'tcp']
      );
      $stream = $Stream->read();
      yield assert(
         assertion: isSet($stream['server_received_datagrams_total']) === false
            && isSet($stream['server_sent_datagrams_total']) === false
            && isSet($stream['server_connections_refused_total']) === false
            && count($stream) === 5,
         description: 'without datagram closures or reasons, only the five stream series exist'
      );
   }
);
