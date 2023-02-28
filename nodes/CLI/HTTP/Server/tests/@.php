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
      // @ authenticate
      '1.6-basic-authentication',
      // @ redirect
      '1.7-redirect-with-302',
      // @ upload
      // .1 - Small Files
      '1.z.1-upload_small_file',
      // .2.1 - Requests Range - User
      '1.z.2.1-upload_file_with_offset_length_1',
      // .2.2 - Requests Range - Client
      '1.z.2.2.1-upload_file_with_range-requests_1',
      '1.z.2.2.2-upload_file_with_range-requests_2',
      '1.z.2.2.3-upload_file_with_range-requests_3',
      '1.z.2.2.4-upload_file_with_range-requests_4',
      // .3 - Large Files
      '1.z.3-upload_large_file',
   ]
];