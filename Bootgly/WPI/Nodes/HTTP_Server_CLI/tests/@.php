<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\tests;

use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;

return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Logger::$display = Logger::DISPLAY_NONE;

      // @ Define BOOTGLY_PROJECT for upload tests (requires project path)
      if ( !defined('BOOTGLY_PROJECT') ) {
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/HTTP_Server_CLI/HTTP_Server_CLI.project.php';
         $TestProject = require $projectFile;
         define('BOOTGLY_PROJECT', $TestProject);
      }

      HTTP_Server_CLI::pretest($Suite);

      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Test);
      $HTTP_Server_CLI->configure(
         host: '0.0.0.0',
         port: 8080,
         workers: 1
      );

      $HTTP_Server_CLI->start();

      $HTTP_Server_CLI->Commands->command('test');

      return true;
   },
   // * Data
   tests: [
      'Request/' => [
         '1.01-request_as_response-address',
         '1.02-request_as_response-port',
         '1.03-request_as_response-scheme',
         '1.04-request_as_response-raw',
         '1.05-request_as_response-method',
         '1.06-request_as_response-uri',
         '1.07-request_as_response-protocol',
         '1.08-request_as_response-url',
         '1.10-request_as_response-query',
         '1.11-request_as_response-queries',
         '1.12-request_as_response-basic-auth',
         '1.13-request_as_response-headers',
         '1.14.1-request_as_response-header-host',
         '1.14.2-request_as_response-header-host',
         '1.14.3-request_as_response-header-host',
         '1.14.4-request_as_response-header-host',
         '1.14.5-request_as_response-header-ips',
         '1.15.1-request_as_response-header-accept_language',
         '1.16.1-request_as_response-header-cookies',
         '1.17.1-request_as_response-content-contents',
         '1.17.2-request_as_response-content-contents',
         '1.17.3-request_as_response-content-contents',
         '1.17.4-request_as_response-content-contents',
         '1.18.1-request_as_response-content-post',
         '1.18.2-request_as_response-content-post',
         '1.18.3-request_as_response-content-post',
         '1.19.1-request_as_response-content-post-file',
         '1.19.2-request_as_response-content-post-file',
         '1.19.3-request_as_response-content-post-file',
         // Streaming Decoder (multipart/form-data → disk)
         '1.20.1-request_as_response-content-streaming-file',
         '1.20.2-request_as_response-content-streaming-file',
         '1.20.3-request_as_response-content-streaming-file',
         '1.20.4-request_as_response-content-streaming-file',
         '1.20.5-request_as_response-content-streaming-file',
         '1.20.6-request_as_response-content-streaming-file',
         '1.20.7-request_as_response-content-streaming-file',
         '1.21-request_as_response-on',
         '1.22-request_as_response-at',
         '1.23-request_as_response-time',
         // HTTP/1.1 Caching Specification (RFC 7234)
         '2.1.1-request_cache-if-modified-since',
         '2.1.2-request_cache-if-modified-since',
         '2.1.3-request_cache-if-modified-since',
         '2.1.4-request_cache-if-modified-since',
         '2.1.5-request_cache-if-modified-since',
         '2.2.1-request_cache-if-none-match',
         '2.2.2-request_cache-if-none-match',
         '2.2.3-request_cache-if-none-match',
         '2.2.4.1-request_cache-if-none-match-etag_weak',
         '2.2.4.2-request_cache-if-none-match-etag_weak',
         '2.2.5-request_cache-if-none-match-etag_strong',
         '2.2.6.1-request_cache-if-none-match-catch_all',
         '2.2.6.2-request_cache-if-none-match-catch_all',
         '2.3.1-request_cache-if-none-match-if-modified-since',
         '2.3.2-request_cache-if-none-match-if-modified-since',
         '2.3.3-request_cache-if-none-match-if-modified-since',
         '2.3.4-request_cache-if-none-match-if-modified-since',

         '2.4-request_cache-no-cache',
         // Session
         '3.2-request_session',
         '3.2.01-request_session-method-set_get',
         '3.2.02-request_session-method-has',
         '3.2.03-request_session-method-delete',
         '3.2.04-request_session-method-pull',
         '3.2.05-request_session-method-put',
         '3.2.06-request_session-method-forget',
         '3.2.08-request_session-method-list',
         '3.2.09-request_session-method-flush',
         '3.2.10-request_session-method-check',
         // HTTP/1.1 Compliance (RFC 9110/9112)
         '4.02-request_compliance-host_header_valid',
         '4.03-request_compliance-http10_no_host',
         '4.07-request_compliance-head_no_body',
      ],
      'Response/' => [
         '1.1-respond_with_a_simple_hello_world',
         // ! Meta
         // status
         '1.2-respond_with_status_302_using_send',
         '1.2.1-respond_with_status_500_no_body',
         // ! Header
         // Content-Type
         '1.3-respond_with_content_type_text_plain',
         // ? Header \ Cookie
         '1.4-respond_with_header_set_cookies',
         // ! Content
         // @ send
         '1.5-send_content_in_json_using_resources',
         // @ authenticate
         '1.6-authenticate_with_http_basic_authentication',
         '1.6.1-authenticate_with_unknown_authentication_method',
         // @ redirect
         '1.7-redirect_to_bootgly_docs',
         '1.7.2-redirect_with_302_to_bootgly_docs',
         // @ upload
         // .1 - Small Files
         '1.z.1-upload_a_small_file',
         // .2.1 - Requests Range - Dev
         '1.z.2.1-upload_file_with_offset_length_1',
         // .2.2 - Requests Range - Client - Single Part (Valid)
         '1.z.2.2.1-upload_file_with_range-requests_1',
         '1.z.2.2.2-upload_file_with_range-requests_2',
         '1.z.2.2.3-upload_file_with_range-requests_3',
         '1.z.2.2.4-upload_file_with_range-requests_4',
         '1.z.2.2.5-upload_file_with_range-requests_5',
         // 2.3 - Requests Range - Client - Single Part (Invalid)
         '1.z.2.3.1-upload_file_with_invalid_range-requests_1',
         '1.z.2.3.2-upload_file_with_invalid_range-requests_2',
         '1.z.2.3.3-upload_file_with_invalid_range-requests_3',
         '1.z.2.3.4-upload_file_with_invalid_range-requests_4',
         '1.z.2.3.5-upload_file_with_invalid_range-requests_5',
         '1.z.2.3.6-upload_file_with_invalid_range-requests_6',
         // 2.4 - Requests Range - Client - Multi (Valid)
         '1.z.2.4.1-upload_file_with_multi_range-requests_1',
         // .3 - Large Files
         '1.z.3-upload_large_file',
      ],
      'Router/' => [
         '1.1-route_callback-closure',
         '1.2-route_callback-function',
         '1.3-route_callback-static_method',
         #'2.1-route_condition-method-custom',
         '2.2.1-route_condition-method-multiple',
         '2.2.2-route_condition-method-multiple',
         '2.2.3-route_condition-method-multiple',
         '3.0-route_route-specific',
         // Named params
         '3.1-route_route-single_param_id',
         '3.2.1-route_route-multiple_param-different',
         '3.2.2-route_route-multiple_param-equals',
         '3.2.3-route_route-multiple_param-equals-different',
         #'3.3.1-route_route-multiple_param-equals-different',
         // Param constraint types
         '3.4.1-route_route-param_constraint_int_valid',
         '3.4.2-route_route-param_constraint_int_invalid',
         '3.4.3-route_route-param_constraint_slug_valid',
         '3.4.4-route_route-param_constraint_alpha_valid',
         '3.4.5-route_route-param_constraint_alpha_invalid',
         '3.4.6-route_route-param_constraint_alphanum_valid',
         '3.4.7-route_route-param_constraint_alphanum_invalid',
         '3.4.8-route_route-param_constraint_slug_invalid',
         '3.4.9-route_route-param_constraint_uuid_valid',
         '3.4.10-route_route-param_constraint_uuid_invalid',
         // Route group
         '4.1-route_group-unparameterized',
         '4.2-route_group-parameterized',
         // Route Catch-All
         '5.1-route_catch_all-generic',
         // Route Catch-All parameterized
         '5.2-route_catch_all-parameterized_single',
         '5.3-route_catch_all-parameterized_multi',
      ],
      'Middleware/' => [
         // CORS
         '1.1-middleware-cors',
         '1.2-middleware-cors_preflight',
         // SecureHeaders
         '2.1-middleware-secure_headers',
         // RequestId
         '3.1-middleware-request_id',
         '3.2-middleware-request_id_preserved',
         // ETag
         '4.1-middleware-etag',
         '4.2-middleware-etag_304',
         // Compression
         '5.1-middleware-compression',
         // BodyParser
         '6.1-middleware-body_parser',
         '6.2-middleware-body_parser_413',
         // RateLimit
         '7.1-middleware-rate_limit',
         // TrustedProxy
         '8.1-middleware-trusted_proxy',
      ],
      'Sequential/' => [
         // Response state reset across sequential requests
         '1.1-response_body_reset_across_routes',
         '1.2-response_status_code_reset_across_routes',
         '1.3-response_header_reset_across_routes',
         // Idempotency
         '1.4-response_idempotent_same_route',
         // Upload + normal request interleaving
         '2.1-response_upload_then_normal_request',
         '2.2-response_normal_then_upload_request',
         '2.3-response_upload_then_different_upload',
      ],
      'Scheduled/' => [
         '1.1-scheduled_sync_baseline',
         '1.2-scheduled_deferred_response',
         '1.3-scheduled_deferred_concurrent',
         '1.4-scheduled_deferred_io_aware',
         '1.5-scheduled_deferred_hybrid',
         '1.6-scheduled_deferred_http_request',
         '1.7-scheduled_deferred_ordering',
      ]
   ]
);
