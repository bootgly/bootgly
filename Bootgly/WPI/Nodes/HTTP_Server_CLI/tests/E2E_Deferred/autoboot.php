<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\E2E_Deferred;


use const BOOTGLY_ROOT_DIR;
use function define;
use function defined;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;


return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Display::show(Display::NONE);

      // @ A project context is required for the process state lock.
      if ( ! defined('BOOTGLY_PROJECT') ) {
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/Demo/HTTP_Server_CLI/HTTP_Server_CLI.Project.php';
         $TestProject = require $projectFile;
         define('BOOTGLY_PROJECT', $TestProject);
      }

      HTTP_Server_CLI::pretest($Suite, 'E2E_Deferred');

      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Test);
      $HTTP_Server_CLI->configure(new HTTP_Server_CLI\Configs(
         host: '0.0.0.0',
         // ? 8104 — 8081-8097 belong to the other E2E suites, 8098 to
         //   ACME_Challenge (and the E2E upstream fixture), 8099 to ACME_Swap,
         //   8100 to ACME_E2E, 8101 to E2E_DualStack, 8102 is the E2E TLS
         //   upstream fixture and 8103 is E2E_Idle.
         port: 8104,
         workers: 1,
         health: '/health'
      ));

      $HTTP_Server_CLI->start();

      $HTTP_Server_CLI->Commands->command('test');

      // @ Teardown: terminate workers and release state lock so the next
      //   suite running in the same master PHP process can bind/lock cleanly.
      $HTTP_Server_CLI->Process->stopping = true;
      $HTTP_Server_CLI->Process->Children->terminate();
      $HTTP_Server_CLI->Process->State->clean();

      return true;
   },
   suiteName: __NAMESPACE__,
   // * Data
   tests: [
      '1.1-fields_and_params_survive_await',
      '1.2-outer_use_request_is_scrubbed',
      '1.3-session_write_after_await_persists',
      '1.4-session_first_touch_after_await_sets_cookie',
      '1.5-session_write_then_throw_persists_and_500',
      '1.6-credentials_and_files_survive_await',
      '1.7-pipelined_request_waits_for_parked',
      '1.8-session_write_then_sse_handoff_persists',
      '1.9-session_write_then_client_leaves_not_persisted',
      '1.10-session_write_around_nested_defer_persists',
      // BG-14: Throwables from deferred work reach the Recovering boundaries
      '2.1-boundary_answers_deferred_throw',
      '2.2-boundary_fresh_response_and_session',
      '2.3-nested_boundaries_innermost_first',
      '2.4-throwing_recover_replaces_throwable',
      '2.5-global_boundary_answers',
      '2.6-admission_not_rerun',
      '2.7-timeout_recovered_as_503',
      '2.8-route_snapshot_cleared_per_request',
      '2.9-boundary_handoff_single_wire',
      '2.10-chain_published_before_process',
      '2.11-timeout_bounds_parked_boundary',
      '2.12-timeout_bounds_two_parked_boundaries',
      '2.13-budget_bounds_parked_boundary_after_any_throwable',
      // BG-23: the worker reserves on THIS server's counter, not the first server's
      '3.1-upload_counter_follows_the_booted_server',
      // BG-22: the Sealing pass decorates deferred responses at settlement
      '4.1-sealing_decorates_deferred_success',
      '4.2-sealing_decorates_error_answers',
      '4.3-shipped_etag_compression_seal_deferred',
      '4.4-throwing_seal_reaches_boundaries',
      // BG-24: records carry the bound port as their instance (appended last — indexes stay stable)
      '3.2-records_carry_the_booted_instance',
      // FEAT-1: the AccessLog middleware writes one line per request, cancelled ones included (appended last)
      '5.1-access_log_lines_per_outcome',
      '5.2-access_log_cancelled_and_timeout',
      // H-HSC-5: the snapshot keeps its own head once the live Request drops its copy
      '5.3-snapshot_keeps_own_head',
      // H-HSC-5: an SSE stream keeps reading its route's live Request
      '5.4-sse_keeps_route_request',
      // H-HSC-6: an HTTP/1.1 connection parks one deferral at a time — the
      //   requests pipelined behind it wait and are answered in order
      '6.1-pipelined_answers_in_order',
      '6.2-one_park_per_connection',
      '6.3-sse_handoff_discards_held_bytes',
      '6.4-connection_close_ends_pipeline',
      '6.5-abandoned_deferral_closes',
      '6.6-second_park_answered_after_another_connection'
   ]
);
