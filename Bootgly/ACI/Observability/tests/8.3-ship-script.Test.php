<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Observability;
use Bootgly\ACI\Observability\Exporters\JSON;
use Bootgly\ACI\Observability\Metrics\Counter;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'scripts/observability-ship.php ships the merged snapshot as OTLP/HTTP JSON and exits 0 on a 2xx',
   test: function () {
      // ! One fresh worker snapshot for the script to merge
      $dir = sys_get_temp_dir() . '/bootgly-obs-ship-' . uniqid();
      $Observability = new Observability(collectors: false);
      $Counter = new Counter(name: 'http_requests_total', labels: ['method' => 'GET']);
      $Counter->increment(by: 7);
      $Observability->Metrics->push($Counter);
      $Observability->dump(new JSON, "$dir/worker-1.json");

      // ! A one-shot collector on an ephemeral port
      $Collector = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
      $port = 0;
      if (is_resource($Collector)) {
         $name = stream_socket_get_name($Collector, false);
         $port = (int) substr((string) $name, strpos((string) $name, ':') + 1);
      }

      // @ Run the script exactly as the guide documents it (env-driven, from the root)
      $env = array_merge(getenv(), [
         'OTEL_EXPORTER_OTLP_ENDPOINT' => "http://127.0.0.1:$port",
         'OTEL_SERVICE_NAME' => 'ship-test',
         'BOOTGLY_OBSERVABILITY_DIR' => $dir,
      ]);
      $Process = proc_open(
         [PHP_BINARY, BOOTGLY_ROOT_DIR . 'scripts/observability-ship.php'],
         [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
         $pipes,
         BOOTGLY_ROOT_DIR,
         $env
      );

      // @ Serve the single POST: read the request, answer 200, close
      $request = '';
      $body = '';
      $served = false;
      $Peer = is_resource($Collector) ? @stream_socket_accept($Collector, 10) : false;
      if (is_resource($Peer)) {
         $served = true;
         while (str_contains($request, "\r\n\r\n") === false) {
            $chunk = fread($Peer, 65535);
            if ($chunk === false || $chunk === '') break;
            $request .= $chunk;
         }
         $split = strpos($request, "\r\n\r\n");
         $headers = $split === false ? $request : substr($request, 0, $split);
         $body = $split === false ? '' : substr($request, $split + 4);
         $length = 0;
         if (preg_match('/^Content-Length:\s*(\d+)/mi', $headers, $matches) === 1) {
            $length = (int) $matches[1];
         }
         while (strlen($body) < $length) {
            $chunk = fread($Peer, 65535);
            if ($chunk === false || $chunk === '') break;
            $body .= $chunk;
         }
         fwrite($Peer, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
         fclose($Peer);
      }

      // @ Collect the script's outcome
      $stdout = '';
      $stderr = '';
      $exit = -1;
      if (is_resource($Process)) {
         $stdout = (string) stream_get_contents($pipes[1]);
         $stderr = (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);
         $exit = proc_close($Process);
      }
      if (is_resource($Collector)) {
         fclose($Collector);
      }
      @unlink("$dir/worker-1.json");
      @rmdir($dir);

      yield assert(
         assertion: $served && str_starts_with($request, 'POST /v1/metrics HTTP/1.1'),
         description: "the script POSTs to /v1/metrics on the configured endpoint (request: " . substr($request, 0, 60) . ')'
      );
      yield assert(
         assertion: preg_match('/^Content-Type:\s*application\/json/mi', $request) === 1,
         description: 'the request is sent as application/json'
      );
      $decoded = json_decode($body, true);
      $series = null;
      if (is_array($decoded)) {
         $series = $decoded['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0] ?? null;
      }
      yield assert(
         assertion: is_array($decoded)
            && ($decoded['resourceMetrics'][0]['resource']['attributes'][0]['value']['stringValue'] ?? null) === 'ship-test'
            && is_array($series) && ($series['name'] ?? null) === 'http_requests_total'
            && ($series['sum']['dataPoints'][0]['asDouble'] ?? null) === 7.0,
         description: 'the body is the OTLP document of the merged snapshot (service.name + the counter value)'
      );
      yield assert(
         assertion: $exit === 0 && str_contains($stdout, '→ HTTP 200'),
         description: "the script reports the collector's status and exits 0 (exit=$exit, stdout=" . trim($stdout) . ', stderr=' . trim($stderr) . ')'
      );
   }
);
