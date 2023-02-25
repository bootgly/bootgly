<?php
return [
   'files' => [
      '1.1-respond_hello_world',
      // ! Meta
      // status
      '1.2-respond_with_status_302',
      '1.2.1-respond_with_status_500_no_body',
      // ! Header
      // Content-Type
      '1.3-respond_with_content_type_text_plain',
      // ? Header \ Cookie
      '1.4-respond_with_set_cookies',
      // ! Content
      // @ send
      '1.5-send_content_in_json',
      // @ upload
      '1.6-upload_small_file',
      '1.6.1.1-upload_file_with_offset_length_1',
      '1.6.2.1-upload_file_with_range-requests_1',
      '1.6.3-upload_large_file',
      // @ authenticate
      '1.7.1-basic-authentication',
      // @ redirect
      '1.8.1-redirect-with-302'
   ]
];