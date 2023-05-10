<?php
return [
   'files' => [
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
];
