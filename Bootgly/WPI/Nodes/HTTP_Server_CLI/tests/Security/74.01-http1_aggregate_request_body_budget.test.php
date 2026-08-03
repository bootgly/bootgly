<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;

if (! class_exists('HTTPServerCLIBodyBudgetConnection', false)) {
   class HTTPServerCLIBodyBudgetConnection extends Connection
   {
      public bool $closed = false;

      /** @param resource $Socket */
      public function __construct (mixed &$Socket)
      {
         $this->Socket = $Socket;
         $this->timers = [];
         $this->expiration = 15;
         $this->ip = '127.0.0.1';
         $this->port = 12345;
         $this->encrypted = false;
         $this->handshaking = false;
         $this->handshakeTimer = 0;
         $this->status = Connections::STATUS_ESTABLISHED;
         $this->started = time();
         $this->used = time();
         $this->writes = 0;
      }

      // ! No event loop in this process — detach from `Server::$Event`.
      public function close (): true
      {
         $this->closed = true;
         $this->status = Connections::STATUS_CLOSED;

         return true;
      }
   }
}

/**
 * PoC — HTTP/1 in-memory request bodies have no worker-wide budget and are
 * retained past connection close.
 *
 * `Decoder_Waiting` (Content-Length) and `Decoder_Chunked` (chunked) each hold
 * an unfinished body bounded only by the PER-REQUEST `Request::$maxBodySize`.
 * Nothing bounds their sum, so N concurrent peers that declare a legal body and
 * send only part of it retain N x cap in one worker. HTTP/2 already carries both
 * a per-connection and a per-worker ledger (`Decoder_HTTP2\Bodies`), which is
 * exactly the control HTTP/1 lacks.
 *
 * Neither HTTP/1 body decoder implements `Disconnecting`, so `Connection::close()`
 * does not tear them down and the retained body stays reachable from the closed
 * Package.
 *
 * The Connection is also a self-cycle — `Packages::__construct()` stores back the
 * very object that inherits it — which plain refcounting can never free. That
 * cycle is DELIBERATE: answering the inherited read through a virtual property
 * removed it, but turned every `$this->Connection` access in the transport loops
 * into a call, cost -15.9% on the plaintext gate and destabilized the JIT. So the
 * contract asserted here is not "no cycle" but "the cycle retains nothing that
 * matters": `close()` releases every request-data retainer, and the empty shell
 * left behind is reclaimed by the collector.
 *
 * Every leg below runs against production classes on the production decode path.
 */

$probe = [
   'error' => '',
   'artifact_residual_files' => -1,
   'artifact_cleanup_exact' => false,
   // # Aggregate budget
   'per_request_cap' => Request::$maxBodySize,
   'probe_budget' => 256 * 1024,
   'slice' => 128 * 1024,
   'declared' => 1024 * 1024,
   'connections' => 6,
   'budget_available' => class_exists(Bodies::class),
   'accepted' => 0,
   'rejected' => 0,
   'retained_body_bytes' => 0,
   'chunked_accepted' => 0,
   'chunked_rejected' => 0,
   'chunk_bytes' => 0,
   'initial_multipart_accepted' => 0,
   'initial_multipart_rejected' => 0,
   'initial_multipart_tail_retained' => 0,
   'initial_multipart_reserved' => 0,
   'initial_multipart_budget_free_after' => true,
   'multipart_accepted' => 0,
   'multipart_rejected' => 0,
   'multipart_field_bytes' => 0,
   'multipart_retained' => 0,
   'multipart_budget_free_after' => true,
   'metadata_budget' => 512 * 1024,
   'metadata_fields' => 16,
   'metadata_name_bytes' => 4000,
   'metadata_accepted' => 0,
   'metadata_rejected' => 0,
   'metadata_raw_charged' => 0,
   'metadata_encoded_retained' => 0,
   'metadata_budget_remainder_available' => false,
   'metadata_one_byte_more_refused' => false,
   'active_name_budget' => 128 * 1024,
   'active_name_peers' => 8,
   'active_name_bytes' => 4072,
   'active_name_accepted' => 0,
   'active_name_rejected' => 0,
   'active_name_retained_bytes' => 0,
   'active_name_reserved' => 0,
   'active_name_heap_delta' => 0,
   'active_name_remainder_available' => false,
   'active_name_one_byte_more_refused' => false,
   'active_name_budget_free_after' => false,
   'active_name_control_state' => '',
   'active_name_control_rejected' => false,
   'active_name_control_bytes' => 0,
   'jit_warmups' => 128,
   'jit_field_warmed' => 0,
   'jit_file_warmed' => 0,
   'jit_warm_files_exact' => false,
   'jit_warm_downloads_exact' => false,
   'jit_warm_budget_free_after' => false,
   'field_buffer_budget' => 512 * 1024,
   'field_buffer_held_bytes' => 64 * 1024,
   'field_buffer_chunk_bytes' => 32 * 1024,
   'field_buffer_pre_state' => '',
   'field_buffer_pre_bytes' => 0,
   'field_buffer_pre_reserved' => 0,
   'field_buffer_bound' => 0,
   'field_buffer_allowed_growth' => 0,
   'field_buffer_blocker_accepted' => false,
   'field_buffer_final_state' => '',
   'field_buffer_rejected' => false,
   'field_buffer_final_bytes' => 0,
   'field_buffer_peak_growth' => 0,
   'field_buffer_budget_free_after' => false,
   'field_buffer_short_blocker_accepted' => false,
   'field_buffer_short_final_state' => '',
   'field_buffer_short_rejected' => false,
   'field_buffer_short_retained' => -1,
   'field_buffer_short_budget_free_after' => false,
   'append_budget' => 8 * 1024 * 1024,
   'append_completed_fields' => 256,
   'append_name_bytes' => 4000,
   'append_pre_state' => '',
   'append_encoded_bytes' => 0,
   'append_current_name_bytes' => 0,
   'append_pre_reserved' => 0,
   'append_admitted_growth' => 0,
   'append_blocker_accepted' => false,
   'append_final_state' => '',
   'append_rejected' => false,
   'append_retained_fields' => -1,
   'append_post_reserved' => 0,
   'append_peak_growth' => 0,
   'append_budget_free_after' => false,
   'append_zero_blocker_accepted' => false,
   'append_zero_final_state' => '',
   'append_zero_rejected' => false,
   'append_zero_peak_growth' => 0,
   'append_zero_budget_free_after' => false,
   'file_reserve_budget' => 64 * 1024,
   'file_reserve_name_bytes' => 4072,
   'file_reserve_pre_state' => '',
   'file_reserve_pre_reserved' => 0,
   'file_reserve_blocker_accepted' => false,
   'file_reserve_final_state' => '',
   'file_reserve_rejected' => false,
   'file_reserve_victim_retained' => -1,
   'file_reserve_peak_growth' => 0,
   'file_reserve_created' => -1,
   'file_reserve_created_zero_bytes' => false,
   'file_reserve_cleanup_exact' => false,
   'file_reserve_budget_free_after' => false,
   'file_reserve_control_state' => '',
   'file_reserve_control_rejected' => false,
   'file_reserve_control_created' => -1,
   'file_reserve_control_cleanup_exact' => false,
   'file_reserve_control_budget_free_after' => false,
   'file_record_budget' => 1024 * 1024,
   'file_record_existing' => 64,
   'file_record_pre_state' => '',
   'file_record_pre_files' => 0,
   'file_record_pre_reserved' => 0,
   'file_record_bound' => 0,
   'file_record_allowed_growth' => 0,
   'file_record_blocker_accepted' => false,
   'file_record_final_state' => '',
   'file_record_rejected' => false,
   'file_record_final_files' => 0,
   'file_record_peak_growth' => 0,
   'file_record_created' => 0,
   'file_record_cleanup_exact' => false,
   'file_record_downloads_exact' => false,
   'file_record_budget_free_after' => false,
   'file_record_short_blocker_accepted' => false,
   'file_record_short_final_state' => '',
   'file_record_short_rejected' => false,
   'file_record_short_retained' => -1,
   'file_record_short_created' => 0,
   'file_record_short_cleanup_exact' => false,
   'file_record_short_downloads_exact' => false,
   'file_record_short_budget_free_after' => false,
   'file_warning_state' => '',
   'file_warning_rejected' => false,
   'file_warning_inside_directory' => false,
   'file_warning_created' => 0,
   'file_warning_cleanup_threw' => false,
   'file_warning_cleanup_exact' => false,
   'file_warning_downloads_exact' => false,
   'file_warning_budget_free_after' => false,
   'file_collision_state' => '',
   'file_collision_rejected' => false,
   'file_collision_files' => -1,
   'file_collision_cleanup_exact' => false,
   'file_collision_downloads_exact' => false,
   'file_collision_budget_free_after' => false,
   'file_collision_control_state' => '',
   'file_collision_control_rejected' => false,
   'file_collision_control_files' => -1,
   'file_collision_control_created' => -1,
   'file_collision_control_cleanup_exact' => false,
   'file_collision_control_downloads_exact' => false,
   'file_projection_budget' => 1024 * 1024,
   'file_projection_files' => 64,
   'file_projection_name_bytes' => 512,
   'file_projection_pre_state' => '',
   'file_projection_pre_reserved' => 0,
   'file_projection_bound' => 0,
   'file_projection_allowed_growth' => 0,
   'file_projection_blocker_accepted' => false,
   'file_projection_final_state' => '',
   'file_projection_rejected' => false,
   'file_projection_result_files' => -1,
   'file_projection_peak_growth' => 0,
   'file_projection_cleanup_exact' => false,
   'file_projection_downloads_exact' => false,
   'file_projection_budget_free_after' => false,
   'file_transform_budget' => 192 * 1024,
   'file_transform_parts' => 16,
   'file_transform_name_bytes' => 4000,
   'file_transform_pre_state' => '',
   'file_transform_raw_retained' => 0,
   'file_transform_encoded_bytes' => 0,
   'file_transform_raw_fits' => false,
   'file_transform_encoded_does_not_fit' => false,
   'file_transform_final_state' => '',
   'file_transform_rejected' => false,
   'file_transform_budget_free_after' => false,
   'file_transform_control_state' => '',
   'file_transform_control_files' => 0,
   'boundary_budget' => 512 * 1024,
   'boundary_parts' => 16,
   'boundary_name_bytes' => 4000,
   'boundary_raw_retained' => 0,
   'boundary_legacy_projection' => 0,
   'boundary_blocker_accepted' => false,
   'boundary_final_state' => '',
   'boundary_rejected' => false,
   'boundary_files' => -1,
   'boundary_budget_free_after' => false,
   'nested_budget' => 512 * 1024,
   'nested_control_budget' => 8 * 1024 * 1024,
   'nested_fields' => 32,
   'nested_depth' => 32,
   'nested_pre_state' => '',
   'nested_finish_measure' => 0,
   'nested_projection' => 0,
   'nested_blocker_accepted' => false,
   'nested_final_state' => '',
   'nested_rejected' => false,
   'nested_result_fields' => -1,
   'nested_array_nodes' => 0,
   'nested_peak_delta' => 0,
   'nested_released_exactly' => false,
   'nested_one_more_refused' => false,
   'nested_budget_free_after' => false,
   'nested_control_state' => '',
   'nested_control_fields' => -1,
   'nested_control_array_nodes' => 0,
   'projection_budget' => 512 * 1024,
   'projection_fields' => 16,
   'projection_name_bytes' => 4000,
   'projection_pre_state' => '',
   'projection_pre_reserved' => 0,
   'projection_current_bound' => 0,
   'projection_allowed_growth' => 0,
   'projection_blocker_accepted' => false,
   'projection_final_state' => '',
   'projection_rejected' => false,
   'projection_result_fields' => -1,
   'projection_decoded_key_bytes' => 0,
   'projection_peak_growth' => 0,
   'projection_budget_free_after' => false,
   'floor_budget' => 64 * 1024,
   'floor_fields' => 8,
   'floor_name_bytes' => 512,
   'floor_pre_state' => '',
   'floor_pre_reserved' => 0,
   'floor_current_bound' => 0,
   'floor_allowed_growth' => 0,
   'floor_blocker_accepted' => false,
   'floor_final_state' => '',
   'floor_rejected' => false,
   'floor_result_fields' => -1,
   'floor_decoded_key_bytes' => 0,
   'floor_peak_growth' => 0,
   'floor_budget_free_after' => false,
   'floor_control_state' => '',
   'floor_control_rejected' => false,
   'floor_control_fields' => -1,
   'cliff_budget' => 512 * 1024,
   'cliff_fields' => 16,
   'cliff_name_bytes' => 4072,
   'cliff_pre_state' => '',
   'cliff_pre_reserved' => 0,
   'cliff_current_bound' => 0,
   'cliff_allowed_growth' => 0,
   'cliff_blocker_accepted' => false,
   'cliff_final_state' => '',
   'cliff_rejected' => false,
   'cliff_result_fields' => -1,
   'cliff_decoded_key_bytes' => 0,
   'cliff_peak_growth' => 0,
   'cliff_budget_free_after' => false,
   'cliff_control_state' => '',
   'cliff_control_rejected' => false,
   'cliff_control_fields' => -1,
   'segmented_budget' => 8 * 1024 * 1024,
   'segmented_fields' => 64,
   'segmented_segment_bytes' => 4072,
   'segmented_header_bytes' => 0,
   'segmented_pre_state' => '',
   'segmented_pre_reserved' => 0,
   'segmented_current_bound' => 0,
   'segmented_allowed_growth' => 0,
   'segmented_blocker_accepted' => false,
   'segmented_final_state' => '',
   'segmented_rejected' => false,
   'segmented_result_fields' => -1,
   'segmented_peak_growth' => 0,
   'segmented_budget_free_after' => false,
   'segmented_control_state' => '',
   'segmented_control_rejected' => false,
   'segmented_control_fields' => -1,
   'container_budget' => 256 * 1024,
   'container_files' => 768,
   'container_pre_state' => '',
   'container_rejected' => false,
   'container_files_retained' => 0,
   'container_reserved' => 0,
   'container_heap_delta' => 0,
   'container_budget_free_before_teardown' => false,
   'container_budget_free_after_teardown' => false,
   // # Teardown driven by the real Connection::close()
   'closed_via_transport' => false,
   'body_alive_after_close' => true,
   'budget_free_after_close' => false,
   // # Deterministic release
   'waiting_disconnecting' => false,
   'chunked_disconnecting' => false,
   'ledger_empty_after_disconnect' => false,
   'request_alive_after_disconnect' => true,
   // # Reservation isolation
   'isolated' => false,
   'ledger_exact' => false,
   // # Close without cyclic GC
   'connection_alive_without_gc' => true,
   'connection_gc_collected' => -1,
   'connection_alive_after_gc' => true,
   // # Controls
   'control_complete_body' => '',
   'control_reservation_released' => false,
   // # Completed bodies must not survive their own response cycle
   'retained_payload_bytes' => 0,
   'retained_frag_state' => '',
   'retained_frag_bytes' => -1,
   'retained_frag_fields' => -1,
   'retained_initial_decoder' => true,
   'retained_initial_bytes' => -1,
   'retained_initial_fields' => -1,
   'retained_peers' => 4,
   'retained_aggregate_bytes' => -1,
   'retained_handler_saw' => 0,
   'retained_listener_saw' => 0,
   'retained_deferred_live_bytes' => -1,
   'retained_deferred_clone_bytes' => -1,
   'retained_deferred_ledger_free' => true,
   'retained_deferred_ledger_drained' => false,
];

return new Specification(
   description: 'HTTP/1 unfinished request bodies must obey a worker-wide budget and release on close',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      $harness = "GET /h1-budget-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";

      $OldRequest = Server::$Request;
      $OldResponse = Server::$Response;
      $OldRouter = Server::$Router;
      $OldDecoder = Server::$Decoder;
      $OldHandler = SAPI::$Handler ?? null;
      $OldMiddlewares = SAPI::$Middlewares ?? null;
      $OldWorkerBodySize = $probe['budget_available'] ? Bodies::$maxWorkerBodySize : 0;
      $downloadDirectory = BOOTGLY_STORAGE_DIR . 'temp/files/downloaded/';
      $downloadBaseline = glob($downloadDirectory . '*');
      if (! is_array($downloadBaseline)) {
         $downloadBaseline = [];
      }
      sort($downloadBaseline);

      $Sockets = [];
      $Retained = [];

      try {
         Server::$Response = new Response;
         Server::$Router = new Router;
         Server::$Decoder = new Decoder_;
         SAPI::$Middlewares = new Middlewares;
         SAPI::$Handler = static function (Request $Request, Response $Response, Router $Router): Response {
            return $Response(code: 200, body: 'H1-BUDGET');
         };

         if ($probe['budget_available']) {
            Bodies::$maxWorkerBodySize = $probe['probe_budget'];
         }

         // ! One independent peer: its own socket, Connection, Package and
         //   Request — exactly what N concurrent connections look like.
         $open = function () use (&$Sockets): array {
            $socket = tmpfile();
            if (! is_resource($socket)) {
               throw new RuntimeException('Could not allocate a temporary stream socket surrogate.');
            }
            $Sockets[] = $socket;

            $Connection = new HTTPServerCLIBodyBudgetConnection($socket);
            $Package = new class($Connection) extends TCPPackages {
               public function __construct (Connection $Connection)
               {
                  $this->Connection = $Connection;

                  $this->cache = true;
                  $this->changed = true;
                  $this->input = '';
                  $this->output = '';
                  $this->callbacks = [&$this->input];
                  $this->expired = false;

                  $this->downloading = [];
                  $this->uploading = [];
                  $this->closeAfterWrite = false;
               }
            };
            $Request = new Request;
            Server::$Request = $Request;

            return [$Connection, $Package, $Request];
         };

         // ! The official launcher enables the tracing JIT. A peak reset must
         //   not make one-time compilation look like attacker-owned body
         //   memory, so heat both finish() branches past jit_hot_func=127 with
         //   small valid requests. The measured adversarial shapes remain
         //   separate and are not used to prime allocator size classes.
         $fieldWarmTag = 'H1FieldWarm';
         $fieldWarmBody = "--{$fieldWarmTag}\r\n"
            . "Content-Disposition: form-data; name=\"warm\"\r\n"
            . "\r\n"
            . "x\r\n"
            . "--{$fieldWarmTag}--\r\n";
         $fileWarmTag = 'H1FileWarm';
         $fileWarmBody = "--{$fileWarmTag}\r\n"
            . "Content-Disposition: form-data; name=\"left\"; filename=\"left.txt\"\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "x\r\n"
            . "--{$fileWarmTag}\r\n"
            . "Content-Disposition: form-data; name=\"right\"; filename=\"right.txt\"\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "y\r\n"
            . "--{$fileWarmTag}--\r\n";

         $WarmFinish = function (
            string $tag,
            string $body,
            string $counter
         ) use ($open, &$probe): bool {
            [$WarmConnection, $WarmPackage, $WarmRequest] = $open();
            $head = "POST /h1-budget-jit-warmup HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$tag}\r\n"
               . 'Content-Length: ' . strlen($body) . "\r\n"
               . "\r\n";
            $HeadState = $WarmRequest->decode($WarmPackage, $head, strlen($head));
            $WarmDecoder = $WarmPackage->Decoder;

            if (
               $HeadState === States::Rejected
               || ! $WarmDecoder instanceof Decoder_Downloading
            ) {
               $probe['error'] = 'Probe setup failed: JIT warmup decoder was not installed.';
               return false;
            }

            $WarmState = $WarmDecoder->decode(
               $WarmPackage,
               $body,
               strlen($body)
            );
            if ($WarmState !== States::Complete || $WarmPackage->rejected) {
               $probe['error'] = 'Probe setup failed: JIT finish warmup did not complete.';

               if ($WarmPackage->Decoder instanceof Disconnecting) {
                  $WarmPackage->Decoder->disconnect();
               }
               $WarmPackage->Decoder = null;
               $WarmRequest->clean();

               return false;
            }

            $probe[$counter]++;
            $WarmRequest->clean();
            $WarmDecoder->disconnect();
            $WarmPackage->Decoder = null;

            return true;
         };

         $warmFilesBefore = glob($downloadDirectory . '*');
         if (! is_array($warmFilesBefore)) {
            $warmFilesBefore = [];
         }
         sort($warmFilesBefore);
         $warmDownloadsBefore = Downloads::peek();

         for ($run = 0; $run < $probe['jit_warmups']; $run++) {
            if ($WarmFinish($fieldWarmTag, $fieldWarmBody, 'jit_field_warmed') === false) {
               break;
            }
         }
         if ($probe['error'] === '') {
            for ($run = 0; $run < $probe['jit_warmups']; $run++) {
               if ($WarmFinish($fileWarmTag, $fileWarmBody, 'jit_file_warmed') === false) {
                  break;
               }
            }
         }
         unset($WarmFinish);

         $warmFilesAfter = glob($downloadDirectory . '*');
         if (! is_array($warmFilesAfter)) {
            $warmFilesAfter = [];
         }
         sort($warmFilesAfter);
         $probe['jit_warm_files_exact'] = $warmFilesAfter === $warmFilesBefore;
         $probe['jit_warm_downloads_exact'] =
            Downloads::peek() === $warmDownloadsBefore;
         $WarmFree = new Bodies;
         $probe['jit_warm_budget_free_after'] = $WarmFree->reserve(
            $probe['probe_budget']
         );
         $WarmFree->release();

         // --- Leg 1: N unfinished Content-Length bodies share one worker.

         $slice = str_repeat('A', $probe['slice']);
         for ($peer = 0; $peer < $probe['connections']; $peer++) {
            [$Connection, $Package, $Request] = $open();

            // ! Head only: the declared body arrives afterwards, through
            //   `Decoder_Waiting` — the drip an attacker never finishes.
            $head = "POST /h1-budget/{$peer} HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Length: {$probe['declared']}\r\n"
               . "\r\n";
            if ($Request->decode($Package, $head, strlen($head)) === States::Rejected) {
               $probe['error'] = "Probe setup failed: head {$peer} was rejected.";
               return $harness;
            }
            if ($Package->Decoder === null) {
               $probe['error'] = "Probe setup failed: peer {$peer} installed no body decoder.";
               return $harness;
            }

            $State = $Package->Decoder->decode($Package, $slice, $probe['slice']);
            if ($State === States::Rejected) {
               $probe['rejected']++;
            }
            else {
               $probe['accepted']++;
            }

            $probe['retained_body_bytes'] += strlen($Request->Body->raw);
            // ! Hold every peer alive: concurrency is the whole point.
            $Retained[] = [$Connection, $Package, $Request];
         }

         // ! Hand the budget back before the chunked leg draws on the same
         //   ledger — otherwise leg 2 would only re-measure leg 1's exhaustion.
         foreach ($Retained as [$Connection, $Package, $Request]) {
            if ($Package->Decoder instanceof Disconnecting) {
               $Package->Decoder->disconnect();
            }
            $Package->Decoder = null;
         }
         $Retained = [];

         // --- Leg 2: the same budget governs chunked bodies.

         // ! A chunked peer retains its framing bytes too, so its footprint is
         //   slightly larger than the raw slice — the budget is in bytes, not
         //   in connections.
         $chunk = "20000\r\n" . $slice;
         $probe['chunk_bytes'] = strlen($chunk);
         for ($peer = 0; $peer < $probe['connections']; $peer++) {
            [$Connection, $Package, $Request] = $open();

            $head = "POST /h1-budget-chunked/{$peer} HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Transfer-Encoding: chunked\r\n"
               . "\r\n";
            if ($Request->decode($Package, $head, strlen($head)) === States::Rejected) {
               $probe['error'] = "Probe setup failed: chunked head {$peer} was rejected.";
               return $harness;
            }
            if ($Package->Decoder === null) {
               $probe['error'] = "Probe setup failed: chunked peer {$peer} installed no body decoder.";
               return $harness;
            }

            $State = $Package->Decoder->decode($Package, $chunk, strlen($chunk));
            if ($State === States::Rejected) {
               $probe['chunked_rejected']++;
            }
            else {
               $probe['chunked_accepted']++;
            }

            $Retained[] = [$Connection, $Package, $Request];
         }

         // ! Same handback before the multipart leg.
         foreach ($Retained as [$Connection, $Package, $Request]) {
            if ($Package->Decoder instanceof Disconnecting) {
               $Package->Decoder->disconnect();
            }
            $Package->Decoder = null;
         }
         $Retained = [];

         // --- Leg 3: multipart bytes already present beside the request head
         //     are retained by feed() and must reserve before returning.

         $initialField = str_repeat('I', 60000);
         for ($peer = 0; $peer < $probe['connections']; $peer++) {
            [$Connection, $Package, $Request] = $open();

            $head = "POST /h1-budget-multipart-initial/{$peer} HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary=BootglyH1\r\n"
               . "Content-Length: {$probe['declared']}\r\n"
               . "\r\n";
            $part = "--BootglyH1\r\n"
               . "Content-Disposition: form-data; name=\"initial{$peer}\"\r\n"
               . "\r\n"
               . $initialField;
            $raw = $head . $part;

            $State = $Request->decode($Package, $raw, strlen($raw));
            $Decoder = $Package->Decoder;
            if ($State === States::Rejected || $Package->rejected) {
               $probe['initial_multipart_rejected']++;
            }
            else if ($Decoder instanceof Decoder_Downloading) {
               $probe['initial_multipart_accepted']++;
               $Tail = new ReflectionProperty($Decoder, 'tailBuffer');
               $tail = $Tail->getValue($Decoder);
               $probe['initial_multipart_tail_retained'] += is_string($tail) ? strlen($tail) : 0;
               $probe['initial_multipart_reserved'] += $Decoder->Bodies->retained;
            }
            else {
               $probe['error'] = "Probe setup failed: initial multipart peer {$peer} installed no decoder.";
               return $harness;
            }

            $Retained[] = [$Connection, $Package, $Request];
         }

         if ($probe['budget_available']) {
            $InitialFree = new Bodies;
            $probe['initial_multipart_budget_free_after'] = $InitialFree->reserve(
               $probe['probe_budget']
            );
            $InitialFree->release();
         }

         foreach ($Retained as [$Connection, $Package, $Request]) {
            if ($Package->Decoder instanceof Disconnecting) {
               $Package->Decoder->disconnect();
            }
            $Package->Decoder = null;
         }
         $Retained = [];

         // --- Leg 4: multipart TEXT parts are memory too, and draw on the
         //     same ledger. File parts stream to disk and must not.

         $probe['multipart_field_bytes'] = 60000;
         $field = str_repeat('M', $probe['multipart_field_bytes']);
         for ($peer = 0; $peer < $probe['connections']; $peer++) {
            [$Connection, $Package, $Request] = $open();

            $head = "POST /h1-budget-multipart/{$peer} HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary=BootglyH1\r\n"
               . "Content-Length: {$probe['declared']}\r\n"
               . "\r\n";
            if ($Request->decode($Package, $head, strlen($head)) === States::Rejected) {
               $probe['error'] = "Probe setup failed: multipart head {$peer} was rejected.";
               return $harness;
            }
            if ($Package->Decoder === null) {
               $probe['error'] = "Probe setup failed: multipart peer {$peer} installed no body decoder.";
               return $harness;
            }

            // ! One text part opened and never terminated: the value sits in
            //   `$fieldBuffer` for as long as the peer keeps the body open.
            $part = "--BootglyH1\r\n"
               . "Content-Disposition: form-data; name=\"a{$peer}\"\r\n"
               . "\r\n"
               . $field;

            $State = $Package->Decoder->decode($Package, $part, strlen($part));
            if ($State === States::Rejected) {
               $probe['multipart_rejected']++;
            }
            else {
               $probe['multipart_accepted']++;
               $probe['multipart_retained'] += $probe['multipart_field_bytes'];
            }

            $Retained[] = [$Connection, $Package, $Request];
         }

         if ($probe['budget_available']) {
            // ! With the admitted text parts outstanding, the whole budget
            //   must NOT still look free.
            $Probe = new Bodies;
            $probe['multipart_budget_free_after'] = $Probe->reserve($probe['probe_budget']);
            $Probe->release();
         }

         foreach ($Retained as [$Connection, $Package, $Request]) {
            if ($Package->Decoder instanceof Disconnecting) {
               $Package->Decoder->disconnect();
            }
            $Package->Decoder = null;
         }
         $Retained = [];

         // --- Leg 5: multipart part metadata is body memory too. A peer can
         //     complete many tiny fields with long names, then leave the next
         //     part open. `fieldsEncoded` retains those names until completion.

         Bodies::$maxWorkerBodySize = $probe['metadata_budget'];

         $metadata = '';
         for ($fieldIndex = 0; $fieldIndex < $probe['metadata_fields']; $fieldIndex++) {
            // ! Percent is valid in the quoted name and expands threefold in
            //   urlencode(), making excluded retention deterministic.
            $name = str_repeat('%', $probe['metadata_name_bytes']) . $fieldIndex;
            $metadata .= "--BootglyH1\r\n"
               . "Content-Disposition: form-data; name=\"{$name}\"\r\n"
               . "\r\n"
               . "x\r\n";
         }
         $metadata .= "--BootglyH1\r\n"
            . "Content-Disposition: form-data; name=\"hold\"\r\n"
            . "\r\n";

         for ($peer = 0; $peer < $probe['connections']; $peer++) {
            [$Connection, $Package, $Request] = $open();

            $head = "POST /h1-budget-multipart-metadata/{$peer} HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary=BootglyH1\r\n"
               . "Content-Length: {$probe['declared']}\r\n"
               . "\r\n";
            if ($Request->decode($Package, $head, strlen($head)) === States::Rejected) {
               $probe['error'] = "Probe setup failed: metadata head {$peer} was rejected.";
               return $harness;
            }

            $Decoder = $Package->Decoder;
            if (! $Decoder instanceof Decoder_Downloading) {
               $probe['error'] = "Probe setup failed: metadata peer {$peer} installed no multipart decoder.";
               return $harness;
            }

            $State = $Decoder->decode($Package, $metadata, strlen($metadata));
            if ($State === States::Rejected) {
               $probe['metadata_rejected']++;
            }
            else {
               $probe['metadata_accepted']++;
               $Encoded = new ReflectionProperty($Decoder, 'fieldsEncoded');
               $encoded = $Encoded->getValue($Decoder);
               $probe['metadata_encoded_retained'] += is_string($encoded) ? strlen($encoded) : 0;
               $probe['metadata_raw_charged'] += $Decoder->Bodies->retained;
            }

            $Retained[] = [$Connection, $Package, $Request];
         }

         if ($probe['budget_available']) {
            // ! Prove exactly what the ledger sees. Current code charges the
            //   one-byte values, so its full remainder fits while one byte more
            //   does not; the much larger encoded names are invisible to it.
            $Remainder = new Bodies;
            $remainder = $probe['metadata_budget'] - $probe['metadata_raw_charged'];
            $probe['metadata_budget_remainder_available'] = $Remainder->reserve($remainder);
            $probe['metadata_one_byte_more_refused'] = $Remainder->reserve($remainder + 1) === false;
            $Remainder->release();
         }

         foreach ($Retained as [$Connection, $Package, $Request]) {
            if ($Package->Decoder instanceof Disconnecting) {
               $Package->Decoder->disconnect();
            }
            $Package->Decoder = null;
         }
         $Retained = [];
         Bodies::$maxWorkerBodySize = $probe['probe_budget'];

         // --- Leg 5.1: an active field name is persistent body metadata too.
         //     Keep the name just below a page boundary, where its zend_string
         //     header makes the retained allocation cross from 4 KiB to 8 KiB.
         //     All peer/fixture allocations exist before the measurement, so
         //     the delta isolates state retained by production decode().

         if ($probe['budget_available']) {
            Bodies::$maxWorkerBodySize = $probe['active_name_budget'];

            $activeName = str_repeat('n', $probe['active_name_bytes']);
            $activePart = "--BootglyH1ActiveName\r\n"
               . "Content-Disposition: form-data; name=\"{$activeName}\"\r\n"
               . "\r\n"
               . 'x';
            $activeLength = strlen($activePart) + 1024;
            $ActivePeers = [];
            $CurrentFieldName = new ReflectionProperty(
               Decoder_Downloading::class,
               'currentFieldName'
            );

            for ($peer = 0; $peer < $probe['active_name_peers']; $peer++) {
               [$Connection, $Package, $Request] = $open();
               $head = "POST /h1-budget-active-name/{$peer} HTTP/1.1\r\n"
                  . "Host: localhost\r\n"
                  . "Content-Type: multipart/form-data; boundary=BootglyH1ActiveName\r\n"
                  . "Content-Length: {$activeLength}\r\n"
                  . "\r\n";
               $HeadState = $Request->decode($Package, $head, strlen($head));

               if (
                  $HeadState === States::Rejected
                  || $Package->rejected
                  || ! $Package->Decoder instanceof Decoder_Downloading
               ) {
                  $probe['error'] =
                     "Probe setup failed: active-name head {$peer} was rejected.";
                  return $harness;
               }

               $ActivePeers[] = [$Connection, $Package, $Request];
            }

            unset($Connection, $Package, $Request, $HeadState, $head);
            gc_collect_cycles();
            $heapBefore = memory_get_usage(false);
            $activeAccepted = 0;
            $activeRejected = 0;
            $activeRetainedBytes = 0;
            $activeReserved = 0;

            foreach ($ActivePeers as [$Connection, $Package, $Request]) {
               $Decoder = $Package->Decoder;
               if (! $Decoder instanceof Decoder_Downloading) {
                  $probe['error'] = 'Probe setup failed: active-name decoder disappeared.';
                  return $harness;
               }

               $ActiveState = $Decoder->decode(
                  $Package,
                  $activePart,
                  strlen($activePart)
               );
               if ($ActiveState === States::Rejected || $Package->rejected) {
                  $activeRejected++;
               }
               else if ($ActiveState === States::Incomplete) {
                  $activeAccepted++;
                  $activeReserved += $Decoder->Bodies->retained;
                  $retainedName = $CurrentFieldName->getValue($Decoder);
                  $activeRetainedBytes += is_string($retainedName)
                     ? strlen($retainedName)
                     : 0;
               }
               else {
                  $probe['error'] =
                     'Probe setup failed: active-name body unexpectedly completed.';
                  return $harness;
               }
            }

            unset($Decoder, $ActiveState, $retainedName);
            gc_collect_cycles();
            $probe['active_name_heap_delta'] =
               memory_get_usage(false) - $heapBefore;
            $probe['active_name_accepted'] = $activeAccepted;
            $probe['active_name_rejected'] = $activeRejected;
            $probe['active_name_retained_bytes'] = $activeRetainedBytes;
            $probe['active_name_reserved'] = $activeReserved;

            $Remainder = new Bodies;
            $remainder = $probe['active_name_budget'] - $activeReserved;
            $probe['active_name_remainder_available'] = $Remainder->reserve($remainder);
            $probe['active_name_one_byte_more_refused'] =
               $Remainder->reserve($remainder + 1) === false;
            $Remainder->release();

            foreach ($ActivePeers as [$Connection, $Package, $Request]) {
               if ($Package->Decoder instanceof Disconnecting) {
                  $Package->Decoder->disconnect();
               }
               $Package->Decoder = null;
               $Request->clean();
            }
            $ActivePeers = [];

            $Free = new Bodies;
            $probe['active_name_budget_free_after'] = $Free->reserve(
               $probe['active_name_budget']
            );
            $Free->release();

            // ! Identical long-name control under ample budget proves this
            //   multipart prefix is valid and reaches the intended active-name
            //   state instead of relying on a parser/policy rejection.
            Bodies::$maxWorkerBodySize = 1024 * 1024;
            [$Connection, $ControlPackage, $ControlRequest] = $open();
            $head = "POST /h1-budget-active-name-control HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary=BootglyH1ActiveName\r\n"
               . "Content-Length: {$activeLength}\r\n"
               . "\r\n";
            $ControlRequest->decode($ControlPackage, $head, strlen($head));
            $ControlDecoder = $ControlPackage->Decoder;
            if ($ControlDecoder instanceof Decoder_Downloading) {
               $ControlState = $ControlDecoder->decode(
                  $ControlPackage,
                  $activePart,
                  strlen($activePart)
               );
               $probe['active_name_control_state'] = $ControlState->name;
               $probe['active_name_control_rejected'] = $ControlPackage->rejected;
               $controlName = $CurrentFieldName->getValue($ControlDecoder);
               $probe['active_name_control_bytes'] = is_string($controlName)
                  ? strlen($controlName)
                  : 0;

               $ControlDecoder->disconnect();
               $ControlPackage->Decoder = null;
               $ControlRequest->clean();
            }

            Bodies::$maxWorkerBodySize = $probe['probe_budget'];
         }

         // --- Leg 5.2: extending an active fieldBuffer may allocate the full
         //     new destination while the old string is still live. Exercise
         //     the exact current admission boundary and one byte below it.

         if ($probe['budget_available']) {
            Bodies::$maxWorkerBodySize = $probe['field_buffer_budget'];

            $PrepareFieldBuffer = function (string $tag) use (
               $open,
               &$probe
            ): array {
               [$Connection, $Package, $Request] = $open();
               $head = "POST /h1-budget-field-buffer HTTP/1.1\r\n"
                  . "Host: localhost\r\n"
                  . "Content-Type: multipart/form-data; boundary={$tag}\r\n"
                  . "Content-Length: {$probe['declared']}\r\n"
                  . "\r\n";
               $HeadState = $Request->decode($Package, $head, strlen($head));
               $Decoder = $Package->Decoder;
               if (
                  $HeadState === States::Rejected
                  || ! $Decoder instanceof Decoder_Downloading
               ) {
                  $probe['error'] =
                     'Probe setup failed: fieldBuffer decoder was not installed.';

                  return [];
               }

               $Boundary = new ReflectionProperty($Decoder, 'boundary');
               $boundaryBytes = strlen((string) $Boundary->getValue($Decoder));
               $tailBytes = $boundaryBytes + 4;
               $initial = "--{$tag}\r\n"
                  . "Content-Disposition: form-data; name=\"payload\"\r\n"
                  . "\r\n"
                  . str_repeat(
                     'a',
                     $probe['field_buffer_held_bytes'] + $tailBytes
                  );
               $State = $Decoder->decode($Package, $initial, strlen($initial));

               if ($State !== States::Incomplete || $Package->rejected) {
                  $probe['error'] =
                     'Probe setup failed: active fieldBuffer prefix was not retained.';
                  $Decoder->disconnect();
                  $Package->Decoder = null;
                  $Request->clean();

                  return [];
               }

               return [$Connection, $Package, $Request, $Decoder, $State];
            };

            $fieldChunk = str_repeat('b', $probe['field_buffer_chunk_bytes']);
            $Prepared = $PrepareFieldBuffer('H1FieldBufferExact');
            if ($Prepared !== []) {
               [$Connection, $Package, $Request, $BufferDecoder, $BufferState] =
                  $Prepared;
               $Measure = new ReflectionMethod($BufferDecoder, 'measure');
               $Block = new ReflectionMethod($BufferDecoder, 'block');
               $FieldBuffer = new ReflectionProperty(
                  $BufferDecoder,
                  'fieldBuffer'
               );
               $Tail = new ReflectionProperty($BufferDecoder, 'tailBuffer');

               $preMeasure = (int) $Measure->invoke($BufferDecoder);
               $heldBytes = strlen(
                  (string) $FieldBuffer->getValue($BufferDecoder)
               );
               $tailBytes = strlen((string) $Tail->getValue($BufferDecoder));
               $appendMeasure = $preMeasure
                  - (int) $Block->invoke(null, $tailBytes)
                  + (int) $Block->invoke(null, 0);
               $bound = $appendMeasure + (int) $Block->invoke(
                  null,
                  $heldBytes + strlen($fieldChunk)
               );

               $probe['field_buffer_pre_state'] = $BufferState->name;
               $probe['field_buffer_pre_bytes'] = $heldBytes;
               $probe['field_buffer_pre_reserved'] =
                  $BufferDecoder->Bodies->retained;
               $probe['field_buffer_bound'] = $bound;
               $probe['field_buffer_allowed_growth'] =
                  $bound - $BufferDecoder->Bodies->retained;

               $Blocker = new Bodies;
               $probe['field_buffer_blocker_accepted'] = $Blocker->reserve(
                  $probe['field_buffer_budget'] - $bound
               );

               if (function_exists('memory_reset_peak_usage')) {
                  memory_reset_peak_usage();
               }
               $usageBefore = memory_get_usage(false);

               $BufferState = $BufferDecoder->decode(
                  $Package,
                  $fieldChunk,
                  strlen($fieldChunk)
               );

               $probe['field_buffer_peak_growth'] =
                  memory_get_peak_usage(false) - $usageBefore;
               $probe['field_buffer_final_state'] = $BufferState->name;
               $probe['field_buffer_rejected'] = $Package->rejected;
               $probe['field_buffer_final_bytes'] = strlen(
                  (string) $FieldBuffer->getValue($BufferDecoder)
               );

               $Blocker->release();
               $BufferDecoder->disconnect();
               $Package->Decoder = null;
               $Request->clean();

               $Free = new Bodies;
               $probe['field_buffer_budget_free_after'] = $Free->reserve(
                  $probe['field_buffer_budget']
               );
               $Free->release();
            }

            $Prepared = $PrepareFieldBuffer('H1FieldBufferShort');
            if ($Prepared !== []) {
               [$Connection, $ShortPackage, $ShortRequest, $ShortDecoder, $ShortState] =
                  $Prepared;
               $Measure = new ReflectionMethod($ShortDecoder, 'measure');
               $Block = new ReflectionMethod($ShortDecoder, 'block');
               $FieldBuffer = new ReflectionProperty(
                  $ShortDecoder,
                  'fieldBuffer'
               );
               $Tail = new ReflectionProperty($ShortDecoder, 'tailBuffer');

               $preMeasure = (int) $Measure->invoke($ShortDecoder);
               $heldBytes = strlen(
                  (string) $FieldBuffer->getValue($ShortDecoder)
               );
               $tailBytes = strlen((string) $Tail->getValue($ShortDecoder));
               $appendMeasure = $preMeasure
                  - (int) $Block->invoke(null, $tailBytes)
                  + (int) $Block->invoke(null, 0);
               $bound = $appendMeasure + (int) $Block->invoke(
                  null,
                  $heldBytes + strlen($fieldChunk)
               );

               $Blocker = new Bodies;
               $probe['field_buffer_short_blocker_accepted'] = $Blocker->reserve(
                  $probe['field_buffer_budget'] - $bound + 1
               );
               $ShortState = $ShortDecoder->decode(
                  $ShortPackage,
                  $fieldChunk,
                  strlen($fieldChunk)
               );
               $probe['field_buffer_short_final_state'] = $ShortState->name;
               $probe['field_buffer_short_rejected'] = $ShortPackage->rejected;
               $probe['field_buffer_short_retained'] =
                  $ShortDecoder->Bodies->retained;

               $Blocker->release();
               $ShortDecoder->disconnect();
               $ShortPackage->Decoder = null;
               $ShortRequest->clean();

               $Free = new Bodies;
               $probe['field_buffer_short_budget_free_after'] = $Free->reserve(
                  $probe['field_buffer_budget']
               );
               $Free->release();
            }
            unset($PrepareFieldBuffer);

            Bodies::$maxWorkerBodySize = $probe['probe_budget'];
         }

         // --- Leg 5.3: completion of one active field must reserve both the
         //     temporary urlencode() result and growth of the retained encoded
         //     aggregate before either allocation. A logical entry-size term
         //     alone does not bound both simultaneously at allocator cliffs.

         if ($probe['budget_available']) {
            Bodies::$maxWorkerBodySize = $probe['append_budget'];

            $appendTag = 'H1PA6';
            $appendPrefix = '';
            for ($field = 0; $field <= $probe['append_completed_fields']; $field++) {
               $index = (string) $field;
               $name = str_repeat(
                  '%',
                  $probe['append_name_bytes'] - strlen($index)
               ) . $index;
               $appendPrefix .= ($field === 0
                     ? "--{$appendTag}\r\n"
                     : "\r\n--{$appendTag}\r\n")
                  . "Content-Disposition: form-data; name=\"{$name}\"\r\n"
                  . "\r\n";
            }
            // @ Complete the delimiter line before measuring field commit:
            //   M3 validation deliberately keeps an unclassified boundary
            //   candidate in the current field state.
            $appendSuffix = "\r\n--{$appendTag}\r\n";
            $appendLength = strlen($appendPrefix) + 1024;

            [$Connection, $Package, $Request] = $open();
            $head = "POST /h1-budget-preallocate-append HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$appendTag}\r\n"
               . "Content-Length: {$appendLength}\r\n"
               . "\r\n";
            $Request->decode($Package, $head, strlen($head));
            $AppendDecoder = $Package->Decoder;

            if ($AppendDecoder instanceof Decoder_Downloading) {
               $offset = 0;
               $AppendState = States::Incomplete;
               while ($offset < strlen($appendPrefix)) {
                  $segment = substr($appendPrefix, $offset, 32 * 1024);
                  $AppendState = $AppendDecoder->decode(
                     $Package,
                     $segment,
                     strlen($segment)
                  );
                  $offset += strlen($segment);

                  if ($AppendState !== States::Incomplete) {
                     break;
                  }
               }

               $Block = new ReflectionMethod($AppendDecoder, 'block');
               $Expand = new ReflectionMethod($AppendDecoder, 'expand');
               $Weigh = new ReflectionMethod($AppendDecoder, 'weigh');
               $Encoded = new ReflectionProperty($AppendDecoder, 'fieldsEncoded');
               $CurrentFieldName = new ReflectionProperty(
                  $AppendDecoder,
                  'currentFieldName'
               );
               $FieldBuffer = new ReflectionProperty($AppendDecoder, 'fieldBuffer');
               $Fields = new ReflectionProperty($AppendDecoder, 'fields');
               $Class = new ReflectionClass($AppendDecoder);

               $encodedBytes = strlen(
                  (string) $Encoded->getValue($AppendDecoder)
               );
               $currentName = (string) $CurrentFieldName->getValue($AppendDecoder);
               $fieldBufferBytes = strlen(
                  (string) $FieldBuffer->getValue($AppendDecoder)
               );
               $index = count((array) $Fields->getValue($AppendDecoder));
               $nodes = 1 + substr_count($currentName, '[');
               $nodeOverhead = (int) $Class->getConstant('NODE_OVERHEAD');
               $entryOverhead = (int) $Class->getConstant('ENTRY_OVERHEAD');
               $mapOverhead = (int) $Class->getConstant('MAP_OVERHEAD');
               $digits = strlen((string) $index);
               $entry = (int) $Expand->invoke(null, $currentName)
                  + 2
                  + $digits;
               $temporary = (3 * strlen($currentName)) + 2 + $digits;
               $growth = (int) $Block->invoke(null, $temporary)
                  + (int) $Block->invoke(null, $encodedBytes + $entry)
                  + (int) $Block->invoke(null, $fieldBufferBytes)
                  + (int) $Weigh->invoke(null, $currentName)
                  + ($nodes * $nodeOverhead)
                  + (($index + 1) * $entryOverhead)
                  + ($index === 0 ? $mapOverhead : 0);

               $probe['append_pre_state'] = $AppendState->name;
               $probe['append_encoded_bytes'] = $encodedBytes;
               $probe['append_current_name_bytes'] = strlen($currentName);
               $probe['append_pre_reserved'] = $AppendDecoder->Bodies->retained;
               $probe['append_admitted_growth'] = $growth;
               unset($currentName);

               $Blocker = new Bodies;
               $probe['append_blocker_accepted'] = $Blocker->reserve(
                  $probe['append_budget']
                     - $AppendDecoder->Bodies->retained
                     - $growth
               );

               if (function_exists('memory_reset_peak_usage')) {
                  memory_reset_peak_usage();
               }
               $usageBefore = memory_get_usage(false);

               $AppendState = $AppendDecoder->decode(
                  $Package,
                  $appendSuffix,
                  strlen($appendSuffix)
               );

               $probe['append_peak_growth'] =
                  memory_get_peak_usage(false) - $usageBefore;
               $probe['append_final_state'] = $AppendState->name;
               $probe['append_rejected'] = $Package->rejected;
               $probe['append_retained_fields'] = count(
                  (array) $Fields->getValue($AppendDecoder)
               );
               $probe['append_post_reserved'] = $AppendDecoder->Bodies->retained;

               $Blocker->release();
               $AppendDecoder->disconnect();
               $Package->Decoder = null;
               $Request->clean();

               $Free = new Bodies;
               $probe['append_budget_free_after'] = $Free->reserve(
                  $probe['append_budget']
               );
               $Free->release();
            }

            // ! Zero-free control: the same append must be refused before
            //   urlencode() when no growth at all remains in the worker cap.
            [$Connection, $ControlPackage, $ControlRequest] = $open();
            $head = "POST /h1-budget-preallocate-append-control HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$appendTag}\r\n"
               . "Content-Length: {$appendLength}\r\n"
               . "\r\n";
            $ControlRequest->decode($ControlPackage, $head, strlen($head));
            $ControlDecoder = $ControlPackage->Decoder;

            if ($ControlDecoder instanceof Decoder_Downloading) {
               $offset = 0;
               $ControlState = States::Incomplete;
               while ($offset < strlen($appendPrefix)) {
                  $segment = substr($appendPrefix, $offset, 32 * 1024);
                  $ControlState = $ControlDecoder->decode(
                     $ControlPackage,
                     $segment,
                     strlen($segment)
                  );
                  $offset += strlen($segment);

                  if ($ControlState !== States::Incomplete) {
                     break;
                  }
               }

               $Blocker = new Bodies;
               $probe['append_zero_blocker_accepted'] = $Blocker->reserve(
                  $probe['append_budget'] - $ControlDecoder->Bodies->retained
               );

               if (function_exists('memory_reset_peak_usage')) {
                  memory_reset_peak_usage();
               }
               $usageBefore = memory_get_usage(false);

               $ControlState = $ControlDecoder->decode(
                  $ControlPackage,
                  $appendSuffix,
                  strlen($appendSuffix)
               );

               $probe['append_zero_peak_growth'] =
                  memory_get_peak_usage(false) - $usageBefore;
               $probe['append_zero_final_state'] = $ControlState->name;
               $probe['append_zero_rejected'] = $ControlPackage->rejected;

               $Blocker->release();
               $ControlDecoder->disconnect();
               $ControlPackage->Decoder = null;
               $ControlRequest->clean();

               $Free = new Bodies;
               $probe['append_zero_budget_free_after'] = $Free->reserve(
                  $probe['append_budget']
               );
               $Free->release();
            }

            Bodies::$maxWorkerBodySize = $probe['probe_budget'];
         }

         // --- Leg 5.4: a refused file record must not create a temporary file
         //     before its worker-budget reservation owns the corresponding
         //     cleanup path.

         if ($probe['budget_available']) {
            $downloadDirectory = BOOTGLY_STORAGE_DIR . 'temp/files/downloaded/';
            $snapshot = static function (string $directory): array {
               $paths = glob($directory . '*');
               if (! is_array($paths)) {
                  return [];
               }

               sort($paths);
               return $paths;
            };
            $cleanup = static function (array $paths): bool {
               $deleted = true;
               foreach ($paths as $path) {
                  if (is_file($path) && @unlink($path) !== true) {
                     $deleted = false;
                  }
               }

               return $deleted;
            };

            Bodies::$maxWorkerBodySize = $probe['file_reserve_budget'];

            $fileReserveTag = 'H1LF6';
            $fileReserveName = str_repeat(
               '%',
               $probe['file_reserve_name_bytes']
            );
            $fileReservePrefix = "--{$fileReserveTag}\r\n"
               . "Content-Disposition: form-data; name=\"{$fileReserveName}\"; "
               . "filename=\"x\"";
            $fileReserveSuffix = "\r\n\r\n";
            $fileReserveLength = strlen($fileReservePrefix)
               + strlen($fileReserveSuffix)
               + 1024;
            $before = $snapshot($downloadDirectory);

            [$Connection, $Package, $Request] = $open();
            $head = "POST /h1-budget-file-reserve HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$fileReserveTag}\r\n"
               . "Content-Length: {$fileReserveLength}\r\n"
               . "\r\n";
            $Request->decode($Package, $head, strlen($head));
            $FileReserveDecoder = $Package->Decoder;

            if ($FileReserveDecoder instanceof Decoder_Downloading) {
               $FileReserveState = $FileReserveDecoder->decode(
                  $Package,
                  $fileReservePrefix,
                  strlen($fileReservePrefix)
               );
               $probe['file_reserve_pre_state'] = $FileReserveState->name;
               $probe['file_reserve_pre_reserved'] =
                  $FileReserveDecoder->Bodies->retained;

               $Blocker = new Bodies;
               $probe['file_reserve_blocker_accepted'] = $Blocker->reserve(
                  $probe['file_reserve_budget']
                     - $FileReserveDecoder->Bodies->retained
               );

               if (function_exists('memory_reset_peak_usage')) {
                  memory_reset_peak_usage();
               }
               $usageBefore = memory_get_usage(false);

               $FileReserveState = $FileReserveDecoder->decode(
                  $Package,
                  $fileReserveSuffix,
                  strlen($fileReserveSuffix)
               );

               $probe['file_reserve_peak_growth'] =
                  memory_get_peak_usage(false) - $usageBefore;
               $probe['file_reserve_final_state'] = $FileReserveState->name;
               $probe['file_reserve_rejected'] = $Package->rejected;
               $probe['file_reserve_victim_retained'] =
                  $FileReserveDecoder->Bodies->retained;

               $after = $snapshot($downloadDirectory);
               $created = array_values(array_diff($after, $before));
               $probe['file_reserve_created'] = count($created);
               $createdZeroBytes = $created !== [];
               foreach ($created as $path) {
                  if (! is_file($path) || filesize($path) !== 0) {
                     $createdZeroBytes = false;
                  }
               }
               $probe['file_reserve_created_zero_bytes'] = $createdZeroBytes;
               $deleted = $cleanup($created);
               $probe['file_reserve_cleanup_exact'] = $deleted
                  && array_values(
                     array_diff($snapshot($downloadDirectory), $before)
                  ) === [];

               $Blocker->release();
               $FileReserveDecoder->disconnect();
               $Package->Decoder = null;
               $Request->clean();

               $Free = new Bodies;
               $probe['file_reserve_budget_free_after'] = $Free->reserve(
                  $probe['file_reserve_budget']
               );
               $Free->release();
            }

            // ! Ample-budget control: the same valid header reaches the file
            //   body, creates exactly one owned tempfile, and disconnect()
            //   removes that exact path.
            Bodies::$maxWorkerBodySize = $probe['append_budget'];
            $before = $snapshot($downloadDirectory);

            [$Connection, $ControlPackage, $ControlRequest] = $open();
            $head = "POST /h1-budget-file-reserve-control HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$fileReserveTag}\r\n"
               . "Content-Length: {$fileReserveLength}\r\n"
               . "\r\n";
            $ControlRequest->decode($ControlPackage, $head, strlen($head));
            $ControlDecoder = $ControlPackage->Decoder;

            if ($ControlDecoder instanceof Decoder_Downloading) {
               $ControlDecoder->decode(
                  $ControlPackage,
                  $fileReservePrefix,
                  strlen($fileReservePrefix)
               );
               $ControlState = $ControlDecoder->decode(
                  $ControlPackage,
                  $fileReserveSuffix,
                  strlen($fileReserveSuffix)
               );

               $created = array_values(
                  array_diff($snapshot($downloadDirectory), $before)
               );
               $probe['file_reserve_control_state'] = $ControlState->name;
               $probe['file_reserve_control_rejected'] =
                  $ControlPackage->rejected;
               $probe['file_reserve_control_created'] = count($created);

               $ControlDecoder->disconnect();
               $ControlPackage->Decoder = null;
               $ControlRequest->clean();

               $residual = array_values(
                  array_diff($snapshot($downloadDirectory), $before)
               );
               $probe['file_reserve_control_cleanup_exact'] = $residual === [];
               $cleanup($residual);

               $Free = new Bodies;
               $probe['file_reserve_control_budget_free_after'] = $Free->reserve(
                  $probe['append_budget']
               );
               $Free->release();
            }

            // ! Exact current file-record admission with populated packed
            //   arrays. Appending the 65th record can replace BOTH outer
            //   destinations while their old 64-slot storage is live.
            Bodies::$maxWorkerBodySize = $probe['file_record_budget'];

            $PrepareFileRecord = function (string $tag) use (
               $open,
               &$probe
            ): array {
               $prefix = '';
               for (
                  $file = 0;
                  $file < $probe['file_record_existing'];
                  $file++
               ) {
                  $prefix .= ($file === 0
                        ? "--{$tag}\r\n"
                        : "\r\n--{$tag}\r\n")
                     . "Content-Disposition: form-data; name=\"existing{$file}\"; "
                     . "filename=\"{$file}.txt\"\r\n"
                     . "Content-Type: text/plain\r\n"
                     . "\r\n"
                     . 'x';
               }
               $prefix .= "\r\n--{$tag}\r\n"
                  . "Content-Disposition: form-data; name=\"next\"; "
                  . "filename=\"next.txt\"\r\n"
                  . 'Content-Type: text/plain';

               [$Connection, $Package, $Request] = $open();
               $head = "POST /h1-budget-file-record HTTP/1.1\r\n"
                  . "Host: localhost\r\n"
                  . "Content-Type: multipart/form-data; boundary={$tag}\r\n"
                  . "Content-Length: {$probe['declared']}\r\n"
                  . "\r\n";
               $HeadState = $Request->decode($Package, $head, strlen($head));
               $Decoder = $Package->Decoder;
               if (
                  $HeadState === States::Rejected
                  || ! $Decoder instanceof Decoder_Downloading
               ) {
                  $probe['error'] =
                     'Probe setup failed: file-record decoder was not installed.';

                  return [];
               }

               $State = $Decoder->decode($Package, $prefix, strlen($prefix));
               $Files = new ReflectionProperty($Decoder, 'files');
               if (
                  $State !== States::Incomplete
                  || $Package->rejected
                  || count((array) $Files->getValue($Decoder))
                     !== $probe['file_record_existing']
               ) {
                  $probe['error'] =
                     'Probe setup failed: existing file records were not retained.';
                  $Decoder->disconnect();
                  $Package->Decoder = null;
                  $Request->clean();

                  return [];
               }

               return [$Connection, $Package, $Request, $Decoder, $State];
            };

            $PriceFileRecord = static function (
               Decoder_Downloading $Decoder
            ): array {
               $Measure = new ReflectionMethod($Decoder, 'measure');
               $Block = new ReflectionMethod($Decoder, 'block');
               $Weigh = new ReflectionMethod($Decoder, 'weigh');
               $Header = new ReflectionProperty($Decoder, 'headerBuffer');
               $Files = new ReflectionProperty($Decoder, 'files');
               $FilesKeys = new ReflectionProperty($Decoder, 'filesKeys');
               $Class = new ReflectionClass($Decoder);

               $headerBytes = strlen((string) $Header->getValue($Decoder));
               $files = count((array) $Files->getValue($Decoder));
               $filesKeys = count((array) $FilesKeys->getValue($Decoder));
               $nodeOverhead = (int) $Class->getConstant('NODE_OVERHEAD');
               $entryOverhead = (int) $Class->getConstant('ENTRY_OVERHEAD');
               $mapOverhead = (int) $Class->getConstant('MAP_OVERHEAD');
               $recordSlots = (int) $Class->getConstant('RECORD_SLOTS');
               $uploadKey = 'next';

               $recordMeasure = (int) $Measure->invoke($Decoder)
                  - (int) $Block->invoke(null, $headerBytes)
                  + (int) $Block->invoke(null, 0);
               $growth = (int) $Block->invoke(null, strlen($uploadKey))
                  + (int) $Weigh->invoke(null, $uploadKey)
                  + $nodeOverhead
                  + $nodeOverhead
                  + ($recordSlots * $entryOverhead)
                  + (($filesKeys + 1) * $entryOverhead)
                  + (($files + 1) * $entryOverhead)
                  + (int) $Block->invoke(null, strlen('next.txt'))
                  + (int) $Block->invoke(null, PHP_MAXPATHLEN)
                  + (int) $Block->invoke(null, strlen('text/plain'));
               if ($filesKeys === 0) {
                  $growth += $mapOverhead;
               }
               if ($files === 0) {
                  $growth += $mapOverhead;
               }

               return [
                  'measure' => $recordMeasure,
                  'growth' => $growth,
                  'bound' => $recordMeasure + $growth,
                  'files' => $files,
               ];
            };

            $before = $snapshot($downloadDirectory);
            $downloadsBefore = Downloads::peek();
            $Prepared = $PrepareFileRecord('H1FileRecordExact');
            if ($Prepared !== []) {
               [$Connection, $Package, $Request, $RecordDecoder, $RecordState] =
                  $Prepared;
               $Price = $PriceFileRecord($RecordDecoder);
               $Files = new ReflectionProperty($RecordDecoder, 'files');

               $probe['file_record_pre_state'] = $RecordState->name;
               $probe['file_record_pre_files'] = $Price['files'];
               $probe['file_record_pre_reserved'] =
                  $RecordDecoder->Bodies->retained;
               $probe['file_record_bound'] = $Price['bound'];
               $probe['file_record_allowed_growth'] =
                  $Price['bound'] - $RecordDecoder->Bodies->retained;

               $Blocker = new Bodies;
               $probe['file_record_blocker_accepted'] = $Blocker->reserve(
                  $probe['file_record_budget'] - $Price['bound']
               );

               if (function_exists('memory_reset_peak_usage')) {
                  memory_reset_peak_usage();
               }
               $usageBefore = memory_get_usage(false);

               $RecordState = $RecordDecoder->decode(
                  $Package,
                  "\r\n\r\n",
                  4
               );

               $probe['file_record_peak_growth'] =
                  memory_get_peak_usage(false) - $usageBefore;
               $probe['file_record_final_state'] = $RecordState->name;
               $probe['file_record_rejected'] = $Package->rejected;
               $probe['file_record_final_files'] = count(
                  (array) $Files->getValue($RecordDecoder)
               );
               $probe['file_record_created'] = count(
                  array_values(
                     array_diff($snapshot($downloadDirectory), $before)
                  )
               );

               $RecordDecoder->disconnect();
               $Package->Decoder = null;
               $Request->clean();
               $Blocker->release();

               $residual = array_values(
                  array_diff($snapshot($downloadDirectory), $before)
               );
               $probe['file_record_cleanup_exact'] = $residual === [];
               foreach ($residual as $path) {
                  Downloads::discard($path);
               }
               $cleanup($residual);
               $probe['file_record_downloads_exact'] =
                  Downloads::peek() === $downloadsBefore;

               $Free = new Bodies;
               $probe['file_record_budget_free_after'] = $Free->reserve(
                  $probe['file_record_budget']
               );
               $Free->release();
            }

            $before = $snapshot($downloadDirectory);
            $downloadsBefore = Downloads::peek();
            $Prepared = $PrepareFileRecord('H1FileRecordShort');
            if ($Prepared !== []) {
               [$Connection, $ShortPackage, $ShortRequest, $ShortDecoder, $ShortState] =
                  $Prepared;
               $Price = $PriceFileRecord($ShortDecoder);

               $Blocker = new Bodies;
               $probe['file_record_short_blocker_accepted'] = $Blocker->reserve(
                  $probe['file_record_budget'] - $Price['bound'] + 1
               );
               $ShortState = $ShortDecoder->decode(
                  $ShortPackage,
                  "\r\n\r\n",
                  4
               );
               $probe['file_record_short_final_state'] = $ShortState->name;
               $probe['file_record_short_rejected'] = $ShortPackage->rejected;
               $probe['file_record_short_retained'] =
                  $ShortDecoder->Bodies->retained;
               $probe['file_record_short_created'] = count(
                  array_values(
                     array_diff($snapshot($downloadDirectory), $before)
                  )
               );

               $ShortDecoder->disconnect();
               $ShortPackage->Decoder = null;
               $ShortRequest->clean();
               $Blocker->release();

               $residual = array_values(
                  array_diff($snapshot($downloadDirectory), $before)
               );
               $probe['file_record_short_cleanup_exact'] = $residual === [];
               foreach ($residual as $path) {
                  Downloads::discard($path);
               }
               $cleanup($residual);
               $probe['file_record_short_downloads_exact'] =
                  Downloads::peek() === $downloadsBefore;

               $Free = new Bodies;
               $probe['file_record_short_budget_free_after'] = $Free->reserve(
                  $probe['file_record_budget']
               );
               $Free->release();
            }
            unset($PrepareFileRecord, $PriceFileRecord);

            // ! Application error handlers may promote even `@`-suppressed
            //   warnings. Prove normal exclusive creation stays inside the
            //   intended directory and decoder cleanup remains no-throw.
            $warningBefore = $snapshot($downloadDirectory);
            $warningDownloadsBefore = Downloads::peek();
            $WarningPackage = null;
            $WarningRequest = null;
            $WarningDecoder = null;
            $handlerInstalled = false;
            $ThrowWarnings = static function (
               int $severity,
               string $message,
               string $file,
               int $line
            ): never {
               throw new ErrorException(
                  $message,
                  0,
                  $severity,
                  $file,
                  $line
               );
            };

            try {
               set_error_handler($ThrowWarnings);
               $handlerInstalled = true;
               $warningTag = 'H1FileWarningOwnership';
               $warningPrefix = "--{$warningTag}\r\n"
                  . "Content-Disposition: form-data; name=\"owned\"; "
                  . "filename=\"owned.txt\"\r\n"
                  . "Content-Type: text/plain\r\n"
                  . "\r\n"
                  . 'x';
               [$WarningConnection, $WarningPackage, $WarningRequest] = $open();
               $head = "POST /h1-file-warning-ownership HTTP/1.1\r\n"
                  . "Host: localhost\r\n"
                  . "Content-Type: multipart/form-data; boundary={$warningTag}\r\n"
                  . "Content-Length: {$probe['declared']}\r\n"
                  . "\r\n";
               $HeadState = $WarningRequest->decode(
                  $WarningPackage,
                  $head,
                  strlen($head)
               );
               $WarningDecoder = $WarningPackage->Decoder;
               if (
                  $HeadState === States::Rejected
                  || ! $WarningDecoder instanceof Decoder_Downloading
               ) {
                  throw new RuntimeException(
                     'Warning-ownership decoder was not installed.'
                  );
               }

               $WarningState = $WarningDecoder->decode(
                  $WarningPackage,
                  $warningPrefix,
                  strlen($warningPrefix)
               );
               $Files = new ReflectionProperty($WarningDecoder, 'files');
               $files = (array) $Files->getValue($WarningDecoder);
               $tmpName = (string) ($files[0]['tmp_name'] ?? '');

               $probe['file_warning_state'] = $WarningState->name;
               $probe['file_warning_rejected'] = $WarningPackage->rejected;
               $probe['file_warning_inside_directory'] = $tmpName !== ''
                  && dirname($tmpName) === rtrim(
                     $downloadDirectory,
                     DIRECTORY_SEPARATOR
                  );
               $probe['file_warning_created'] = count(
                  array_values(
                     array_diff(
                        $snapshot($downloadDirectory),
                        $warningBefore
                     )
                  )
               );

               try {
                  $WarningDecoder->disconnect();
               }
               catch (Throwable) {
                  $probe['file_warning_cleanup_threw'] = true;
               }
               $WarningPackage->Decoder = null;
               $WarningRequest->clean();
            }
            catch (Throwable $Throwable) {
               $probe['error'] = 'Warning-ownership probe failed: '
                  . $Throwable->getMessage();
            }
            finally {
               if ($handlerInstalled) {
                  restore_error_handler();
               }

               if ($WarningDecoder instanceof Disconnecting) {
                  try {
                     $WarningDecoder->disconnect();
                  }
                  catch (Throwable) {
                     $probe['file_warning_cleanup_threw'] = true;
                  }
               }
               if ($WarningPackage instanceof TCPPackages) {
                  $WarningPackage->Decoder = null;
               }
               if ($WarningRequest instanceof Request) {
                  $WarningRequest->clean();
               }
            }
            unset($ThrowWarnings);

            $residual = array_values(
               array_diff(
                  $snapshot($downloadDirectory),
                  $warningBefore
               )
            );
            $probe['file_warning_cleanup_exact'] = $residual === [];
            foreach ($residual as $path) {
               Downloads::discard($path);
            }
            $cleanup($residual);
            $probe['file_warning_downloads_exact'] =
               Downloads::peek() === $warningDownloadsBefore;

            $Free = new Bodies;
            $probe['file_warning_budget_free_after'] = $Free->reserve(
               $probe['file_record_budget']
            );
            $Free->release();

            // ! parse_str() silently collapses duplicate/normalized file keys.
            //   The decoder must reject that ambiguous map while it still owns
            //   every exact tempfile; distinct keys are the matched control.
            Bodies::$maxWorkerBodySize = $probe['append_budget'];
            $exercise = function (string $tag, array $keys) use (
               $open,
               $snapshot,
               $cleanup,
               $downloadDirectory,
               &$probe
            ): array {
               $body = '';
               foreach ($keys as $index => $key) {
                  $body .= ($index === 0
                        ? "--{$tag}\r\n"
                        : "\r\n--{$tag}\r\n")
                     . "Content-Disposition: form-data; name=\"{$key}\"; "
                     . "filename=\"{$index}.txt\"\r\n"
                     . "Content-Type: text/plain\r\n"
                     . "\r\n"
                     . (string) $index;
               }
               $body .= "\r\n--{$tag}--\r\n";

               $before = $snapshot($downloadDirectory);
               $downloadsBefore = Downloads::peek();
               [$Connection, $Package, $Request] = $open();
               $head = "POST /h1-budget-file-collision HTTP/1.1\r\n"
                  . "Host: localhost\r\n"
                  . "Content-Type: multipart/form-data; boundary={$tag}\r\n"
                  . 'Content-Length: ' . strlen($body) . "\r\n"
                  . "\r\n";
               $HeadState = $Request->decode($Package, $head, strlen($head));
               $Decoder = $Package->Decoder;
               if (
                  $HeadState === States::Rejected
                  || ! $Decoder instanceof Decoder_Downloading
               ) {
                  $probe['error'] = 'Probe setup failed: file-collision decoder was not installed.';
                  return [];
               }

               $State = $Decoder->decode($Package, $body, strlen($body));
               $files = count($Request->files);
               $created = count(
                  array_values(
                     array_diff($snapshot($downloadDirectory), $before)
                  )
               );

               if ($Package->Decoder instanceof Disconnecting) {
                  $Package->Decoder->disconnect();
               }
               $Package->Decoder = null;
               $Request->clean();

               $residual = array_values(
                  array_diff($snapshot($downloadDirectory), $before)
               );
               $cleanupExact = $residual === [];
               foreach ($residual as $path) {
                  Downloads::discard($path);
               }
               $cleanup($residual);

               $downloadsExact = Downloads::peek() === $downloadsBefore;
               $Free = new Bodies;
               $budgetFree = $Free->reserve($probe['append_budget']);
               $Free->release();

               return [
                  'state' => $State->name,
                  'rejected' => $Package->rejected,
                  'files' => $files,
                  'created' => $created,
                  'cleanup' => $cleanupExact,
                  'downloads' => $downloadsExact,
                  'budget' => $budgetFree,
               ];
            };

            $collision = $exercise('H1FileCollision', ['same', 'same']);
            if ($collision !== []) {
               $probe['file_collision_state'] = $collision['state'];
               $probe['file_collision_rejected'] = $collision['rejected'];
               $probe['file_collision_files'] = $collision['files'];
               $probe['file_collision_cleanup_exact'] = $collision['cleanup'];
               $probe['file_collision_downloads_exact'] = $collision['downloads'];
               $probe['file_collision_budget_free_after'] = $collision['budget'];
            }

            $control = $exercise('H1FileUnique', ['left', 'right']);
            if ($control !== []) {
               $probe['file_collision_control_state'] = $control['state'];
               $probe['file_collision_control_rejected'] = $control['rejected'];
               $probe['file_collision_control_files'] = $control['files'];
               $probe['file_collision_control_created'] = $control['created'];
               $probe['file_collision_control_cleanup_exact'] = $control['cleanup'];
               $probe['file_collision_control_downloads_exact'] = $control['downloads'];
            }

            // ! Exact current file-finish projection: URL-encode scratch,
            //   aggregate copies, decoded maps and the ownership-bijection map
            //   must all fit before the terminal boundary is accepted.
            Bodies::$maxWorkerBodySize = $probe['file_projection_budget'];
            $fileProjectionTag = 'H1FileProjection';
            $fileProjectionPrefix = '';
            $fileProjectionNames = [];
            for ($file = 0; $file < $probe['file_projection_files']; $file++) {
               $index = (string) $file;
               $name = str_repeat(
                  'p',
                  $probe['file_projection_name_bytes'] - strlen($index)
               ) . $index;
               $fileProjectionNames[] = $name;
               $fileProjectionPrefix .= ($file === 0
                     ? "--{$fileProjectionTag}\r\n"
                     : "\r\n--{$fileProjectionTag}\r\n")
                  . "Content-Disposition: form-data; name=\"{$name}\"; "
                  . "filename=\"{$index}.txt\"\r\n"
                  . "Content-Type: text/plain\r\n"
                  . "\r\n"
                  . 'x';
            }
            $fileProjectionPrefix .= "\r\n--{$fileProjectionTag}";
            $fileProjectionSuffix = "--\r\n";
            $fileProjectionLength = strlen($fileProjectionPrefix)
               + strlen($fileProjectionSuffix);

            $before = $snapshot($downloadDirectory);
            $downloadsBefore = Downloads::peek();
            [$Connection, $ProjectionPackage, $ProjectionRequest] = $open();
            $head = "POST /h1-budget-file-projection HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; "
               . "boundary={$fileProjectionTag}\r\n"
               . "Content-Length: {$fileProjectionLength}\r\n"
               . "\r\n";
            $ProjectionRequest->decode(
               $ProjectionPackage,
               $head,
               strlen($head)
            );
            $ProjectionDecoder = $ProjectionPackage->Decoder;

            if ($ProjectionDecoder instanceof Decoder_Downloading) {
               $ProjectionState = $ProjectionDecoder->decode(
                  $ProjectionPackage,
                  $fileProjectionPrefix,
                  strlen($fileProjectionPrefix)
               );
               $Measure = new ReflectionMethod($ProjectionDecoder, 'measure');
               $Block = new ReflectionMethod($ProjectionDecoder, 'block');
               $Expand = new ReflectionMethod($ProjectionDecoder, 'expand');
               $Tail = new ReflectionProperty($ProjectionDecoder, 'tailBuffer');
               $Encoded = new ReflectionProperty(
                  $ProjectionDecoder,
                  'fieldsEncoded'
               );
               $KeysBlocks = new ReflectionProperty(
                  $ProjectionDecoder,
                  'keysBlocks'
               );
               $Nodes = new ReflectionProperty($ProjectionDecoder, 'nodes');
               $Files = new ReflectionProperty($ProjectionDecoder, 'files');
               $Class = new ReflectionClass($ProjectionDecoder);

               $preMeasure = (int) $Measure->invoke($ProjectionDecoder);
               $tailBytes = strlen((string) $Tail->getValue($ProjectionDecoder));
               $encodedBytes = strlen(
                  (string) $Encoded->getValue($ProjectionDecoder)
               );
               $keysBlocks = (int) $KeysBlocks->getValue($ProjectionDecoder);
               $nodes = (int) $Nodes->getValue($ProjectionDecoder);
               $files = count((array) $Files->getValue($ProjectionDecoder));
               $nodeOverhead = (int) $Class->getConstant('NODE_OVERHEAD');
               $entryOverhead = (int) $Class->getConstant('ENTRY_OVERHEAD');
               $mapOverhead = (int) $Class->getConstant('MAP_OVERHEAD');
               $finishMeasure = $preMeasure
                  - (int) $Block->invoke(null, $tailBytes)
                  + (int) $Block->invoke(null, 0);
               $aggregate = 0;
               $temporary = 0;
               foreach ($fileProjectionNames as $index => $name) {
                  $digits = strlen((string) $index);
                  $aggregate += (int) $Expand->invoke(null, $name)
                     + 2
                     + $digits;
                  $candidate = (3 * strlen($name)) + 2 + $digits;
                  if ($candidate > $temporary) {
                     $temporary = $candidate;
                  }
               }
               $projection = $finishMeasure
                  + (int) $Block->invoke(null, $encodedBytes)
                  + $keysBlocks
                  + ($nodes * ($nodeOverhead + $entryOverhead))
                  + $mapOverhead
                  + $mapOverhead
                  + ($files * $entryOverhead)
                  + (2 * (int) $Block->invoke(null, $aggregate))
                  + (int) $Block->invoke(null, $temporary);

               $probe['file_projection_pre_state'] = $ProjectionState->name;
               $probe['file_projection_pre_reserved'] =
                  $ProjectionDecoder->Bodies->retained;
               $probe['file_projection_bound'] = $projection;
               $probe['file_projection_allowed_growth'] =
                  $projection - $ProjectionDecoder->Bodies->retained;

               $Blocker = new Bodies;
               $probe['file_projection_blocker_accepted'] = $Blocker->reserve(
                  $probe['file_projection_budget'] - $projection
               );

               if (function_exists('memory_reset_peak_usage')) {
                  memory_reset_peak_usage();
               }
               $usageBefore = memory_get_usage(false);

               $ProjectionState = $ProjectionDecoder->decode(
                  $ProjectionPackage,
                  $fileProjectionSuffix,
                  strlen($fileProjectionSuffix)
               );

               $probe['file_projection_peak_growth'] =
                  memory_get_peak_usage(false) - $usageBefore;
               $probe['file_projection_final_state'] = $ProjectionState->name;
               $probe['file_projection_rejected'] =
                  $ProjectionPackage->rejected;
               $probe['file_projection_result_files'] =
                  count($ProjectionRequest->files);

               $ProjectionRequest->clean();
               $ProjectionDecoder->disconnect();
               $ProjectionPackage->Decoder = null;
               $Blocker->release();

               $residual = array_values(
                  array_diff($snapshot($downloadDirectory), $before)
               );
               $probe['file_projection_cleanup_exact'] = $residual === [];
               foreach ($residual as $path) {
                  Downloads::discard($path);
               }
               $cleanup($residual);
               $probe['file_projection_downloads_exact'] =
                  Downloads::peek() === $downloadsBefore;

               $Free = new Bodies;
               $probe['file_projection_budget_free_after'] = $Free->reserve(
                  $probe['file_projection_budget']
               );
               $Free->release();
            }

            Bodies::$maxWorkerBodySize = $probe['probe_budget'];
         }

         // --- Leg 6: file upload keys are transformed again at finish().
         //     Their URL-encoded shaping must fit before it is allocated.

         if ($probe['budget_available']) {
            Bodies::$maxWorkerBodySize = $probe['file_transform_budget'];
         }

         $fileBoundary = 'BootglyH1Files';
         $filePrefix = '';
         $fileEncodedBytes = 0;
         for ($fileIndex = 0; $fileIndex < $probe['file_transform_parts']; $fileIndex++) {
            $index = (string) $fileIndex;
            $name = str_repeat(
               '%',
               $probe['file_transform_name_bytes'] - strlen($index)
            ) . $index;
            $fileEncodedBytes += strlen(urlencode($name) . '=' . $fileIndex . '&');
            $filePrefix .= ($fileIndex === 0
                  ? "--{$fileBoundary}\r\n"
                  : "\r\n--{$fileBoundary}\r\n")
               . "Content-Disposition: form-data; name=\"{$name}\"; filename=\"x\"\r\n"
               . "\r\n";
         }
         // ! Split only the closing `--`: immediately before it, every raw key
         //   is retained and charged, but finish() has not shaped the key map.
         $filePrefix .= "\r\n--{$fileBoundary}";
         $fileSuffix = "--\r\n";

         [$Connection, $Package, $Request] = $open();
         $fileLength = strlen($filePrefix) + strlen($fileSuffix);
         $head = "POST /h1-budget-file-transform HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$fileBoundary}\r\n"
            . "Content-Length: {$fileLength}\r\n"
            . "\r\n";
         if ($Request->decode($Package, $head, strlen($head)) === States::Rejected) {
            $probe['error'] = 'Probe setup failed: file-transform head was rejected.';
            return $harness;
         }
         $Decoder = $Package->Decoder;
         if (! $Decoder instanceof Decoder_Downloading) {
            $probe['error'] = 'Probe setup failed: file-transform decoder was not installed.';
            return $harness;
         }

         $offset = 0;
         $FileState = States::Incomplete;
         while ($offset < strlen($filePrefix)) {
            $segment = substr($filePrefix, $offset, 32 * 1024);
            $FileState = $Decoder->decode($Package, $segment, strlen($segment));
            $offset += strlen($segment);

            if ($FileState !== States::Incomplete) {
               break;
            }
         }

         $probe['file_transform_pre_state'] = $FileState->name;
         $probe['file_transform_raw_retained'] = $Decoder->Bodies->retained;
         $probe['file_transform_encoded_bytes'] = $fileEncodedBytes;
         $probe['file_transform_raw_fits'] = $Decoder->Bodies->retained
            < $probe['file_transform_budget'];
         $probe['file_transform_encoded_does_not_fit'] = $fileEncodedBytes
            > $probe['file_transform_budget'] - $Decoder->Bodies->retained;

         if (
            $FileState !== States::Incomplete
            || $Package->rejected
            || $probe['file_transform_raw_fits'] !== true
            || $probe['file_transform_encoded_does_not_fit'] !== true
         ) {
            $probe['error'] = 'Probe setup failed: file-transform preconditions were not established.';
            $Request->clean();
            $Decoder->disconnect();
            return $harness;
         }

         $FileState = $Decoder->decode($Package, $fileSuffix, strlen($fileSuffix));
         $probe['file_transform_final_state'] = $FileState->name;
         $probe['file_transform_rejected'] = $Package->rejected;
         $Request->clean();
         $Decoder->disconnect();
         $Package->Decoder = null;

         if ($probe['budget_available']) {
            $FileFree = new Bodies;
            $probe['file_transform_budget_free_after'] = $FileFree->reserve(
               $probe['file_transform_budget']
            );
            $FileFree->release();
         }

         // ! Positive control: normal short keys still complete and reach the
         //   owning Request, proving a reject-all implementation cannot pass.
         $controlBoundary = 'BootglyH1FilesControl';
         $controlBody = '';
         for ($fileIndex = 0; $fileIndex < 2; $fileIndex++) {
            $controlBody .= ($fileIndex === 0
                  ? "--{$controlBoundary}\r\n"
                  : "\r\n--{$controlBoundary}\r\n")
               . "Content-Disposition: form-data; name=\"safe{$fileIndex}\"; filename=\"x\"\r\n"
               . "\r\n";
         }
         $controlBody .= "\r\n--{$controlBoundary}--\r\n";

         [$Connection, $ControlPackage, $ControlRequest] = $open();
         $controlLength = strlen($controlBody);
         $head = "POST /h1-budget-file-transform-control HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$controlBoundary}\r\n"
            . "Content-Length: {$controlLength}\r\n"
            . "\r\n";
         $ControlRequest->decode($ControlPackage, $head, strlen($head));
         $ControlDecoder = $ControlPackage->Decoder;
         if ($ControlDecoder instanceof Decoder_Downloading) {
            $ControlState = $ControlDecoder->decode(
               $ControlPackage,
               $controlBody,
               $controlLength
            );
            $probe['file_transform_control_state'] = $ControlState->name;
            $probe['file_transform_control_files'] = count($ControlRequest->files);
            $ControlRequest->clean();
            $ControlDecoder->disconnect();
            $ControlPackage->Decoder = null;
         }

         if ($probe['budget_available']) {
            Bodies::$maxWorkerBodySize = $probe['probe_budget'];
         }

         // --- Leg 6.1: the EXACT admission boundary. A blocker reservation
         //     models other correctly accounted peers and leaves free exactly
         //     what the superseded bound asked for (`3*key + index + 1`).
         //     Completion must still refuse, because that bound omitted one of
         //     the two delimiters and the whole key map `parse_str()` decodes
         //     back while the raw keys and the encoded string still coexist.

         if ($probe['budget_available']) {
            Bodies::$maxWorkerBodySize = $probe['boundary_budget'];

            $edgeTag = 'BootglyH1Edge';
            $edgePrefix = '';
            $legacy = 0;
            for ($part = 0; $part < $probe['boundary_parts']; $part++) {
               $index = (string) $part;
               // ! Every byte of the key URL-expands to three.
               $name = str_repeat(
                  '%',
                  $probe['boundary_name_bytes'] - strlen($index)
               ) . $index;
               $legacy += (strlen($name) * 3) + strlen($index) + 1;
               $edgePrefix .= ($part === 0
                     ? "--{$edgeTag}\r\n"
                     : "\r\n--{$edgeTag}\r\n")
                  . "Content-Disposition: form-data; name=\"{$name}\"; filename=\"x\"\r\n"
                  . "\r\n";
            }
            $edgePrefix .= "\r\n--{$edgeTag}";
            $edgeSuffix = "--\r\n";

            [$Connection, $Package, $Request] = $open();
            $edgeLength = strlen($edgePrefix) + strlen($edgeSuffix);
            $head = "POST /h1-budget-boundary HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$edgeTag}\r\n"
               . "Content-Length: {$edgeLength}\r\n"
               . "\r\n";
            $Request->decode($Package, $head, strlen($head));
            $EdgeDecoder = $Package->Decoder;

            if ($EdgeDecoder instanceof Decoder_Downloading) {
               $offset = 0;
               $EdgeState = States::Incomplete;
               while ($offset < strlen($edgePrefix)) {
                  $segment = substr($edgePrefix, $offset, 32 * 1024);
                  $EdgeState = $EdgeDecoder->decode($Package, $segment, strlen($segment));
                  $offset += strlen($segment);

                  if ($EdgeState !== States::Incomplete) {
                     break;
                  }
               }

               $probe['boundary_raw_retained'] = $EdgeDecoder->Bodies->retained;
               $probe['boundary_legacy_projection'] = $legacy;

               // ! Occupy the rest, so the free remainder is precisely the
               //   superseded projection — the exact boundary, not a margin.
               $Blocker = new Bodies;
               $probe['boundary_blocker_accepted'] = $Blocker->reserve(
                  $probe['boundary_budget'] - $probe['boundary_raw_retained'] - $legacy
               );

               $EdgeState = $EdgeDecoder->decode($Package, $edgeSuffix, strlen($edgeSuffix));
               $probe['boundary_final_state'] = $EdgeState->name;
               $probe['boundary_rejected'] = $Package->rejected;
               $probe['boundary_files'] = count($Request->files);

               $Blocker->release();
               $Request->clean();
               $EdgeDecoder->disconnect();
               $Package->Decoder = null;

               $Free = new Bodies;
               $probe['boundary_budget_free_after'] = $Free->reserve($probe['boundary_budget']);
               $Free->release();
            }

            Bodies::$maxWorkerBodySize = $probe['probe_budget'];
         }

         // --- Leg 6.2: bracket syntax makes parse_str() allocate one decoded
         //     HashTable path per nesting level. A flat per-part allowance
         //     cannot price this attacker-controlled amplification.

         if ($probe['budget_available']) {
            Bodies::$maxWorkerBodySize = $probe['nested_budget'];

            $nestedTag = 'BootglyH1Nested';
            $nestedPrefix = '';
            for ($field = 0; $field < $probe['nested_fields']; $field++) {
               $name = 'nested' . $field . str_repeat('[%]', $probe['nested_depth']);
               $nestedPrefix .= "--{$nestedTag}\r\n"
                  . "Content-Disposition: form-data; name=\"{$name}\"\r\n"
                  . "\r\n"
                  . "x\r\n";
            }
            $nestedPrefix .= "--{$nestedTag}";
            $nestedSuffix = "--\r\n";
            $nestedBody = $nestedPrefix . $nestedSuffix;
            $nestedLength = strlen($nestedBody);

            $CountArrays = function (mixed $value) use (&$CountArrays): int {
               if (! is_array($value)) {
                  return 0;
               }

               $arrays = 1;
               foreach ($value as $child) {
                  $arrays += $CountArrays($child);
               }

               return $arrays;
            };

            [$Connection, $Package, $Request] = $open();
            $head = "POST /h1-budget-nested HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$nestedTag}\r\n"
               . "Content-Length: {$nestedLength}\r\n"
               . "\r\n";
            $Request->decode($Package, $head, strlen($head));
            $NestedDecoder = $Package->Decoder;

            if ($NestedDecoder instanceof Decoder_Downloading) {
               $offset = 0;
               $NestedState = States::Incomplete;
               while ($offset < strlen($nestedPrefix)) {
                  $segment = substr($nestedPrefix, $offset, 32 * 1024);
                  $NestedState = $NestedDecoder->decode($Package, $segment, strlen($segment));
                  $offset += strlen($segment);

                  if ($NestedState !== States::Incomplete) {
                     break;
                  }
               }

               $probe['nested_pre_state'] = $NestedState->name;

               $Measure = new ReflectionMethod($NestedDecoder, 'measure');
               $Tail = new ReflectionProperty($NestedDecoder, 'tailBuffer');
               $Encoded = new ReflectionProperty($NestedDecoder, 'fieldsEncoded');
               $Fields = new ReflectionProperty($NestedDecoder, 'fields');

               $preMeasure = (int) $Measure->invoke($NestedDecoder);
               $tailBytes = strlen((string) $Tail->getValue($NestedDecoder));
               $encoded = (string) $Encoded->getValue($NestedDecoder);
               $fields = (array) $Fields->getValue($NestedDecoder);

               $probe['nested_finish_measure'] = $preMeasure - $tailBytes;
               $probe['nested_projection'] = $probe['nested_finish_measure']
                  + strlen($encoded)
                  + count($fields) * 64;

               // ! Leave exactly the current flat projection available.
               $Blocker = new Bodies;
               $probe['nested_blocker_accepted'] = $Blocker->reserve(
                  $probe['nested_budget'] - $probe['nested_projection']
               );

               if (function_exists('memory_reset_peak_usage')) {
                  memory_reset_peak_usage();
               }
               $usageBefore = memory_get_usage(false);

               $NestedState = $NestedDecoder->decode(
                  $Package,
                  $nestedSuffix,
                  strlen($nestedSuffix)
               );
               $probe['nested_peak_delta'] = memory_get_peak_usage(false) - $usageBefore;
               $probe['nested_final_state'] = $NestedState->name;
               $probe['nested_rejected'] = $Package->rejected;
               $probe['nested_result_fields'] = count($Request->fields);
               $probe['nested_array_nodes'] = $CountArrays($Request->fields);

               // ! Probe rejection cleanup while the blocker is still live,
               //   before manual Request/decoder teardown can mask a leak.
               $Released = new Bodies;
               $probe['nested_released_exactly'] = $Released->reserve(
                  $probe['nested_projection']
               );
               $probe['nested_one_more_refused'] = $Released->reserve(
                  $probe['nested_projection'] + 1
               ) === false;
               $Released->release();

               $Request->clean();
               $NestedDecoder->disconnect();
               $Package->Decoder = null;
               $Blocker->release();

               $Free = new Bodies;
               $probe['nested_budget_free_after'] = $Free->reserve($probe['nested_budget']);
               $Free->release();
            }

            // ! Same nested payload with ample budget is the positive control:
            //   the parser, bootstrap and fixture must be capable of completion.
            Bodies::$maxWorkerBodySize = $probe['nested_control_budget'];
            [$Connection, $ControlPackage, $ControlRequest] = $open();
            $head = "POST /h1-budget-nested-control HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$nestedTag}\r\n"
               . "Content-Length: {$nestedLength}\r\n"
               . "\r\n";
            $ControlRequest->decode($ControlPackage, $head, strlen($head));
            $ControlDecoder = $ControlPackage->Decoder;

            if ($ControlDecoder instanceof Decoder_Downloading) {
               $ControlState = $ControlDecoder->decode(
                  $ControlPackage,
                  $nestedBody,
                  $nestedLength
               );
               $probe['nested_control_state'] = $ControlState->name;
               $probe['nested_control_fields'] = count($ControlRequest->fields);
               $probe['nested_control_array_nodes'] = $CountArrays($ControlRequest->fields);

               $ControlRequest->clean();
               $ControlDecoder->disconnect();
               $ControlPackage->Decoder = null;
            }

            Bodies::$maxWorkerBodySize = $probe['probe_budget'];
         }

         // --- Leg 6.3: leave EXACTLY the CURRENT finish() projection free.
         //     Long field names make parse_str() retain decoded keys while its
         //     mutable query copy coexists with the original encoded string.
         //     If the real peak growth is larger than the growth admitted by
         //     the ledger, the current projection is not a memory bound.

         if ($probe['budget_available']) {
            $warm = function (
               string $tag,
               string $prefix,
               string $suffix
            ) use ($open, &$probe): void {
               $length = strlen($prefix) + strlen($suffix);
               for ($run = 0; $run < 8; $run++) {
                  [$WarmConnection, $WarmPackage, $WarmRequest] = $open();
                  $head = "POST /h1-budget-projection-warmup HTTP/1.1\r\n"
                     . "Host: localhost\r\n"
                     . "Content-Type: multipart/form-data; boundary={$tag}\r\n"
                     . "Content-Length: {$length}\r\n"
                     . "\r\n";
                  $WarmRequest->decode($WarmPackage, $head, strlen($head));
                  $WarmDecoder = $WarmPackage->Decoder;
                  if (! $WarmDecoder instanceof Decoder_Downloading) {
                     $probe['error'] =
                        'Probe setup failed: projection warmup decoder was not installed.';
                     return;
                  }

                  $offset = 0;
                  $WarmState = States::Incomplete;
                  while ($offset < strlen($prefix)) {
                     $segment = substr($prefix, $offset, 32 * 1024);
                     $WarmState = $WarmDecoder->decode(
                        $WarmPackage,
                        $segment,
                        strlen($segment)
                     );
                     $offset += strlen($segment);

                     if ($WarmState !== States::Incomplete) {
                        break;
                     }
                  }
                  if ($WarmState === States::Incomplete) {
                     $WarmState = $WarmDecoder->decode(
                        $WarmPackage,
                        $suffix,
                        strlen($suffix)
                     );
                  }
                  if (
                     $WarmState !== States::Complete
                     || $WarmPackage->rejected
                  ) {
                     $probe['error'] = 'Probe setup failed: projection warmup did not complete.';
                  }

                  $WarmRequest->clean();
                  $WarmDecoder->disconnect();
                  $WarmPackage->Decoder = null;
               }
            };

            Bodies::$maxWorkerBodySize = $probe['projection_budget'];

            $projectionTag = 'BootglyH1Projection';
            $projectionPrefix = '';
            for ($field = 0; $field < $probe['projection_fields']; $field++) {
               $index = (string) $field;
               $name = str_repeat(
                  'k',
                  $probe['projection_name_bytes'] - strlen($index)
               ) . $index;
               $projectionPrefix .= "--{$projectionTag}\r\n"
                  . "Content-Disposition: form-data; name=\"{$name}\"\r\n"
                  . "\r\n"
                  . "x\r\n";
            }
            // @ Validate and commit the terminal field before measuring only
            //   finish()'s projection. One epilogue byte remains outstanding
            //   so the decoder stays Incomplete until the measured call.
            $projectionPrefix .= "--{$projectionTag}--\r\n";
            $projectionSuffix = 'E';
            $projectionLength = strlen($projectionPrefix) + strlen($projectionSuffix);
            $warm($projectionTag, $projectionPrefix, $projectionSuffix);

            [$Connection, $Package, $Request] = $open();
            $head = "POST /h1-budget-current-projection HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$projectionTag}\r\n"
               . "Content-Length: {$projectionLength}\r\n"
               . "\r\n";
            $Request->decode($Package, $head, strlen($head));
            $ProjectionDecoder = $Package->Decoder;

            if ($ProjectionDecoder instanceof Decoder_Downloading) {
               $offset = 0;
               $ProjectionState = States::Incomplete;
               while ($offset < strlen($projectionPrefix)) {
                  $segment = substr($projectionPrefix, $offset, 32 * 1024);
                  $ProjectionState = $ProjectionDecoder->decode(
                     $Package,
                     $segment,
                     strlen($segment)
                  );
                  $offset += strlen($segment);

                  if ($ProjectionState !== States::Incomplete) {
                     break;
                  }
               }
               $segment = '';

               $Measure = new ReflectionMethod($ProjectionDecoder, 'measure');
               $Block = new ReflectionMethod($ProjectionDecoder, 'block');
               $Tail = new ReflectionProperty($ProjectionDecoder, 'tailBuffer');
               $Encoded = new ReflectionProperty($ProjectionDecoder, 'fieldsEncoded');
               $KeysBlocks = new ReflectionProperty($ProjectionDecoder, 'keysBlocks');
               $Nodes = new ReflectionProperty($ProjectionDecoder, 'nodes');
               $Class = new ReflectionClass($ProjectionDecoder);

               $preMeasure = (int) $Measure->invoke($ProjectionDecoder);
               $tailBytes = strlen((string) $Tail->getValue($ProjectionDecoder));
               $encodedBytes = strlen(
                  (string) $Encoded->getValue($ProjectionDecoder)
               );
               $keysBlocks = (int) $KeysBlocks->getValue($ProjectionDecoder);
               $nodes = (int) $Nodes->getValue($ProjectionDecoder);
               $nodeOverhead = (int) $Class->getConstant('NODE_OVERHEAD');
               $entryOverhead = (int) $Class->getConstant('ENTRY_OVERHEAD');
               $mapOverhead = (int) $Class->getConstant('MAP_OVERHEAD');
               $finishMeasure = $preMeasure
                  - (int) $Block->invoke(null, $tailBytes)
                  + (int) $Block->invoke(null, 0);
               $currentProjection = $finishMeasure
                  + (int) $Block->invoke(null, $encodedBytes)
                  + $keysBlocks
                  + ($nodes * ($nodeOverhead + $entryOverhead))
                  + $mapOverhead
                  + (2 * (int) $Block->invoke(null, 0));

               $probe['projection_pre_state'] = $ProjectionState->name;
               $probe['projection_pre_reserved'] = $ProjectionDecoder->Bodies->retained;
               $probe['projection_current_bound'] = $currentProjection;
               $probe['projection_allowed_growth'] =
                  $currentProjection - $ProjectionDecoder->Bodies->retained;

               // ! Occupy everything except the exact absolute reservation
               //   finish() is about to request.
               $Blocker = new Bodies;
               $probe['projection_blocker_accepted'] = $Blocker->reserve(
                  $probe['projection_budget'] - $currentProjection
               );

               if (function_exists('memory_reset_peak_usage')) {
                  memory_reset_peak_usage();
               }
               $usageBefore = memory_get_usage(false);

               $ProjectionState = $ProjectionDecoder->decode(
                  $Package,
                  $projectionSuffix,
                  strlen($projectionSuffix)
               );

               $probe['projection_peak_growth'] =
                  memory_get_peak_usage(false) - $usageBefore;
               $probe['projection_final_state'] = $ProjectionState->name;
               $probe['projection_rejected'] = $Package->rejected;
               $probe['projection_result_fields'] = count($Request->fields);
               foreach (array_keys($Request->fields) as $key) {
                  $probe['projection_decoded_key_bytes'] += strlen((string) $key);
               }

               $Request->clean();
               $ProjectionDecoder->disconnect();
               $Package->Decoder = null;
               $Blocker->release();

               $Free = new Bodies;
               $probe['projection_budget_free_after'] = $Free->reserve(
                  $probe['projection_budget']
               );
               $Free->release();
            }

            Bodies::$maxWorkerBodySize = $probe['probe_budget'];
         }

         // --- Leg 6.4: exercise the NEW exact finish() projection rather than
         //     merely proving it rejects the superseded one-copy bound. Small
         //     maps expose any fixed parser/root-container allocation floor
         //     that a per-byte/per-node projection fails to include.

         if ($probe['budget_available']) {
            Bodies::$maxWorkerBodySize = $probe['floor_budget'];

            $floorTag = 'BootglyH1Floor';
            $floorPrefix = '';
            for ($field = 0; $field < $probe['floor_fields']; $field++) {
               $index = (string) $field;
               $name = str_repeat(
                  'q',
                  $probe['floor_name_bytes'] - strlen($index)
               ) . $index;
               $floorPrefix .= "--{$floorTag}\r\n"
                  . "Content-Disposition: form-data; name=\"{$name}\"\r\n"
                  . "\r\n"
                  . "x\r\n";
            }
            // @ Commit the terminal field before isolating finish()'s
            //   projection; an epilogue byte keeps the measured call pending.
            $floorPrefix .= "--{$floorTag}--\r\n";
            $floorSuffix = 'E';
            $floorLength = strlen($floorPrefix) + strlen($floorSuffix);
            $warm($floorTag, $floorPrefix, $floorSuffix);

            [$Connection, $Package, $Request] = $open();
            $head = "POST /h1-budget-projection-floor HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$floorTag}\r\n"
               . "Content-Length: {$floorLength}\r\n"
               . "\r\n";
            $Request->decode($Package, $head, strlen($head));
            $FloorDecoder = $Package->Decoder;

            if ($FloorDecoder instanceof Decoder_Downloading) {
               $FloorState = $FloorDecoder->decode(
                  $Package,
                  $floorPrefix,
                  strlen($floorPrefix)
               );

               $Measure = new ReflectionMethod($FloorDecoder, 'measure');
               $Block = new ReflectionMethod($FloorDecoder, 'block');
               $Tail = new ReflectionProperty($FloorDecoder, 'tailBuffer');
               $Encoded = new ReflectionProperty($FloorDecoder, 'fieldsEncoded');
               $KeysBlocks = new ReflectionProperty($FloorDecoder, 'keysBlocks');
               $Nodes = new ReflectionProperty($FloorDecoder, 'nodes');
               $Class = new ReflectionClass($FloorDecoder);

               $preMeasure = (int) $Measure->invoke($FloorDecoder);
               $tailBytes = strlen((string) $Tail->getValue($FloorDecoder));
               $encodedBytes = strlen(
                  (string) $Encoded->getValue($FloorDecoder)
               );
               $keysBlocks = (int) $KeysBlocks->getValue($FloorDecoder);
               $nodes = (int) $Nodes->getValue($FloorDecoder);
               $nodeOverhead = (int) $Class->getConstant('NODE_OVERHEAD');
               $entryOverhead = (int) $Class->getConstant('ENTRY_OVERHEAD');
               $mapOverhead = (int) $Class->getConstant('MAP_OVERHEAD');
               $finishMeasure = $preMeasure
                  - (int) $Block->invoke(null, $tailBytes)
                  + (int) $Block->invoke(null, 0);
               $currentProjection = $finishMeasure
                  + (int) $Block->invoke(null, $encodedBytes)
                  + $keysBlocks
                  + ($nodes * ($nodeOverhead + $entryOverhead))
                  + $mapOverhead
                  + (2 * (int) $Block->invoke(null, 0));

               $probe['floor_pre_state'] = $FloorState->name;
               $probe['floor_pre_reserved'] = $FloorDecoder->Bodies->retained;
               $probe['floor_current_bound'] = $currentProjection;
               $probe['floor_allowed_growth'] =
                  $currentProjection - $FloorDecoder->Bodies->retained;

               $Blocker = new Bodies;
               $probe['floor_blocker_accepted'] = $Blocker->reserve(
                  $probe['floor_budget'] - $currentProjection
               );

               if (function_exists('memory_reset_peak_usage')) {
                  memory_reset_peak_usage();
               }
               $usageBefore = memory_get_usage(false);

               $FloorState = $FloorDecoder->decode(
                  $Package,
                  $floorSuffix,
                  strlen($floorSuffix)
               );

               $probe['floor_peak_growth'] =
                  memory_get_peak_usage(false) - $usageBefore;
               $probe['floor_final_state'] = $FloorState->name;
               $probe['floor_rejected'] = $Package->rejected;
               $probe['floor_result_fields'] = count($Request->fields);
               foreach (array_keys($Request->fields) as $key) {
                  $probe['floor_decoded_key_bytes'] += strlen((string) $key);
               }

               $Request->clean();
               $FloorDecoder->disconnect();
               $Package->Decoder = null;
               $Blocker->release();

               $Free = new Bodies;
               $probe['floor_budget_free_after'] = $Free->reserve($probe['floor_budget']);
               $Free->release();
            }

            // ! Matched ample-budget control: the exact same payload must parse
            //   and retain every field when no blocker consumes the remainder.
            [$Connection, $ControlPackage, $ControlRequest] = $open();
            $head = "POST /h1-budget-projection-floor-control HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$floorTag}\r\n"
               . "Content-Length: {$floorLength}\r\n"
               . "\r\n";
            $ControlRequest->decode($ControlPackage, $head, strlen($head));
            $ControlDecoder = $ControlPackage->Decoder;

            if ($ControlDecoder instanceof Decoder_Downloading) {
               $ControlState = $ControlDecoder->decode(
                  $ControlPackage,
                  $floorPrefix . $floorSuffix,
                  $floorLength
               );
               $probe['floor_control_state'] = $ControlState->name;
               $probe['floor_control_rejected'] = $ControlPackage->rejected;
               $probe['floor_control_fields'] = count($ControlRequest->fields);

               $ControlRequest->clean();
               $ControlDecoder->disconnect();
               $ControlPackage->Decoder = null;
            }

            Bodies::$maxWorkerBodySize = $probe['probe_budget'];
         }

         // --- Leg 6.5: decoded key strings have their OWN allocator blocks.
         //     A 4,072-byte decoded key crosses Zend's 4 KiB -> 8 KiB class
         //     once its header/NUL are included. Charging only its logical
         //     characters leaves a per-key gap even when retained input strings
         //     and the fixed root-map floor are priced conservatively.

         if ($probe['budget_available']) {
            Bodies::$maxWorkerBodySize = $probe['cliff_budget'];

            $cliffTag = 'BootglyH1Cliff';
            $cliffPrefix = '';
            for ($field = 0; $field < $probe['cliff_fields']; $field++) {
               $index = (string) $field;
               $name = str_repeat(
                  'z',
                  $probe['cliff_name_bytes'] - strlen($index)
               ) . $index;
               $cliffPrefix .= "--{$cliffTag}\r\n"
                  . "Content-Disposition: form-data; name=\"{$name}\"\r\n"
                  . "\r\n"
                  . "x\r\n";
            }
            // @ Commit the terminal field before isolating finish()'s
            //   projection; an epilogue byte keeps the measured call pending.
            $cliffPrefix .= "--{$cliffTag}--\r\n";
            $cliffSuffix = 'E';
            $cliffLength = strlen($cliffPrefix) + strlen($cliffSuffix);
            $warm($cliffTag, $cliffPrefix, $cliffSuffix);

            [$Connection, $Package, $Request] = $open();
            $head = "POST /h1-budget-decoded-key-cliff HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$cliffTag}\r\n"
               . "Content-Length: {$cliffLength}\r\n"
               . "\r\n";
            $Request->decode($Package, $head, strlen($head));
            $CliffDecoder = $Package->Decoder;

            if ($CliffDecoder instanceof Decoder_Downloading) {
               $CliffState = $CliffDecoder->decode(
                  $Package,
                  $cliffPrefix,
                  strlen($cliffPrefix)
               );

               $Measure = new ReflectionMethod($CliffDecoder, 'measure');
               $Block = new ReflectionMethod($CliffDecoder, 'block');
               $Tail = new ReflectionProperty($CliffDecoder, 'tailBuffer');
               $Encoded = new ReflectionProperty($CliffDecoder, 'fieldsEncoded');
               $KeysBlocks = new ReflectionProperty($CliffDecoder, 'keysBlocks');
               $Nodes = new ReflectionProperty($CliffDecoder, 'nodes');
               $Class = new ReflectionClass($CliffDecoder);

               $preMeasure = (int) $Measure->invoke($CliffDecoder);
               $tailBytes = strlen((string) $Tail->getValue($CliffDecoder));
               $encodedBytes = strlen(
                  (string) $Encoded->getValue($CliffDecoder)
               );
               $keysBlocks = (int) $KeysBlocks->getValue($CliffDecoder);
               $nodes = (int) $Nodes->getValue($CliffDecoder);
               $nodeOverhead = (int) $Class->getConstant('NODE_OVERHEAD');
               $entryOverhead = (int) $Class->getConstant('ENTRY_OVERHEAD');
               $mapOverhead = (int) $Class->getConstant('MAP_OVERHEAD');
               $finishMeasure = $preMeasure
                  - (int) $Block->invoke(null, $tailBytes)
                  + (int) $Block->invoke(null, 0);
               $currentProjection = $finishMeasure
                  + (int) $Block->invoke(null, $encodedBytes)
                  + $keysBlocks
                  + ($nodes * ($nodeOverhead + $entryOverhead))
                  + $mapOverhead
                  + (2 * (int) $Block->invoke(null, 0));

               $probe['cliff_pre_state'] = $CliffState->name;
               $probe['cliff_pre_reserved'] = $CliffDecoder->Bodies->retained;
               $probe['cliff_current_bound'] = $currentProjection;
               $probe['cliff_allowed_growth'] =
                  $currentProjection - $CliffDecoder->Bodies->retained;

               $Blocker = new Bodies;
               $probe['cliff_blocker_accepted'] = $Blocker->reserve(
                  $probe['cliff_budget'] - $currentProjection
               );

               if (function_exists('memory_reset_peak_usage')) {
                  memory_reset_peak_usage();
               }
               $usageBefore = memory_get_usage(false);

               $CliffState = $CliffDecoder->decode(
                  $Package,
                  $cliffSuffix,
                  strlen($cliffSuffix)
               );

               $probe['cliff_peak_growth'] =
                  memory_get_peak_usage(false) - $usageBefore;
               $probe['cliff_final_state'] = $CliffState->name;
               $probe['cliff_rejected'] = $Package->rejected;
               $probe['cliff_result_fields'] = count($Request->fields);
               foreach (array_keys($Request->fields) as $key) {
                  $probe['cliff_decoded_key_bytes'] += strlen((string) $key);
               }

               $Request->clean();
               $CliffDecoder->disconnect();
               $Package->Decoder = null;
               $Blocker->release();

               $Free = new Bodies;
               $probe['cliff_budget_free_after'] = $Free->reserve($probe['cliff_budget']);
               $Free->release();
            }

            // ! Matched no-blocker control proves that this exact valid body
            //   parses and returns every field under sufficient free budget.
            [$Connection, $ControlPackage, $ControlRequest] = $open();
            $head = "POST /h1-budget-decoded-key-cliff-control HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$cliffTag}\r\n"
               . "Content-Length: {$cliffLength}\r\n"
               . "\r\n";
            $ControlRequest->decode($ControlPackage, $head, strlen($head));
            $ControlDecoder = $ControlPackage->Decoder;

            if ($ControlDecoder instanceof Decoder_Downloading) {
               $ControlState = $ControlDecoder->decode(
                  $ControlPackage,
                  $cliffPrefix . $cliffSuffix,
                  $cliffLength
               );
               $probe['cliff_control_state'] = $ControlState->name;
               $probe['cliff_control_rejected'] = $ControlPackage->rejected;
               $probe['cliff_control_fields'] = count($ControlRequest->fields);

               $ControlRequest->clean();
               $ControlDecoder->disconnect();
               $ControlPackage->Decoder = null;
            }

            Bodies::$maxWorkerBodySize = $probe['probe_budget'];
         }

         // --- Leg 6.6: bracket components become independent decoded keys.
         //     Pricing one block for the whole raw name cannot bound two
         //     separately allocated 4,072-byte zend_strings.

         if ($probe['budget_available']) {
            Bodies::$maxWorkerBodySize = $probe['segmented_budget'];

            $segmentedTag = 'BootglyH1Segments';
            $segmentedPrefix = '';
            for ($field = 0; $field < $probe['segmented_fields']; $field++) {
               $index = (string) $field;
               $root = str_repeat(
                  'r',
                  $probe['segmented_segment_bytes'] - strlen($index)
               ) . $index;
               $child = str_repeat(
                  's',
                  $probe['segmented_segment_bytes'] - strlen($index)
               ) . $index;
               $name = $root . '[' . $child . ']';
               $disposition = "Content-Disposition: form-data; name=\"{$name}\"";
               $probe['segmented_header_bytes'] = max(
                  $probe['segmented_header_bytes'],
                  strlen($disposition)
               );
               $segmentedPrefix .= "--{$segmentedTag}\r\n"
                  . $disposition . "\r\n"
                  . "\r\n"
                  . "x\r\n";
            }
            // @ Commit the terminal field before isolating finish()'s
            //   projection; an epilogue byte keeps the measured call pending.
            $segmentedPrefix .= "--{$segmentedTag}--\r\n";
            $segmentedSuffix = 'E';
            $segmentedLength = strlen($segmentedPrefix) + strlen($segmentedSuffix);
            $warm($segmentedTag, $segmentedPrefix, $segmentedSuffix);

            [$Connection, $Package, $Request] = $open();
            $head = "POST /h1-budget-segmented-keys HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$segmentedTag}\r\n"
               . "Content-Length: {$segmentedLength}\r\n"
               . "\r\n";
            $Request->decode($Package, $head, strlen($head));
            $SegmentedDecoder = $Package->Decoder;

            if ($SegmentedDecoder instanceof Decoder_Downloading) {
               $offset = 0;
               $SegmentedState = States::Incomplete;
               while ($offset < strlen($segmentedPrefix)) {
                  $segment = substr($segmentedPrefix, $offset, 32 * 1024);
                  $SegmentedState = $SegmentedDecoder->decode(
                     $Package,
                     $segment,
                     strlen($segment)
                  );
                  $offset += strlen($segment);

                  if ($SegmentedState !== States::Incomplete) {
                     break;
                  }
               }

               $Measure = new ReflectionMethod($SegmentedDecoder, 'measure');
               $Block = new ReflectionMethod($SegmentedDecoder, 'block');
               $Tail = new ReflectionProperty($SegmentedDecoder, 'tailBuffer');
               $Encoded = new ReflectionProperty($SegmentedDecoder, 'fieldsEncoded');
               $KeysBlocks = new ReflectionProperty($SegmentedDecoder, 'keysBlocks');
               $Nodes = new ReflectionProperty($SegmentedDecoder, 'nodes');
               $Class = new ReflectionClass($SegmentedDecoder);

               $preMeasure = (int) $Measure->invoke($SegmentedDecoder);
               $tailBytes = strlen((string) $Tail->getValue($SegmentedDecoder));
               $encodedBytes = strlen(
                  (string) $Encoded->getValue($SegmentedDecoder)
               );
               $keysBlocks = (int) $KeysBlocks->getValue($SegmentedDecoder);
               $nodes = (int) $Nodes->getValue($SegmentedDecoder);
               $nodeOverhead = (int) $Class->getConstant('NODE_OVERHEAD');
               $entryOverhead = (int) $Class->getConstant('ENTRY_OVERHEAD');
               $mapOverhead = (int) $Class->getConstant('MAP_OVERHEAD');
               $finishMeasure = $preMeasure
                  - (int) $Block->invoke(null, $tailBytes)
                  + (int) $Block->invoke(null, 0);
               $currentProjection = $finishMeasure
                  + (int) $Block->invoke(null, $encodedBytes)
                  + $keysBlocks
                  + ($nodes * ($nodeOverhead + $entryOverhead))
                  + $mapOverhead
                  + (2 * (int) $Block->invoke(null, 0));

               $probe['segmented_pre_state'] = $SegmentedState->name;
               $probe['segmented_pre_reserved'] = $SegmentedDecoder->Bodies->retained;
               $probe['segmented_current_bound'] = $currentProjection;
               $probe['segmented_allowed_growth'] =
                  $currentProjection - $SegmentedDecoder->Bodies->retained;

               $Blocker = new Bodies;
               $probe['segmented_blocker_accepted'] = $Blocker->reserve(
                  $probe['segmented_budget'] - $currentProjection
               );

               if (function_exists('memory_reset_peak_usage')) {
                  memory_reset_peak_usage();
               }
               $usageBefore = memory_get_usage(false);

               $SegmentedState = $SegmentedDecoder->decode(
                  $Package,
                  $segmentedSuffix,
                  strlen($segmentedSuffix)
               );

               $probe['segmented_peak_growth'] =
                  memory_get_peak_usage(false) - $usageBefore;
               $probe['segmented_final_state'] = $SegmentedState->name;
               $probe['segmented_rejected'] = $Package->rejected;
               $probe['segmented_result_fields'] = count($Request->fields);

               $Request->clean();
               $SegmentedDecoder->disconnect();
               $Package->Decoder = null;
               $Blocker->release();

               $Free = new Bodies;
               $probe['segmented_budget_free_after'] = $Free->reserve(
                  $probe['segmented_budget']
               );
               $Free->release();
            }

            // ! Matched no-blocker control proves that every structured field
            //   is valid and materializes under sufficient available budget.
            [$Connection, $ControlPackage, $ControlRequest] = $open();
            $head = "POST /h1-budget-segmented-keys-control HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$segmentedTag}\r\n"
               . "Content-Length: {$segmentedLength}\r\n"
               . "\r\n";
            $ControlRequest->decode($ControlPackage, $head, strlen($head));
            $ControlDecoder = $ControlPackage->Decoder;

            if ($ControlDecoder instanceof Decoder_Downloading) {
               $offset = 0;
               $ControlState = States::Incomplete;
               $controlBody = $segmentedPrefix . $segmentedSuffix;
               while ($offset < strlen($controlBody)) {
                  $segment = substr($controlBody, $offset, 32 * 1024);
                  $ControlState = $ControlDecoder->decode(
                     $ControlPackage,
                     $segment,
                     strlen($segment)
                  );
                  $offset += strlen($segment);

                  if ($ControlState !== States::Incomplete) {
                     break;
                  }
               }

               $probe['segmented_control_state'] = $ControlState->name;
               $probe['segmented_control_rejected'] = $ControlPackage->rejected;
               $probe['segmented_control_fields'] = count($ControlRequest->fields);

               $ControlRequest->clean();
               $ControlDecoder->disconnect();
               $ControlPackage->Decoder = null;
            }

            Bodies::$maxWorkerBodySize = $probe['probe_budget'];
         }

         // --- Leg 6.7: completed file parts retain two levels of PHP arrays
         //     before finish(). Charging their strings alone leaves the outer
         //     buckets, zvals and five-element metadata arrays outside the
         //     worker ledger across event-loop turns.

         if ($probe['budget_available']) {
            Bodies::$maxWorkerBodySize = $probe['container_budget'];

            $containerTag = 'BootglyH1Containers';
            $containerPrefix = '';
            for ($file = 0; $file < $probe['container_files']; $file++) {
               $containerPrefix .= ($file === 0
                     ? "--{$containerTag}\r\n"
                     : "\r\n--{$containerTag}\r\n")
                  . "Content-Disposition: form-data; name=\"f{$file}\"; filename=\"x\"\r\n"
                  . "\r\n";
            }
            // ! Close the final file and leave a text part open, so every file
            //   handler is closed while all metadata arrays remain retained.
            $containerPrefix .= "\r\n--{$containerTag}\r\n"
               . "Content-Disposition: form-data; name=\"hold\"\r\n"
               . "\r\n";

            [$Connection, $Package, $Request] = $open();
            $containerLength = strlen($containerPrefix) + 1024;
            $head = "POST /h1-budget-containers HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$containerTag}\r\n"
               . "Content-Length: {$containerLength}\r\n"
               . "\r\n";
            $Request->decode($Package, $head, strlen($head));
            $ContainerDecoder = $Package->Decoder;

            if ($ContainerDecoder instanceof Decoder_Downloading) {
               $memoryBefore = memory_get_usage(false);
               $offset = 0;
               $ContainerState = States::Incomplete;
               while ($offset < strlen($containerPrefix)) {
                  $segment = substr($containerPrefix, $offset, 32 * 1024);
                  $ContainerState = $ContainerDecoder->decode(
                     $Package,
                     $segment,
                     strlen($segment)
                  );
                  $offset += strlen($segment);

                  if ($ContainerState !== States::Incomplete) {
                     break;
                  }
               }
               $segment = '';

               $Files = new ReflectionProperty($ContainerDecoder, 'files');
               $retainedFiles = (array) $Files->getValue($ContainerDecoder);
               $probe['container_pre_state'] = $ContainerState->name;
               $probe['container_rejected'] = $Package->rejected;
               $probe['container_files_retained'] = count($retainedFiles);
               $probe['container_reserved'] = $ContainerDecoder->Bodies->retained;
               $probe['container_heap_delta'] = memory_get_usage(false) - $memoryBefore;
               unset($retainedFiles);

               // ! On a secure rejection, abort() must release before manual
               //   disconnect can hide the leak.
               $BeforeTeardown = new Bodies;
               $probe['container_budget_free_before_teardown'] =
                  $BeforeTeardown->reserve($probe['container_budget']);
               $BeforeTeardown->release();

               $ContainerDecoder->disconnect();
               $Package->Decoder = null;
               $Request->clean();

               $AfterTeardown = new Bodies;
               $probe['container_budget_free_after_teardown'] =
                  $AfterTeardown->reserve($probe['container_budget']);
               $AfterTeardown->release();
            }

            Bodies::$maxWorkerBodySize = $probe['probe_budget'];
         }

         // --- Leg 7: teardown releases the exact reservation, deterministically.

         $Retained = [];
         $probe['waiting_disconnecting'] = false;
         $probe['chunked_disconnecting'] = false;

         [$Connection, $Package, $Request] = $open();
         $head = "POST /h1-budget-release HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: {$probe['declared']}\r\n"
            . "\r\n";
         $Request->decode($Package, $head, strlen($head));
         $Decoder = $Package->Decoder;
         $probe['waiting_disconnecting'] = $Decoder instanceof Disconnecting;

         if ($Decoder !== null) {
            $Decoder->decode($Package, $slice, $probe['slice']);

            $Weak = WeakReference::create($Request);
            if ($Decoder instanceof Disconnecting) {
               $Decoder->disconnect();
            }
            $Package->Decoder = null;
            Server::$Request = new Request;
            unset($Decoder, $Request);

            // ! No `gc_collect_cycles()`: a body released only by the cycle
            //   collector is still a burst an attacker fully controls.
            $probe['request_alive_after_disconnect'] = $Weak->get() !== null;
         }

         [$Connection, $Package, $Request] = $open();
         $head = "POST /h1-budget-release-chunked HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n";
         $Request->decode($Package, $head, strlen($head));
         $probe['chunked_disconnecting'] = $Package->Decoder instanceof Disconnecting;
         if ($Package->Decoder instanceof Disconnecting) {
            $Package->Decoder->decode($Package, $chunk, strlen($chunk));
            $Package->Decoder->disconnect();
         }
         $Package->Decoder = null;

         if ($probe['budget_available']) {
            // ! The worker ledger is deliberately private, so prove its state
            //   by what it admits: after teardown the whole budget is free.
            $Empty = new Bodies;
            $probe['ledger_empty_after_disconnect'] = $Empty->reserve($probe['probe_budget']);
            $Empty->release();

            // --- Leg 8: one peer must never release another peer's bytes.

            $A = new Bodies;
            $B = new Bodies;
            $reservedA = $A->reserve(64 * 1024);
            $reservedB = $B->reserve(64 * 1024);
            // ! Twice: a double release must not credit the ledger twice.
            $A->release();
            $A->release();
            $probe['isolated'] = $reservedA && $reservedB && $B->retained === 64 * 1024;

            // ! With only B's reservation outstanding, exactly the remainder
            //   fits and one byte more does not.
            $C = new Bodies;
            $probe['ledger_exact'] = $C->reserve($probe['probe_budget'] - 64 * 1024)
               && $C->reserve($probe['probe_budget']) === false;
            $C->release();
            $B->release();

            $Free = new Bodies;
            $probe['control_reservation_released'] = $Free->reserve($probe['probe_budget']);
            $Free->release();
         }

         // --- Leg 9: the empty connection shell may wait for the collector, but
         //     it must actually be reclaimed by it — nothing may pin it forever.

         $socket = tmpfile();
         if (! is_resource($socket)) {
            throw new RuntimeException('Could not allocate the lifetime probe socket.');
         }
         $Sockets[] = $socket;

         // ! Drain whatever cyclic garbage earlier suites left in this worker,
         //   so the collector count below is attributable to THIS connection
         //   and not to the order the matrix happened to run in.
         gc_collect_cycles();

         // ! The REAL constructor — the self-reference is created there, not
         //   at close. `guard()` parks `[$this, 'expire']` in the static Timer
         //   map, so drop that root first: it is an unrelated, already-fixed
         //   retention path and would mask this one.
         $Lifetime = new Connection($socket, '127.0.0.1', 12345);
         foreach ($Lifetime->timers as $timer) {
            Timer::del($timer);
         }
         $Lifetime->timers = [];

         $Weak = WeakReference::create($Lifetime);
         unset($Lifetime);
         // ! Surviving refcounting is expected — the self-cycle is deliberate.
         //   What is NOT acceptable is surviving the collector too: that would
         //   mean a live root (a timer, a registry entry) still pins the shell,
         //   and the retention would be unbounded rather than merely deferred.
         $probe['connection_alive_without_gc'] = $Weak->get() !== null;
         $probe['connection_gc_collected'] = gc_collect_cycles();
         $probe['connection_alive_after_gc'] = $Weak->get() !== null;

         // --- Leg 10: drive the teardown through the REAL transport close with
         //     a body attached, instead of calling disconnect() by hand. This
         //     is what proves Connection::close() actually reaches the decoder.

         $socket = tmpfile();
         if (! is_resource($socket)) {
            throw new RuntimeException('Could not allocate the transport-close socket.');
         }
         $Sockets[] = $socket;

         // ! A Connection IS its own Package, so it decodes into itself —
         //   exactly the production shape.
         $Transport = new Connection($socket, '127.0.0.1', 12345);
         $TransportRequest = new Request;
         Server::$Request = $TransportRequest;

         $head = "POST /h1-budget-transport-close HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: {$probe['declared']}\r\n"
            . "\r\n";
         $TransportRequest->decode($Transport, $head, strlen($head));
         if ($Transport->Decoder === null) {
            $probe['error'] = 'Probe setup failed: the transport peer installed no body decoder.';
            return $harness;
         }
         $Transport->Decoder->decode($Transport, $slice, $probe['slice']);

         $BodyWeak = WeakReference::create($TransportRequest);
         Server::$Request = new Request;
         unset($TransportRequest);

         // @ The production close path — nothing about the decoder is touched
         //   by hand here.
         $probe['closed_via_transport'] = $Transport->close();
         $probe['body_alive_after_close'] = $BodyWeak->get() !== null;

         if ($probe['budget_available']) {
            $Closed = new Bodies;
            $probe['budget_free_after_close'] = $Closed->reserve($probe['probe_budget']);
            $Closed->release();
         }
         unset($Transport);

         // --- Leg 11: a COMPLETED body must not survive its own response
         //     cycle. The ledger bounds only UNFINISHED bodies, so anything
         //     still resident once the cycle ends sits outside every ceiling
         //     — and on an idle keep-alive connection it stays there until the
         //     next request or the close.

         $OldEmitter = Emitter::$Instance;
         $handlerSaw = 0;
         $listenerSaw = 0;

         try {
            // ! Own budget: the legs above leave the ceiling wherever their
            //   own scenario needed it, and 11.5 must be able to reserve its
            //   captured body without inheriting someone else's limit.
            Bodies::$maxWorkerBodySize = 1024 * 1024;

            $probe['retained_payload_bytes'] = 60000;
            $payload = str_repeat('A', $probe['retained_payload_bytes']);
            $retainedBody = "k={$payload}";
            $retainedLength = strlen($retainedBody);

            $Events = new Emitter;
            Emitter::$Instance = $Events;
            // ! Control: a Handled listener is the LAST reader of the live
            //   Request, so it must still see the body. A scrub placed before
            //   it would pass the leg while breaking every listener.
            $Events->listen(
               RequestEvents::Handled,
               static function (Emission $Emission) use (&$listenerSaw): void {
                  /** @var Request $Request */
                  [$Request] = $Emission->payload;

                  $listenerSaw += strlen((string) ($Request->fields['k'] ?? ''));
               }
            );

            SAPI::$Handler = static function (
               Request $Request,
               Response $Response,
               Router $Router
            ) use (&$handlerSaw): Response {
               $handlerSaw += strlen((string) ($Request->fields['k'] ?? ''));

               return $Response(code: 200, body: 'RETAINED-OK');
            };

            $retainedHead = static fn (string $URI): string =>
               "POST {$URI} HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: application/x-www-form-urlencoded\r\n"
               . "Content-Length: {$GLOBALS['h1RetainedLength']}\r\n"
               . "\r\n";
            $GLOBALS['h1RetainedLength'] = $retainedLength;

            // # 11.1 — fragmented: the body completes through Decoder_Waiting
            [$Connection, $Package, $Request] = $open();
            $head = $retainedHead('/h1-retained-fragmented');
            $Request->decode($Package, $head, strlen($head));

            if ($Package->Decoder !== null) {
               $probe['retained_frag_state'] = $Package->Decoder
                  ->decode($Package, $retainedBody, $retainedLength)->name;
            }

            $length = null;
            Encoder_::encode($Package, $length);

            $probe['retained_frag_bytes'] = strlen($Request->Body->raw);
            $Fields = new ReflectionProperty($Request, '_fields');
            $probe['retained_frag_fields'] = count((array) $Fields->getValue($Request));

            // # 11.2 — the whole body arrives in the FIRST read, so no
            //   Decoder_Waiting is ever installed and nothing is reserved.
            [$Connection, $Package, $Request] = $open();
            $whole = $retainedHead('/h1-retained-initial') . $retainedBody;
            $Request->decode($Package, $whole, strlen($whole));

            $probe['retained_initial_decoder'] = $Package->Decoder !== null;

            $length = null;
            Encoder_::encode($Package, $length);

            $probe['retained_initial_bytes'] = strlen($Request->Body->raw);
            $probe['retained_initial_fields'] = count((array) $Fields->getValue($Request));

            // # 11.3 — the aggregate an attacker parks across idle peers
            $Idle = [];
            $aggregate = 0;
            for ($peer = 0; $peer < $probe['retained_peers']; $peer++) {
               [$Connection, $Package, $Request] = $open();
               $whole = $retainedHead("/h1-retained-idle/{$peer}") . $retainedBody;
               $Request->decode($Package, $whole, strlen($whole));

               $length = null;
               Encoder_::encode($Package, $length);

               // ! Hold every peer: an idle keep-alive connection is exactly
               //   what keeps the decoded Request — and its body — reachable.
               $Idle[] = [$Connection, $Package, $Request];
            }
            foreach ($Idle as [$IdleConnection, $IdlePackage, $IdleRequest]) {
               $aggregate += strlen($IdleRequest->Body->raw);
            }
            $probe['retained_aggregate_bytes'] = $aggregate;
            $Idle = [];

            $probe['retained_handler_saw'] = $handlerSaw;
            $probe['retained_listener_saw'] = $listenerSaw;

            // # 11.5 — deferred: the encoder returns early, the Fiber works on
            //   the deep copy `Request::capture()` took, and that copy is the
            //   ONE retainer a scrub cannot cover. The live Request must still
            //   be emptied, and the copy must draw on the worker ledger for as
            //   long as it holds the body.
            // # 11.5 — deferred: `Request::capture()` hands the Fiber its own
            //   deep copy of the body — the ONE retainer the encoder's scrub
            //   cannot cover — so that copy must draw on the worker ledger for
            //   as long as it lives.
            // ! The snapshot is taken directly instead of through
            //   `Response::defer()`: this probe drives `Encoder_::encode()`
            //   inline, and PHP refuses to start a Fiber from this execution
            //   context ("Cannot switch fibers"). `capture()` is the exact
            //   production call `Response::__clone()` makes for a deferral,
            //   and it is what owns the reservation.
            $Captured = null;
            SAPI::$Handler = static function (
               Request $Request,
               Response $Response,
               Router $Router
            ) use (&$Captured): Response {
               $Captured = $Request->capture();
               $Response->deferred = true;

               return $Response;
            };

            [$Connection, $Package, $Request] = $open();
            $whole = $retainedHead('/h1-retained-deferred') . $retainedBody;
            $Request->decode($Package, $whole, strlen($whole));

            $length = null;
            Encoder_::encode($Package, $length);

            $probe['retained_deferred_clone_bytes'] = ($Captured !== null)
               ? strlen($Captured->Body->raw)
               : -1;

            // ! With the snapshot still holding the body, ask the ledger for
            //   the WHOLE worker budget. It is granted only when nothing at
            //   all is reserved — which is the finding: a parked copy that is
            //   invisible to the ceiling bounding every other in-memory body.
            $Ledger = new Bodies;
            $probe['retained_deferred_ledger_free'] = $Ledger->reserve(
               Bodies::$maxWorkerBodySize
            );
            $Ledger->release();

            // ! And the snapshot must give the bytes back when it is cleaned,
            //   exactly as the Fiber's `finally` does.
            // ! Hold the reservation object across the call, so the release
            //   has to be the EXPLICIT one. Letting it fall out of scope would
            //   let `Bodies::__destruct()` drain the ledger on its own and the
            //   assertion below would pass with no release in `clean()` at all
            //   — and a destructor is GC-bound, so a reference cycle could
            //   defer it past the requests this budget is meant to bound.
            $Reservation = new ReflectionProperty($Captured, 'Bodies');
            $Held = $Reservation->getValue($Captured);
            $Captured->clean();
            $Drained = new Bodies;
            $probe['retained_deferred_ledger_drained'] = $Drained->reserve(
               Bodies::$maxWorkerBodySize
            );
            $Drained->release();
            $Held = null;
            $Captured = null;

            $probe['retained_deferred_live_bytes'] = strlen($Request->Body->raw);
         }
         finally {
            Emitter::$Instance = $OldEmitter;
            unset($GLOBALS['h1RetainedLength']);
         }
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage()
            . ' at ' . $Throwable->getFile() . ':' . $Throwable->getLine();
      }
      finally {
         $Retained = [];

         Server::$Request = $OldRequest;
         Server::$Response = $OldResponse;
         Server::$Router = $OldRouter;
         Server::$Decoder = $OldDecoder;

         if ($OldHandler !== null) {
            SAPI::$Handler = $OldHandler;
         }
         if ($OldMiddlewares !== null) {
            SAPI::$Middlewares = $OldMiddlewares;
         }
         if ($probe['budget_available']) {
            Bodies::$maxWorkerBodySize = $OldWorkerBodySize;
         }

         foreach ($Sockets as $socket) {
            if (is_resource($socket)) {
               @fclose($socket);
            }
         }

         // ! Validation must never strand its own upload artifacts. Record the
         //   production leak first, then remove only the exact before/after
         //   path difference created during this case.
         $downloadAfter = glob($downloadDirectory . '*');
         if (! is_array($downloadAfter)) {
            $downloadAfter = [];
         }
         sort($downloadAfter);
         $artifactPaths = array_values(
            array_diff($downloadAfter, $downloadBaseline)
         );
         $probe['artifact_residual_files'] = count($artifactPaths);

         $deleted = true;
         foreach ($artifactPaths as $path) {
            if (is_file($path) && @unlink($path) !== true) {
               $deleted = false;
            }
         }

         $downloadAfter = glob($downloadDirectory . '*');
         if (! is_array($downloadAfter)) {
            $downloadAfter = [];
         }
         $probe['artifact_cleanup_exact'] = $deleted
            && array_values(
               array_diff($downloadAfter, $downloadBaseline)
            ) === [];
      }

      return $harness;
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/h1-budget-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'H1-BUDGET-HARNESS-OK');
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['H1 probe state'];
         dump(json_encode($probe));
         return $probe['error'];
      }
      if ($probe['artifact_cleanup_exact'] !== true) {
         Vars::$labels = ['H1 validation artifact cleanup'];
         dump(json_encode($probe));
         return 'The H1 native case could not reclaim only the upload artifacts it created; '
            . 'evidence=' . json_encode($probe);
      }
      if (! str_contains($response, 'H1-BUDGET-HARNESS-OK')) {
         Vars::$labels = ['H1 harness response'];
         dump(json_encode($response));
         return 'The H1 budget harness did not receive its control response.';
      }

      if ($probe['budget_available'] === false) {
         Vars::$labels = ['H1 aggregate-budget evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: HTTP/1 in-memory request bodies have no worker-wide '
            . 'accountant at all, so N connections retain N x the per-request cap ('
            . $probe['per_request_cap'] . ' bytes each); evidence=' . json_encode($probe);
      }
      if (
         $probe['jit_field_warmed'] !== $probe['jit_warmups']
         || $probe['jit_file_warmed'] !== $probe['jit_warmups']
         || $probe['jit_warm_files_exact'] !== true
         || $probe['jit_warm_downloads_exact'] !== true
         || $probe['jit_warm_budget_free_after'] !== true
      ) {
         Vars::$labels = ['H1 JIT warmup isolation'];
         dump(json_encode($probe));
         return 'The H1 probe did not heat both multipart finish branches without leaking '
            . 'temporary files, disk accounting, or worker-budget reservations; evidence='
            . json_encode($probe);
      }

      // ? The aggregate a burst of unfinished Content-Length bodies retained.
      if ($probe['retained_body_bytes'] > $probe['probe_budget']) {
         Vars::$labels = ['H1 aggregate-budget evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: unfinished HTTP/1 Content-Length bodies retained '
            . $probe['retained_body_bytes'] . ' bytes across ' . $probe['connections']
            . ' connections, over the ' . $probe['probe_budget'] . '-byte worker budget; '
            . 'evidence=' . json_encode($probe);
      }
      if ($probe['accepted'] !== 2 || $probe['rejected'] !== 4) {
         Vars::$labels = ['H1 aggregate-budget evidence'];
         dump(json_encode($probe));
         return 'The worker budget did not admit exactly the two peers that fit and reject '
            . 'the remaining four; evidence=' . json_encode($probe);
      }
      // ? Chunked peers must draw on the SAME ledger. Assert it in bytes, not
      //   in connections: exactly the peers whose footprint fits are admitted,
      //   and the first one that does not fit is refused.
      if (
         $probe['chunked_rejected'] === 0
         || $probe['chunked_accepted'] * $probe['chunk_bytes'] > $probe['probe_budget']
         || ($probe['chunked_accepted'] + 1) * $probe['chunk_bytes'] <= $probe['probe_budget']
      ) {
         Vars::$labels = ['H1 chunked-budget evidence'];
         dump(json_encode($probe));
         return 'Chunked bodies did not draw on the same worker budget as Content-Length '
            . 'bodies; evidence=' . json_encode($probe);
      }

      // ? `Request::decode()` can receive part of the body in the same transport
      //   read as the head. `feed()` must charge whatever it retains before the
      //   next decoder call; otherwise every connection gets one free read.
      if (
         $probe['initial_multipart_accepted'] === $probe['connections']
         && $probe['initial_multipart_rejected'] === 0
         && $probe['initial_multipart_tail_retained'] > $probe['probe_budget']
         && $probe['initial_multipart_reserved'] === 0
         && $probe['initial_multipart_budget_free_after'] === true
      ) {
         Vars::$labels = ['H1 initial multipart budget evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: multipart bytes received with the request head retained '
            . $probe['initial_multipart_tail_retained'] . ' bytes across '
            . $probe['connections'] . ' connections while reserving zero worker-budget bytes; '
            . 'evidence=' . json_encode($probe);
      }
      if (
         $probe['initial_multipart_accepted'] !== 4
         || $probe['initial_multipart_rejected'] !== 2
         || $probe['initial_multipart_tail_retained'] > $probe['probe_budget']
         || $probe['initial_multipart_reserved'] < $probe['initial_multipart_tail_retained']
         || $probe['initial_multipart_budget_free_after'] !== false
      ) {
         Vars::$labels = ['H1 initial multipart secure-accounting evidence'];
         dump(json_encode($probe));
         return 'Initial multipart retention was not charged exactly enough to admit the four '
            . 'peers that fit and reject the remaining two; evidence=' . json_encode($probe);
      }

      // ? Multipart TEXT parts are held in memory exactly like a
      //   Content-Length body, so they must draw on the same ledger. File
      //   parts stream to disk and are accounted for separately.
      if (
         $probe['multipart_rejected'] === 0
         || $probe['multipart_retained'] > $probe['probe_budget']
         || $probe['multipart_budget_free_after'] !== false
      ) {
         Vars::$labels = ['H1 multipart-budget evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: unfinished multipart text parts retained '
            . $probe['multipart_retained'] . ' bytes across ' . $probe['connections']
            . ' connections without drawing on the worker budget, so the ceiling still '
            . 'looked free; evidence=' . json_encode($probe);
      }
      if (
         $probe['multipart_accepted'] * $probe['multipart_field_bytes'] > $probe['probe_budget']
         || ($probe['multipart_accepted'] + 1) * $probe['multipart_field_bytes']
            <= $probe['probe_budget']
      ) {
         Vars::$labels = ['H1 multipart-budget evidence'];
         dump(json_encode($probe));
         return 'The worker budget did not admit exactly the multipart peers that fit; '
            . 'evidence=' . json_encode($probe);
      }

      // ? Multipart part headers are body bytes too. Completed field names stay
      //   in `fieldsEncoded` while the request remains incomplete and therefore
      //   must not sit outside the worker-wide retained-body ceiling.
      if (
         $probe['metadata_accepted'] === $probe['connections']
         && $probe['metadata_rejected'] === 0
         && $probe['metadata_encoded_retained'] > $probe['probe_budget']
         && $probe['metadata_budget_remainder_available'] === true
         && $probe['metadata_one_byte_more_refused'] === true
      ) {
         Vars::$labels = ['H1 multipart-metadata budget evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: incomplete multipart requests retained '
            . $probe['metadata_encoded_retained'] . ' bytes of encoded field-name metadata '
            . 'while the worker ledger charged only ' . $probe['metadata_raw_charged']
            . ' bytes and still admitted its exact remainder; evidence=' . json_encode($probe);
      }
      if (
         $probe['metadata_accepted'] !== 1
         || $probe['metadata_rejected'] !== 5
         || $probe['metadata_encoded_retained'] > $probe['metadata_budget']
         || $probe['metadata_raw_charged'] < $probe['metadata_encoded_retained']
         || $probe['metadata_budget_remainder_available'] !== true
         || $probe['metadata_one_byte_more_refused'] !== true
      ) {
         Vars::$labels = ['H1 multipart-metadata secure-accounting evidence'];
         dump(json_encode($probe));
         return 'Multipart metadata was not charged exactly enough to admit the one peer that '
            . 'fits, reject the remaining five, and expose only the exact ledger remainder; '
            . 'evidence=' . json_encode($probe);
      }

      // ? The name of the currently open text part survives between transport
      //   reads. Its independent zend_string allocation must be block-priced,
      //   just like completed values and decoded output keys.
      if (
         $probe['active_name_accepted'] !== $probe['active_name_peers']
         || $probe['active_name_rejected'] !== 0
         || $probe['active_name_retained_bytes']
            !== $probe['active_name_peers'] * $probe['active_name_bytes']
         || $probe['active_name_reserved'] <= 0
         || $probe['active_name_reserved'] > $probe['active_name_budget']
         || $probe['active_name_remainder_available'] !== true
         || $probe['active_name_one_byte_more_refused'] !== true
      ) {
         Vars::$labels = ['H1 active field-name setup'];
         dump(json_encode($probe));
         return 'The active multipart field-name probe did not retain every valid long name '
            . 'at the exact worker-ledger remainder; evidence=' . json_encode($probe);
      }
      if (
         $probe['active_name_heap_delta'] > $probe['active_name_reserved']
         && $probe['segmented_pre_state'] === States::Incomplete->name
         && $probe['segmented_blocker_accepted'] === true
         && $probe['segmented_final_state'] === States::Complete->name
         && $probe['segmented_rejected'] === false
         && $probe['segmented_result_fields'] === $probe['segmented_fields']
         && $probe['segmented_peak_growth'] > $probe['segmented_allowed_growth']
      ) {
         Vars::$labels = ['H1 allocator-block residuals'];
         dump(json_encode($probe));
         return 'H1 still reproduced: active field names retained '
            . $probe['active_name_heap_delta'] . ' heap bytes against '
            . $probe['active_name_reserved'] . ' reserved, and bracketed decoded keys grew '
            . $probe['segmented_peak_growth'] . ' bytes against '
            . $probe['segmented_allowed_growth']
            . ' admitted at the exact completion bound; evidence=' . json_encode($probe);
      }
      if ($probe['active_name_heap_delta'] > $probe['active_name_reserved']) {
         Vars::$labels = ['H1 active field-name allocator evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: active multipart field names grew the retained heap by '
            . $probe['active_name_heap_delta'] . ' bytes while the worker ledger reserved only '
            . $probe['active_name_reserved'] . ' bytes; evidence=' . json_encode($probe);
      }
      if (
         $probe['active_name_budget_free_after'] !== true
         || $probe['active_name_control_state'] !== States::Incomplete->name
         || $probe['active_name_control_rejected'] !== false
         || $probe['active_name_control_bytes'] !== $probe['active_name_bytes']
      ) {
         Vars::$labels = ['H1 active field-name cleanup/control'];
         dump(json_encode($probe));
         return 'The active field-name leg did not release its ledger or its identical '
            . 'ample-budget control failed; evidence=' . json_encode($probe);
      }

      // ? Extending an already retained text value can allocate the complete
      //   destination while the old string remains live. Leave exactly that
      //   current bound free, then prove one byte less is refused.
      if (
         $probe['field_buffer_pre_state'] !== States::Incomplete->name
         || $probe['field_buffer_pre_bytes'] !== $probe['field_buffer_held_bytes']
         || $probe['field_buffer_pre_reserved'] <= 0
         || $probe['field_buffer_bound'] <= $probe['field_buffer_pre_reserved']
         || $probe['field_buffer_bound'] > $probe['field_buffer_budget']
         || $probe['field_buffer_allowed_growth']
            !== $probe['field_buffer_bound']
               - $probe['field_buffer_pre_reserved']
         || $probe['field_buffer_blocker_accepted'] !== true
      ) {
         Vars::$labels = ['H1 active field-buffer append setup'];
         dump(json_encode($probe));
         return 'The active field-buffer probe did not reach the exact current append '
            . 'admission boundary; evidence=' . json_encode($probe);
      }
      if (
         $probe['field_buffer_final_state'] !== States::Incomplete->name
         || $probe['field_buffer_rejected'] !== false
         || $probe['field_buffer_final_bytes']
            !== $probe['field_buffer_held_bytes']
               + $probe['field_buffer_chunk_bytes']
         || $probe['field_buffer_budget_free_after'] !== true
      ) {
         Vars::$labels = ['H1 active field-buffer append evidence'];
         dump(json_encode($probe));
         return 'The exact active field-buffer bound did not retain the complete destination '
            . 'and release its worker reservation; evidence=' . json_encode($probe);
      }
      if (
         $probe['field_buffer_short_blocker_accepted'] !== true
         || $probe['field_buffer_short_final_state'] !== States::Rejected->name
         || $probe['field_buffer_short_rejected'] !== true
         || $probe['field_buffer_short_retained'] !== 0
         || $probe['field_buffer_short_budget_free_after'] !== true
      ) {
         Vars::$labels = ['H1 active field-buffer one-byte-short evidence'];
         dump(json_encode($probe));
         return 'The active field-buffer append was not rejected and reclaimed when its exact '
            . 'current bound was one byte short; evidence=' . json_encode($probe);
      }

      // ? Completing an active text field creates a temporary urlencode()
      //   result while the previously retained aggregate is still live, then
      //   grows that aggregate. Both allocations must fit in the admitted
      //   completion bound, rather than only the logical encoded entry size.
      if (
         $probe['append_pre_state'] !== States::Incomplete->name
         || $probe['append_encoded_bytes'] <= 0
         || $probe['append_current_name_bytes'] !== $probe['append_name_bytes']
         || $probe['append_pre_reserved'] <= 0
         || $probe['append_admitted_growth'] <= 0
         || $probe['append_blocker_accepted'] !== true
      ) {
         Vars::$labels = ['H1 retained field-append setup evidence'];
         dump(json_encode($probe));
         return 'The retained field-append probe did not reach its exact current admission '
            . 'boundary; evidence=' . json_encode($probe);
      }
      if (
         $probe['append_final_state'] === States::Incomplete->name
         && $probe['append_rejected'] === false
         && $probe['append_retained_fields']
            === $probe['append_completed_fields'] + 1
         && $probe['append_peak_growth'] > $probe['append_admitted_growth']
      ) {
         Vars::$labels = ['H1 retained field-append allocator evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: completing one active multipart text field grew the '
            . 'heap by ' . $probe['append_peak_growth'] . ' bytes while the current exact '
            . 'preallocation bound admitted only ' . $probe['append_admitted_growth']
            . ' bytes; evidence=' . json_encode($probe);
      }
      if (
         $probe['append_final_state'] !== States::Incomplete->name
         || $probe['append_rejected'] !== false
         || $probe['append_retained_fields']
            !== $probe['append_completed_fields'] + 1
         || $probe['append_peak_growth'] > $probe['append_admitted_growth']
      ) {
         Vars::$labels = ['H1 retained field-append secure-accounting evidence'];
         dump(json_encode($probe));
         return 'The exact current retained field-append bound did not admit and retain the '
            . 'complete field wholly within its measured heap allowance; evidence='
            . json_encode($probe);
      }
      if (
         $probe['append_budget_free_after'] !== true
         || $probe['append_zero_blocker_accepted'] !== true
         || $probe['append_zero_final_state'] !== States::Rejected->name
         || $probe['append_zero_rejected'] !== true
         || $probe['append_zero_budget_free_after'] !== true
      ) {
         Vars::$labels = ['H1 retained field-append cleanup/control evidence'];
         dump(json_encode($probe));
         return 'The retained field-append leg did not release its ledger exactly or its '
            . 'zero-free control was not rejected; evidence=' . json_encode($probe);
      }

      // ? Refusing a file record at a full worker ledger must happen before
      //   tempnam()/fopen(), or the path must already be owned by abort().
      if (
         $probe['file_reserve_pre_state'] !== States::Incomplete->name
         || $probe['file_reserve_pre_reserved'] <= 0
         || $probe['file_reserve_blocker_accepted'] !== true
      ) {
         Vars::$labels = ['H1 file-record reserve setup evidence'];
         dump(json_encode($probe));
         return 'The file-record reserve probe did not reach its exact zero-free boundary; '
            . 'evidence=' . json_encode($probe);
      }
      if (
         $probe['file_reserve_final_state'] === States::Rejected->name
         && $probe['file_reserve_rejected'] === true
         && $probe['file_reserve_created'] > 0
         && $probe['file_reserve_created_zero_bytes'] === true
      ) {
         Vars::$labels = ['H1 refused file-record tempfile evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: a worker-budget rejection created '
            . $probe['file_reserve_created'] . ' unowned zero-byte tempfile(s) before the '
            . 'file record reservation; evidence=' . json_encode($probe);
      }
      if (
         $probe['file_reserve_final_state'] !== States::Rejected->name
         || $probe['file_reserve_rejected'] !== true
         || $probe['file_reserve_victim_retained'] !== 0
         || $probe['file_reserve_created'] !== 0
         || $probe['file_reserve_cleanup_exact'] !== true
         || $probe['file_reserve_budget_free_after'] !== true
      ) {
         Vars::$labels = ['H1 refused file-record secure-accounting evidence'];
         dump(json_encode($probe));
         return 'The zero-free file record was not refused before creating a tempfile or did '
            . 'not release its exact worker reservation; evidence=' . json_encode($probe);
      }
      if (
         $probe['file_reserve_control_state'] !== States::Incomplete->name
         || $probe['file_reserve_control_rejected'] !== false
         || $probe['file_reserve_control_created'] !== 1
         || $probe['file_reserve_control_cleanup_exact'] !== true
         || $probe['file_reserve_control_budget_free_after'] !== true
      ) {
         Vars::$labels = ['H1 file-record ownership control evidence'];
         dump(json_encode($probe));
         return 'The ample-budget file-record control did not create exactly one owned '
            . 'tempfile and reclaim it on disconnect; evidence=' . json_encode($probe);
      }

      // ? Populated packed arrays can allocate complete replacement storage
      //   when another file record is appended. The current reserve formula
      //   prices both 65-slot destinations before exclusive file creation.
      if (
         $probe['file_record_pre_state'] !== States::Incomplete->name
         || $probe['file_record_pre_files'] !== $probe['file_record_existing']
         || $probe['file_record_pre_reserved'] <= 0
         || $probe['file_record_bound'] <= $probe['file_record_pre_reserved']
         || $probe['file_record_bound'] > $probe['file_record_budget']
         || $probe['file_record_allowed_growth']
            !== $probe['file_record_bound'] - $probe['file_record_pre_reserved']
         || $probe['file_record_blocker_accepted'] !== true
      ) {
         Vars::$labels = ['H1 populated file-record append setup'];
         dump(json_encode($probe));
         return 'The populated file-record probe did not reach the exact current admission '
            . 'boundary; evidence=' . json_encode($probe);
      }
      if (
         $probe['file_record_final_state'] !== States::Incomplete->name
         || $probe['file_record_rejected'] !== false
         || $probe['file_record_final_files']
            !== $probe['file_record_existing'] + 1
         || $probe['file_record_created']
            !== $probe['file_record_existing'] + 1
         || $probe['file_record_peak_growth']
            > $probe['file_record_allowed_growth']
         || $probe['file_record_cleanup_exact'] !== true
         || $probe['file_record_downloads_exact'] !== true
         || $probe['file_record_budget_free_after'] !== true
      ) {
         Vars::$labels = ['H1 populated file-record append evidence'];
         dump(json_encode($probe));
         return 'The exact populated file-record bound did not append its 65th record within '
            . 'the admitted growth and reclaim every owned tempfile/reservation; evidence='
            . json_encode($probe);
      }
      if (
         $probe['file_record_short_blocker_accepted'] !== true
         || $probe['file_record_short_final_state'] !== States::Rejected->name
         || $probe['file_record_short_rejected'] !== true
         || $probe['file_record_short_retained'] !== 0
         || $probe['file_record_short_created'] !== 0
         || $probe['file_record_short_cleanup_exact'] !== true
         || $probe['file_record_short_downloads_exact'] !== true
         || $probe['file_record_short_budget_free_after'] !== true
      ) {
         Vars::$labels = ['H1 populated file-record one-byte-short evidence'];
         dump(json_encode($probe));
         return 'The populated file-record append was not rejected before creation and fully '
            . 'reclaimed when its exact current bound was one byte short; evidence='
            . json_encode($probe);
      }

      // ? A hostile application error handler must not turn the normal
      //   exclusive-create/disconnect path into an ownership leak.
      if (
         $probe['file_warning_state'] !== States::Incomplete->name
         || $probe['file_warning_rejected'] !== false
         || $probe['file_warning_inside_directory'] !== true
         || $probe['file_warning_created'] !== 1
         || $probe['file_warning_cleanup_threw'] !== false
         || $probe['file_warning_cleanup_exact'] !== true
         || $probe['file_warning_downloads_exact'] !== true
         || $probe['file_warning_budget_free_after'] !== true
      ) {
         Vars::$labels = ['H1 warning-handler tempfile ownership'];
         dump(json_encode($probe));
         return 'Exclusive upload creation or disconnect cleanup did not remain inside the '
            . 'intended directory and no-throw under a warning-promoting handler; evidence='
            . json_encode($probe);
      }

      if (
         $probe['file_collision_state'] !== States::Rejected->name
         || $probe['file_collision_rejected'] !== true
         || $probe['file_collision_files'] !== 0
         || $probe['file_collision_cleanup_exact'] !== true
         || $probe['file_collision_downloads_exact'] !== true
         || $probe['file_collision_budget_free_after'] !== true
      ) {
         Vars::$labels = ['H1 colliding file-key ownership evidence'];
         dump(json_encode($probe));
         return 'Colliding multipart file keys were not rejected while the decoder still owned '
            . 'and reclaimed every tempfile and reservation; evidence=' . json_encode($probe);
      }
      if (
         $probe['file_collision_control_state'] !== States::Complete->name
         || $probe['file_collision_control_rejected'] !== false
         || $probe['file_collision_control_files'] !== 2
         || $probe['file_collision_control_created'] !== 2
         || $probe['file_collision_control_cleanup_exact'] !== true
         || $probe['file_collision_control_downloads_exact'] !== true
      ) {
         Vars::$labels = ['H1 unique file-key ownership control'];
         dump(json_encode($probe));
         return 'The distinct-key upload control did not expose both records and reclaim both '
            . 'temporary files through Request ownership; evidence=' . json_encode($probe);
      }

      // ? File-only completion allocates the encoded query, parsed file map
      //   and ownership-bijection map together. Validate the populated 64-file
      //   branch at the exact current finish() boundary.
      if (
         $probe['file_projection_pre_state'] !== States::Incomplete->name
         || $probe['file_projection_pre_reserved'] <= 0
         || $probe['file_projection_bound']
            <= $probe['file_projection_pre_reserved']
         || $probe['file_projection_bound'] > $probe['file_projection_budget']
         || $probe['file_projection_allowed_growth']
            !== $probe['file_projection_bound']
               - $probe['file_projection_pre_reserved']
         || $probe['file_projection_blocker_accepted'] !== true
      ) {
         Vars::$labels = ['H1 file-finish projection setup'];
         dump(json_encode($probe));
         return 'The populated file-only probe did not reach the exact current finish '
            . 'admission boundary; evidence=' . json_encode($probe);
      }
      if (
         $probe['file_projection_final_state'] !== States::Complete->name
         || $probe['file_projection_rejected'] !== false
         || $probe['file_projection_result_files']
            !== $probe['file_projection_files']
         || $probe['file_projection_peak_growth']
            > $probe['file_projection_allowed_growth']
      ) {
         Vars::$labels = ['H1 file-finish projection evidence'];
         dump(json_encode($probe));
         return 'The exact populated file-only finish projection did not expose every record '
            . 'inside its admitted heap growth; evidence=' . json_encode($probe);
      }
      if (
         $probe['file_projection_cleanup_exact'] !== true
         || $probe['file_projection_downloads_exact'] !== true
         || $probe['file_projection_budget_free_after'] !== true
      ) {
         Vars::$labels = ['H1 file-finish projection cleanup'];
         dump(json_encode($probe));
         return 'The populated file-only finish leg did not reclaim every tempfile, disk '
            . 'reservation, and worker-budget reservation; evidence=' . json_encode($probe);
      }

      if ($probe['artifact_residual_files'] > 0) {
         Vars::$labels = ['H1 upload artifact ownership evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: the complete native case left '
            . $probe['artifact_residual_files'] . ' unowned upload artifact(s) before its '
            . 'exact validation-only cleanup; evidence=' . json_encode($probe);
      }

      // ? The file-key map is a body-parser allocation too. Raw keys fitting
      //   does not authorize a larger URL-encoded shaping allocation at finish.
      if (
         $probe['file_transform_pre_state'] !== States::Incomplete->name
         || $probe['file_transform_raw_fits'] !== true
         || $probe['file_transform_encoded_does_not_fit'] !== true
      ) {
         Vars::$labels = ['H1 multipart file-transform setup evidence'];
         dump(json_encode($probe));
         return 'The multipart file-transform probe did not establish its bounded '
            . 'raw-fits/encoded-does-not-fit control; evidence=' . json_encode($probe);
      }
      if (
         $probe['file_transform_final_state'] !== States::Rejected->name
         || $probe['file_transform_rejected'] !== true
      ) {
         Vars::$labels = ['H1 multipart file-transform budget evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: multipart file keys were URL-encoded at finish even '
            . 'though their transformed footprint exceeded the remaining worker budget; '
            . 'evidence=' . json_encode($probe);
      }
      if (
         $probe['file_transform_budget_free_after'] !== true
         || $probe['file_transform_control_state'] !== States::Complete->name
         || $probe['file_transform_control_files'] !== 2
      ) {
         Vars::$labels = ['H1 multipart file-transform cleanup/control evidence'];
         dump(json_encode($probe));
         return 'File-transform rejection did not release the ledger exactly or the short-key '
            . 'positive control did not complete; evidence=' . json_encode($probe);
      }

      // ? The exact admission boundary: the remainder left free is precisely
      //   what the superseded bound asked for, so anything the bound omitted
      //   is what decides this leg.
      if (
         $probe['boundary_blocker_accepted'] !== true
         || $probe['boundary_raw_retained'] <= 0
      ) {
         Vars::$labels = ['H1 boundary setup'];
         dump(json_encode($probe));
         return 'The H1 boundary probe did not reach its exact admission boundary; evidence='
            . json_encode($probe);
      }
      if (
         $probe['boundary_final_state'] !== States::Rejected->name
         || $probe['boundary_rejected'] !== true
         || $probe['boundary_files'] !== 0
      ) {
         Vars::$labels = ['H1 boundary evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: multipart completion allocated its shaped file-key maps '
            . 'at the exact admission boundary, where only the superseded bound ('
            . $probe['boundary_legacy_projection'] . ' bytes) fits; evidence='
            . json_encode($probe);
      }
      if ($probe['boundary_budget_free_after'] !== true) {
         Vars::$labels = ['H1 boundary evidence'];
         dump(json_encode($probe));
         return 'The refused completion did not return its whole reservation; evidence='
            . json_encode($probe);
      }

      // ? Deep bracket notation multiplies one field into many decoded
      //   HashTables. The worker budget must price that structure before
      //   parse_str() creates it, not use one flat allowance per wire part.
      if (
         $probe['nested_pre_state'] !== States::Incomplete->name
         || $probe['nested_blocker_accepted'] !== true
         || $probe['nested_projection'] <= 0
      ) {
         Vars::$labels = ['H1 nested-map setup'];
         dump(json_encode($probe));
         return 'The H1 nested-map probe did not reach its exact flat-projection boundary; '
            . 'evidence=' . json_encode($probe);
      }
      if (
         $probe['nested_final_state'] !== States::Rejected->name
         || $probe['nested_rejected'] !== true
         || $probe['nested_result_fields'] !== 0
      ) {
         Vars::$labels = ['H1 nested-map evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: multipart bracket names expanded into '
            . $probe['nested_array_nodes'] . ' decoded array nodes at completion while only '
            . 'the flat ' . $probe['nested_projection'] . '-byte projection was reserved; '
            . 'peak delta=' . $probe['nested_peak_delta'] . '; evidence='
            . json_encode($probe);
      }
      if (
         $probe['nested_released_exactly'] !== true
         || $probe['nested_one_more_refused'] !== true
         || $probe['nested_budget_free_after'] !== true
      ) {
         Vars::$labels = ['H1 nested-map cleanup evidence'];
         dump(json_encode($probe));
         return 'Nested-map rejection did not release its exact reservation before teardown; '
            . 'evidence=' . json_encode($probe);
      }
      if (
         $probe['nested_control_state'] !== States::Complete->name
         || $probe['nested_control_fields'] !== $probe['nested_fields']
         || $probe['nested_control_array_nodes']
            < $probe['nested_fields'] * $probe['nested_depth']
      ) {
         Vars::$labels = ['H1 nested-map positive control'];
         dump(json_encode($probe));
         return 'The sufficient-budget nested-map control did not complete with the expected '
            . 'decoded structure; evidence=' . json_encode($probe);
      }

      // ? The reservation used by finish() is an absolute bound, so the actual
      //   heap growth after its last pre-completion reservation must fit inside
      //   the growth that the ledger admits at the exact boundary.
      if (
         $probe['projection_pre_state'] !== States::Incomplete->name
         || $probe['projection_blocker_accepted'] !== true
         || $probe['projection_allowed_growth'] <= 0
      ) {
         Vars::$labels = ['H1 current-projection setup'];
         dump(json_encode($probe));
         return 'The H1 current-projection probe did not reach the exact admission boundary; '
            . 'evidence=' . json_encode($probe);
      }
      if (
         $probe['projection_final_state'] === States::Complete->name
         && $probe['projection_rejected'] === false
         && $probe['projection_result_fields'] === $probe['projection_fields']
         && $probe['projection_decoded_key_bytes']
            === $probe['projection_fields'] * $probe['projection_name_bytes']
         && $probe['projection_peak_growth'] > $probe['projection_allowed_growth']
      ) {
         Vars::$labels = ['H1 current-projection evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: multipart completion admitted only '
            . $probe['projection_allowed_growth'] . ' bytes of worker-ledger growth but '
            . 'parse_str() grew the heap by ' . $probe['projection_peak_growth']
            . ' bytes while materializing attacker-sized decoded keys; evidence='
            . json_encode($probe);
      }
      if (
         $probe['projection_final_state'] !== States::Complete->name
         || $probe['projection_rejected'] !== false
         || $probe['projection_result_fields'] !== $probe['projection_fields']
         || $probe['projection_decoded_key_bytes']
            !== $probe['projection_fields'] * $probe['projection_name_bytes']
         || $probe['projection_peak_growth'] > $probe['projection_allowed_growth']
      ) {
         Vars::$labels = ['H1 current-projection secure accounting'];
         dump(json_encode($probe));
         return 'The exact current projection did not complete with every expected field '
            . 'inside its admitted heap growth; evidence=' . json_encode($probe);
      }
      if ($probe['projection_budget_free_after'] !== true) {
         Vars::$labels = ['H1 current-projection cleanup'];
         dump(json_encode($probe));
         return 'Current-projection completion did not release the worker ledger; evidence='
            . json_encode($probe);
      }

      // ? The revised two-copy projection must itself be a bound. This leg
      //   leaves that NEW projection—not the superseded one—exactly available.
      if (
         $probe['floor_pre_state'] !== States::Incomplete->name
         || $probe['floor_pre_reserved'] <= 0
         || $probe['floor_current_bound'] <= $probe['floor_pre_reserved']
         || $probe['floor_current_bound'] > $probe['floor_budget']
         || $probe['floor_blocker_accepted'] !== true
      ) {
         Vars::$labels = ['H1 projection-floor setup'];
         dump(json_encode($probe));
         return 'The H1 projection-floor probe did not reach the revised exact admission '
            . 'boundary; evidence=' . json_encode($probe);
      }
      if (
         $probe['floor_final_state'] === States::Complete->name
         && $probe['floor_rejected'] === false
         && $probe['floor_result_fields'] === $probe['floor_fields']
         && $probe['floor_decoded_key_bytes']
            === $probe['floor_fields'] * $probe['floor_name_bytes']
         && $probe['floor_peak_growth'] > $probe['floor_allowed_growth']
      ) {
         Vars::$labels = ['H1 projection-floor evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: the revised two-copy multipart projection admitted '
            . $probe['floor_allowed_growth'] . ' bytes of worker-ledger growth but its '
            . 'fixed parse/map allocation floor grew the heap by '
            . $probe['floor_peak_growth'] . ' bytes; evidence=' . json_encode($probe);
      }
      if (
         $probe['floor_final_state'] !== States::Complete->name
         || $probe['floor_rejected'] !== false
         || $probe['floor_result_fields'] !== $probe['floor_fields']
         || $probe['floor_decoded_key_bytes']
            !== $probe['floor_fields'] * $probe['floor_name_bytes']
         || $probe['floor_peak_growth'] > $probe['floor_allowed_growth']
      ) {
         Vars::$labels = ['H1 projection-floor secure accounting'];
         dump(json_encode($probe));
         return 'The exact projection-floor bound did not complete with every expected field '
            . 'inside its admitted heap growth; evidence=' . json_encode($probe);
      }
      if (
         $probe['floor_budget_free_after'] !== true
         || $probe['floor_control_state'] !== States::Complete->name
         || $probe['floor_control_rejected'] !== false
         || $probe['floor_control_fields'] !== $probe['floor_fields']
      ) {
         Vars::$labels = ['H1 projection-floor cleanup/control'];
         dump(json_encode($probe));
         return 'The revised projection did not release its ledger or the matched ample-budget '
            . 'control failed; evidence=' . json_encode($probe);
      }

      // ? Output keys cross allocator classes independently of the retained
      //   encoded query. The exact CURRENT projection must include that block
      //   growth, not merely the decoded character count.
      if (
         $probe['cliff_pre_state'] !== States::Incomplete->name
         || $probe['cliff_pre_reserved'] <= 0
         || $probe['cliff_current_bound'] <= $probe['cliff_pre_reserved']
         || $probe['cliff_current_bound'] > $probe['cliff_budget']
         || $probe['cliff_blocker_accepted'] !== true
      ) {
         Vars::$labels = ['H1 decoded-key cliff setup'];
         dump(json_encode($probe));
         return 'The H1 decoded-key allocator-cliff probe did not reach the exact current '
            . 'admission boundary; evidence=' . json_encode($probe);
      }
      if (
         $probe['cliff_final_state'] === States::Complete->name
         && $probe['cliff_rejected'] === false
         && $probe['cliff_result_fields'] === $probe['cliff_fields']
         && $probe['cliff_decoded_key_bytes']
            === $probe['cliff_fields'] * $probe['cliff_name_bytes']
         && $probe['cliff_peak_growth'] > $probe['cliff_allowed_growth']
      ) {
         Vars::$labels = ['H1 decoded-key cliff evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: decoded multipart keys crossed a Zend allocator class and '
            . 'grew the heap by ' . $probe['cliff_peak_growth'] . ' bytes while the exact '
            . 'current projection admitted only ' . $probe['cliff_allowed_growth']
            . ' bytes; evidence=' . json_encode($probe);
      }
      if (
         $probe['cliff_final_state'] !== States::Complete->name
         || $probe['cliff_rejected'] !== false
         || $probe['cliff_result_fields'] !== $probe['cliff_fields']
         || $probe['cliff_decoded_key_bytes']
            !== $probe['cliff_fields'] * $probe['cliff_name_bytes']
         || $probe['cliff_peak_growth'] > $probe['cliff_allowed_growth']
      ) {
         Vars::$labels = ['H1 decoded-key cliff secure accounting'];
         dump(json_encode($probe));
         return 'The exact decoded-key allocator-cliff bound did not complete with every '
            . 'expected field inside its admitted heap growth; evidence='
            . json_encode($probe);
      }
      if (
         $probe['cliff_budget_free_after'] !== true
         || $probe['cliff_control_state'] !== States::Complete->name
         || $probe['cliff_control_rejected'] !== false
         || $probe['cliff_control_fields'] !== $probe['cliff_fields']
      ) {
         Vars::$labels = ['H1 decoded-key cliff cleanup/control'];
         dump(json_encode($probe));
         return 'The decoded-key allocator-cliff leg did not release its ledger or its matched '
            . 'control failed; evidence=' . json_encode($probe);
      }

      // ? Bracket syntax splits one raw name into independently allocated
      //   decoded key strings. The exact projection must price every component
      //   block, not one block for the concatenated wire name.
      if (
         $probe['segmented_pre_state'] !== States::Incomplete->name
         || $probe['segmented_header_bytes'] > Request::$maxMultipartHeaderSize
         || $probe['segmented_pre_reserved'] <= 0
         || $probe['segmented_current_bound'] <= $probe['segmented_pre_reserved']
         || $probe['segmented_current_bound'] > $probe['segmented_budget']
         || $probe['segmented_blocker_accepted'] !== true
      ) {
         Vars::$labels = ['H1 segmented-key setup'];
         dump(json_encode($probe));
         return 'The H1 segmented-key probe did not reach a valid exact current admission '
            . 'boundary; evidence=' . json_encode($probe);
      }
      if (
         $probe['segmented_final_state'] === States::Complete->name
         && $probe['segmented_rejected'] === false
         && $probe['segmented_result_fields'] === $probe['segmented_fields']
         && $probe['segmented_peak_growth'] > $probe['segmented_allowed_growth']
      ) {
         Vars::$labels = ['H1 segmented-key allocator evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: bracketed multipart names grew the completion heap by '
            . $probe['segmented_peak_growth'] . ' bytes while the exact current projection '
            . 'admitted only ' . $probe['segmented_allowed_growth']
            . ' bytes; evidence=' . json_encode($probe);
      }
      if (
         $probe['segmented_final_state'] !== States::Complete->name
         || $probe['segmented_rejected'] !== false
         || $probe['segmented_result_fields'] !== $probe['segmented_fields']
         || $probe['segmented_peak_growth'] > $probe['segmented_allowed_growth']
      ) {
         Vars::$labels = ['H1 segmented-key secure accounting'];
         dump(json_encode($probe));
         return 'The exact segmented-key bound did not complete with every expected field '
            . 'inside its admitted heap growth; evidence=' . json_encode($probe);
      }
      if (
         $probe['segmented_budget_free_after'] !== true
         || $probe['segmented_control_state'] !== States::Complete->name
         || $probe['segmented_control_rejected'] !== false
         || $probe['segmented_control_fields'] !== $probe['segmented_fields']
      ) {
         Vars::$labels = ['H1 segmented-key cleanup/control'];
         dump(json_encode($probe));
         return 'The segmented-key leg did not release its ledger or its identical control '
            . 'failed; evidence=' . json_encode($probe);
      }

      // ? File metadata arrays survive between transport events. Their heap
      //   footprint must draw on the same worker ledger as their string bytes.
      if (
         $probe['container_pre_state'] !== States::Rejected->name
         && $probe['container_rejected'] !== true
         && $probe['container_files_retained'] === $probe['container_files']
         && $probe['container_heap_delta'] > $probe['container_budget']
         && $probe['container_reserved'] < $probe['container_heap_delta']
      ) {
         Vars::$labels = ['H1 retained-container evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: incomplete multipart requests retained '
            . $probe['container_heap_delta'] . ' bytes of file metadata containers while '
            . 'the worker ledger reserved only ' . $probe['container_reserved']
            . ' bytes; evidence=' . json_encode($probe);
      }
      if (
         $probe['container_pre_state'] !== States::Rejected->name
         || $probe['container_rejected'] !== true
      ) {
         Vars::$labels = ['H1 retained-container secure-accounting evidence'];
         dump(json_encode($probe));
         return 'Persistent multipart file containers did not draw on the worker budget before '
            . 'finish(); evidence=' . json_encode($probe);
      }
      if (
         $probe['container_budget_free_before_teardown'] !== true
         || $probe['container_budget_free_after_teardown'] !== true
      ) {
         Vars::$labels = ['H1 retained-container cleanup evidence'];
         dump(json_encode($probe));
         return 'Container-budget rejection did not release its reservation before teardown; '
            . 'evidence=' . json_encode($probe);
      }

      // ? CONTROLS FIRST: both the handler and the Handled listener — the
      //   last reader of the live Request — must still observe the real body.
      //   Without these, a scrub placed too early would satisfy every
      //   assertion below while silently emptying the body under consumers.
      if (
         $probe['retained_handler_saw'] !== $probe['retained_payload_bytes'] * 6
         || $probe['retained_listener_saw'] !== $probe['retained_payload_bytes'] * 6
      ) {
         Vars::$labels = ['H1 retained-body consumer controls'];
         dump(json_encode($probe));
         return 'Request consumers stopped seeing the body: the handler read '
            . $probe['retained_handler_saw'] . ' and the Handled listener '
            . $probe['retained_listener_saw'] . ' bytes across 6 cycles, expected '
            . ($probe['retained_payload_bytes'] * 6) . ' each; evidence='
            . json_encode($probe);
      }
      if ($probe['retained_frag_state'] !== States::Complete->name) {
         Vars::$labels = ['H1 retained-body setup'];
         dump(json_encode($probe));
         return 'The fragmented retained-body leg never completed its body; evidence='
            . json_encode($probe);
      }

      // ? A body that completed its own response cycle must not still be
      //   resident: the ledger bounds only unfinished bodies.
      if ($probe['retained_frag_bytes'] !== 0 || $probe['retained_frag_fields'] !== 0) {
         Vars::$labels = ['H1 retained-body evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: a completed fragmented body stayed resident after its '
            . 'response cycle — ' . $probe['retained_frag_bytes'] . ' raw bytes and '
            . $probe['retained_frag_fields'] . ' parsed fields, none of them reserved; '
            . 'evidence=' . json_encode($probe);
      }
      if ($probe['retained_initial_decoder'] !== false) {
         Vars::$labels = ['H1 retained-body evidence'];
         dump(json_encode($probe));
         return 'The initial-read-complete leg installed a body decoder, so it is not '
            . 'exercising the unreserved path; evidence=' . json_encode($probe);
      }
      if ($probe['retained_initial_bytes'] !== 0 || $probe['retained_initial_fields'] !== 0) {
         Vars::$labels = ['H1 retained-body evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: a body delivered complete in the first read stayed '
            . 'resident after its response cycle — ' . $probe['retained_initial_bytes']
            . ' raw bytes, never reserved by any decoder; evidence=' . json_encode($probe);
      }
      if ($probe['retained_deferred_live_bytes'] !== 0) {
         Vars::$labels = ['H1 deferred retention'];
         dump(json_encode($probe));

         return 'H1 still reproduced (deferred): the live Request kept '
            . $probe['retained_deferred_live_bytes'] . ' body bytes after the encoder '
            . 'returned for a deferred response; evidence=' . json_encode($probe);
      }
      if ($probe['retained_deferred_clone_bytes'] !== $probe['retained_payload_bytes'] + 2) {
         Vars::$labels = ['H1 deferred capture control'];
         dump(json_encode($probe));

         return 'The deferred leg did not hand the Fiber a usable body copy — '
            . $probe['retained_deferred_clone_bytes'] . ' bytes, expected '
            . ($probe['retained_payload_bytes'] + 2) . '; evidence=' . json_encode($probe);
      }
      if ($probe['retained_deferred_ledger_drained'] !== true) {
         Vars::$labels = ['H1 deferred ledger drain'];
         dump(json_encode($probe));

         return 'The deferred snapshot did not return its reservation when cleaned — '
            . 'the worker ledger stayed charged after the Fiber would have finished; '
            . 'evidence=' . json_encode($probe);
      }
      if ($probe['retained_deferred_ledger_free'] !== false) {
         Vars::$labels = ['H1 deferred ledger'];
         dump(json_encode($probe));

         return 'H1 still reproduced (deferred): the captured Request holds '
            . $probe['retained_deferred_clone_bytes'] . ' body bytes while the worker '
            . 'ledger granted its ENTIRE budget — the parked copy draws on no '
            . 'reservation at all; evidence=' . json_encode($probe);
      }
      if ($probe['retained_aggregate_bytes'] !== 0) {
         Vars::$labels = ['H1 idle keep-alive retention'];
         dump(json_encode($probe));

         return 'H1 still reproduced: ' . $probe['retained_peers'] . ' idle keep-alive peers '
            . 'held ' . $probe['retained_aggregate_bytes'] . ' bytes of completed bodies '
            . 'outside every worker ceiling; evidence=' . json_encode($probe);
      }

      // ? Teardown must be deterministic, not cycle-collector dependent.
      if ($probe['waiting_disconnecting'] === false || $probe['chunked_disconnecting'] === false) {
         Vars::$labels = ['H1 teardown evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: the HTTP/1 body decoders do not implement Disconnecting, '
            . 'so Connection::close() never releases their retained body; evidence='
            . json_encode($probe);
      }
      if ($probe['request_alive_after_disconnect'] !== false) {
         Vars::$labels = ['H1 teardown evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: the decoded Request survived decoder teardown without a '
            . 'cycle collection; evidence=' . json_encode($probe);
      }
      if ($probe['ledger_empty_after_disconnect'] === false) {
         Vars::$labels = ['H1 teardown evidence'];
         dump(json_encode($probe));
         return 'Decoder teardown did not return the worker ledger to zero; evidence='
            . json_encode($probe);
      }
      if (
         $probe['isolated'] === false
         || $probe['ledger_exact'] === false
         || $probe['control_reservation_released'] === false
      ) {
         Vars::$labels = ['H1 reservation isolation evidence'];
         dump(json_encode($probe));
         return 'One decoder released bytes it never reserved, or a release did not return '
            . 'them to the worker ledger; evidence=' . json_encode($probe);
      }

      // ? The closed connection graph itself. The shell is allowed to outlive
      //   refcounting — the self-cycle it needs for the transport hot path is a
      //   deliberate trade — but it must not outlive the collector. What must
      //   never wait for the collector is request DATA, which the transport-close
      //   leg below asserts separately.
      if ($probe['connection_alive_after_gc'] !== false) {
         Vars::$labels = ['H1 connection lifetime evidence'];
         dump(json_encode($probe));
         return 'A released Connection survived even a cycle collection (collected='
            . $probe['connection_gc_collected'] . '), so something still pins the shell '
            . 'and its retention is unbounded rather than deferred; evidence='
            . json_encode($probe);
      }

      // ? The production teardown, driven by Connection::close() with a body
      //   attached — not by a hand-called disconnect().
      if ($probe['closed_via_transport'] !== true) {
         Vars::$labels = ['H1 transport-close evidence'];
         dump(json_encode($probe));
         return 'The transport-close leg never reached Connection::close(); evidence='
            . json_encode($probe);
      }
      if ($probe['body_alive_after_close'] !== false) {
         Vars::$labels = ['H1 transport-close evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: the decoded Request survived Connection::close() '
            . 'without a cycle collection; evidence=' . json_encode($probe);
      }
      if ($probe['budget_free_after_close'] !== true) {
         Vars::$labels = ['H1 transport-close evidence'];
         dump(json_encode($probe));
         return 'Connection::close() did not return the retained body to the worker '
            . 'ledger; evidence=' . json_encode($probe);
      }

      return true;
   },
);
