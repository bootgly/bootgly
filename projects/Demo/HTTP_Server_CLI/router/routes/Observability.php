<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

/**
 * Observability routes — the `/health` and `/metrics` convention.
 *
 * Exposes the ACI Observability collector over HTTP:
 *   - GET /health  → JSON liveness (status, pid, uptime, memory) for this worker.
 *   - GET /metrics → JSON snapshot merged across all workers (counters/gauges/histograms
 *                    + process & runtime health + bridged HTTP socket stats).
 *
 * Enable it in router/router.index.php: add 'Observability' to the manifest.
 *
 * Cross-worker aggregation is file-based: each worker writes its snapshot to
 * `<dir>/worker-<pid>.json` when scraped, and /metrics merges every fresh file.
 * The directory defaults to the system temp dir (override with BOOTGLY_OBSERVABILITY_DIR).
 *
 * Note: with more than one worker, a scrape refreshes only the worker that served it; under
 * real traffic every worker is exercised, but a perfectly synchronized multi-worker refresh
 * waits on the deferred per-worker tick. With the demo default (workers: 1) it is exact.
 */

use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Observability\Exporters\JSON;
use Bootgly\ACI\Observability\Exporters\Prometheus;
use Bootgly\ACI\Observability\Metrics\Counter;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;


return static function (Request $Request, Response $Response, Router $Router)
{
   // ! Metrics directory (per-worker snapshot files) — isolated per install by default so unrelated
   //   deployments under the same OS temp dir do not cross-aggregate. Set BOOTGLY_OBSERVABILITY_DIR
   //   explicitly in production (and to share one dir with the OTLP ship script across projects).
   $dir = getenv('BOOTGLY_OBSERVABILITY_DIR')
      ?: sys_get_temp_dir() . '/bootgly-observability-' . substr(hash('xxh3', BOOTGLY_WORKING_BASE), 0, 12);

   // ! Lazy registry builder — one instance per worker, shared by both routes.
   //   Default Process + Runtime collectors are auto-registered; HTTP socket stats are
   //   bridged as observable counters reading this worker's Connections statics (WPI → ACI).
   $ensure = static function () use ($dir): Observability {
      if (Observability::$Instance !== null) {
         return Observability::$Instance;
      }

      // @ Turn on the server's socket-stats counters (lazy, like the `stats` command)
      Connections::$stats = true;

      // ! These are per-process (post-fork) cumulative counters — label each series by PID so the
      //   cluster aggregate UNIONS per-worker series instead of summing into one value that would
      //   drop on a stale-skip or worker restart (monotonic-counter contract). Backends sum + handle
      //   resets per series.
      $pid = (string) (getmypid() ?: 0);

      $O = new Observability();
      $O->Metrics
         ->push(new Counter(
            name: 'http_socket_reads_total', help: 'Total socket read operations.',
            labels: ['pid' => $pid], observe: static fn () => Connections::$reads
         ))
         ->push(new Counter(
            name: 'http_socket_writes_total', help: 'Total socket write operations.',
            labels: ['pid' => $pid], observe: static fn () => Connections::$writes
         ))
         ->push(new Counter(
            name: 'http_bytes_read_total', help: 'Total bytes read from sockets.',
            labels: ['pid' => $pid], observe: static fn () => Connections::$read
         ))
         ->push(new Counter(
            name: 'http_bytes_written_total', help: 'Total bytes written to sockets.',
            labels: ['pid' => $pid], observe: static fn () => Connections::$written
         ))
         ->push(new Counter(
            name: 'http_errors_total', help: 'Total socket errors by type.',
            labels: ['type' => 'connection', 'pid' => $pid], observe: static fn () => Connections::$errors['connection'] ?? 0
         ))
         ->push(new Counter(
            name: 'http_errors_total', help: 'Total socket errors by type.',
            labels: ['type' => 'read', 'pid' => $pid], observe: static fn () => Connections::$errors['read'] ?? 0
         ))
         ->push(new Counter(
            name: 'http_errors_total', help: 'Total socket errors by type.',
            labels: ['type' => 'write', 'pid' => $pid], observe: static fn () => Connections::$errors['write'] ?? 0
         ));

      // @ HTTP request metrics (count, status class, duration, in-flight) via the request-lifecycle
      //   events — hot path stays zero-cost until these listeners are registered here.
      new Telemetry($O)->boot();

      Observability::$Instance = $O;

      // @ Periodic per-worker dump so files stay fresh for scrapes AND the OTLP ship script
      //   (worker Timer ticks via Select::loop()'s per-iteration pcntl_signal_dispatch + SIGALRM)
      $interval = (int) (getenv('BOOTGLY_OBSERVABILITY_INTERVAL') ?: 10);
      Timer::add($interval, static function () use ($O, $dir): void {
         $O->dump(new JSON, "$dir/worker-" . (getmypid() ?: 0) . '.json');
      });

      return $O;
   };

   // @ GET /health — liveness of this worker
   yield $Router->route('/health', static function (Request $Request, Response $Response) use ($ensure) {
      $O = $ensure();
      $Snapshot = $O->gather();

      $pid = getmypid() ?: 0;
      $uptime = $Snapshot->metrics['process_uptime_seconds']['series'][0]['value'] ?? 0;
      $memory = $Snapshot->metrics['process_memory_bytes']['series'][0]['value'] ?? 0;

      return $Response->JSON->send([
         'status'         => 'ok',
         'pid'            => $pid,
         'uptime_seconds' => $uptime,
         'memory_bytes'   => $memory,
         'timestamp'      => microtime(true),
      ]);
   }, GET);

   // @ GET /metrics — cluster snapshot (this worker refreshed + all fresh worker files merged)
   yield $Router->route('/metrics', static function (Request $Request, Response $Response) use ($ensure, $dir) {
      $O = $ensure();

      $pid = getmypid() ?: 0;
      $O->dump(new JSON, "$dir/worker-$pid.json");

      $Cluster = Observability::aggregate("$dir/worker-*.json", maxAge: 60.0);

      // @ Content negotiation: Prometheus text by default, JSON on explicit Accept
      $accept = $Request->Header->get('Accept') ?? '';
      if (str_contains($accept, 'application/json')) {
         $Response->Header->type = 'application/json';

         return $Response(body: (new JSON)->export($Cluster));
      }

      $Response->Header->type = 'text/plain; version=0.0.4';

      return $Response(body: (new Prometheus)->export($Cluster));
   }, GET);
};
