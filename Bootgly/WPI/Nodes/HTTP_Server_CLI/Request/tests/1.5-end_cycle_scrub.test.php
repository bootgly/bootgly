<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


return new Specification(
   description: 'Request::scrub() releases completed payload while preserving decode metadata',
   test: function () {
      $Request = new Request;
      $Request->Body->raw = 'request-secret';
      $Request->Body->input = 'parsed-secret';
      $Request->Body->length = 14;
      $Request->Body->position = 18;
      $Request->Body->downloaded = 14;
      $Request->Body->waiting = false;
      $Request->Body->streaming = true;
      $Request->fields = ['token' => 'field-secret'];

      $Request->scrub();

      $Reflection = new ReflectionClass($Request);
      $fields = $Reflection->getProperty('_fields')->getRawValue($Request);

      yield assert(
         assertion: $Request->Body->raw === ''
            && $Request->Body->input === null
            && $fields === [],
         description: 'Completed body bytes and parsed fields are released together',
      );

      yield assert(
         assertion: $Request->Body->length === 14
            && $Request->Body->position === 18
            && $Request->Body->downloaded === 14
            && $Request->Body->waiting === false
            && $Request->Body->streaming === true,
         description: 'Decoder-owned body metadata remains intact',
      );

      yield assert(
         assertion: $Reflection->getMethod('scrub')->isFinal(),
         description: 'Mandatory end-cycle cleanup cannot be bypassed by a Request subtype',
      );
   },
);
