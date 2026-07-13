#!/usr/bin/env php
<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

/**
 * Observability OTLP ship script.
 *
 * Reads the per-worker snapshot files written by the running server, merges them, encodes the
 * cluster snapshot as OTLP/HTTP JSON, and POSTs it to an OpenTelemetry collector. Run from the
 * Bootgly root, periodically (cron / systemd-timer), e.g. every 15s:
 *
 *   * * * * * cd /path/to/bootgly && OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318 \
 *             php scripts/observability-ship.php
 *
 * Environment:
 *   OTEL_EXPORTER_OTLP_ENDPOINT   collector base URL          (default http://127.0.0.1:4318)
 *   OTEL_SERVICE_NAME             service.name resource attr  (default bootgly)
 *   BOOTGLY_OBSERVABILITY_DIR     per-worker snapshot dir     (default <tmp>/bootgly-observability)
 *   BOOTGLY_OBSERVABILITY_MAXAGE  skip files older than (s)   (default 60)
 */

// ! Bootstrap the framework (registered as a built-in script in scripts/autoboot.php)
define('BOOTGLY_WORKING_BASE', dirname(__DIR__));
define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);
(@include dirname(__DIR__) . '/autoboot.php') || exit(1);


// ! Config (from environment)
$endpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://127.0.0.1:4318';
$service  = getenv('OTEL_SERVICE_NAME') ?: 'bootgly';
$dir      = getenv('BOOTGLY_OBSERVABILITY_DIR')
   ?: sys_get_temp_dir() . '/bootgly-observability-' . substr(hash('xxh3', BOOTGLY_WORKING_BASE), 0, 12);
$maxAge   = (float) (getenv('BOOTGLY_OBSERVABILITY_MAXAGE') ?: 60);

// ! Merge per-worker snapshots into one cluster snapshot
$Snapshot = Bootgly\ACI\Observability::aggregate("$dir/worker-*.json", maxAge: $maxAge);
if ($Snapshot->metrics === []) {
   fwrite(STDERR, "observability-ship: no fresh metrics in $dir (is the server running + ticking?).\n");
   exit(1);
}

// ! Encode OTLP/HTTP JSON
$body = new Bootgly\ACI\Observability\Exporters\OTLP(service: $service)->export($Snapshot);

// ! Resolve the collector endpoint (…/v1/metrics)
$url    = rtrim($endpoint, '/') . '/v1/metrics';
$parts  = parse_url($url) ?: [];
$scheme = $parts['scheme'] ?? 'http';
$host   = $parts['host'] ?? '127.0.0.1';
$port   = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 4318));
$path   = $parts['path'] ?? '/v1/metrics';

// ! POST via the canonical HTTP client (sync mode — runs its own event loop, then returns)
$Client = new Bootgly\WPI\Nodes\HTTP_Client_CLI;
$Client->configure(host: $host, port: $port, secure: $scheme === 'https' ? [] : null);
$Response = $Client->request(
   method: 'POST',
   URI: $path,
   headers: ['Content-Type' => 'application/json'],
   body: $body,
);

$code = $Response->code ?? 0;
fwrite(STDOUT, "observability-ship: POST $scheme://$host:$port$path → HTTP $code\n");

// : Exit non-zero on a non-2xx response (lets cron/monitoring detect failures)
exit($code >= 200 && $code < 300 ? 0 : 1);
