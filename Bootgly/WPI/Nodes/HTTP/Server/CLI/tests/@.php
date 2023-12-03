<?php

namespace Bootgly\WPI\Nodes\HTTP\Server\CLI\tests;


use Bootgly\ACI\Logs\Logger;

use Bootgly\WPI\Nodes\HTTP\Server\CLI as HTTP_Server_CLI;


return [
   // * Config
   'autoBoot' => function () { // function ($suiteSpecs)
      // TODO configure verbosity of server output/echo
      // TODO like $HTTP_Server_CLI->verbosity = 0;
      Logger::$display = Logger::DISPLAY_NONE;

      $HTTP_Server_CLI = new HTTP_Server_CLI(mode: HTTP_Server_CLI::MODE_TEST);
      // * Config
      $HTTP_Server_CLI->configure(
         host: '0.0.0.0',
         port: 8080,
         workers: 1
      );

      $HTTP_Server_CLI->start();

      $HTTP_Server_CLI->Terminal->command('test'); // TODO up command method to parent class as trait|interface|parent

      return [];
   },
   // * Data
   'tests' => [
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
         '1.20-request_as_response-on',
         '1.21-request_as_response-at',
         '1.22-request_as_response-time',
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
         // @ redirect
         '1.7-redirect_with_302_to_bootgly_docs',
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
         // Route group
         '4.1-route_group-unparameterized',
         '4.2-route_group-parameterized',
         // Route Catch-All
         '5.1-route_catch_all-generic',
      ]
   ]
];
