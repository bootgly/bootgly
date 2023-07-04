<?php
namespace Bootgly\Web\nodes\HTTP\Server\tests;


use Bootgly\ACI\Logs\Logger;

use Bootgly\Web\nodes\HTTP;


return [
   // * Config
   'autoBoot' => function () {
      // TODO configure verbosity of server output/echo
      // TODO like $HTTPServer->verbosity = 0;
      Logger::$display = Logger::DISPLAY_NONE;

      $HTTP_Server = new HTTP\Server;
      // * Config
      $HTTP_Server->mode = $HTTP_Server::MODE_TEST;
      $HTTP_Server->configure(
         host: '0.0.0.0',
         port: 8080,
         workers: 1
      );

      $HTTP_Server->start();

      $HTTP_Server->Terminal->command('test');

      return [];
   },
   // * Data
   'files' => [
      'Request/' => [
         '1.01-request_as_response-address',
         '1.02-request_as_response-port',
         '1.03-request_as_response-scheme',
         '1.04-request_as_response-raw',
         '1.05-request_as_response-method',
         '1.06-request_as_response-uri',
         '1.07-request_as_response-protocol',
         '1.08-request_as_response-url',
         '1.09-request_as_response-urn',
         '1.10-request_as_response-query',
         '1.11-request_as_response-queries',
         '1.12-request_as_response-basic-auth',
         '1.13-request_as_response-headers',
         '1.14.1-request_as_response-header-accept_language',
         '1.15.1-request_as_response-header-cookies',
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
      ]
   ]
];
