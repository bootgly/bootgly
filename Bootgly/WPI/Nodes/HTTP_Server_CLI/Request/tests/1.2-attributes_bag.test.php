<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


return new Specification(
   description: 'HTTP Request attributes bag: magic __set/__get/__isset/__unset route undeclared properties',
   test: function () {
      $Request = new Request;

      // ? Reading a never-set attribute
      yield assert(
         assertion: isSet($Request->tenant) === false,
         description: 'isset() on a never-set attribute returns false'
      );
      yield assert(
         assertion: $Request->tenant === null,
         description: 'Reading a never-set attribute returns null (no dynamic-property creation)'
      );

      // ? Writing and reading back
      $Request->tenant = 'acme';
      $Request->user = (object) ['id' => 7];

      yield assert(
         assertion: $Request->tenant === 'acme'
            && isSet($Request->tenant) === true,
         description: 'Attribute written via property syntax reads back identically'
      );
      yield assert(
         assertion: $Request->attributes === ['tenant' => 'acme', 'user' => $Request->user],
         description: 'Attributes land in the declared $attributes bag, not as dynamic properties'
      );

      // ? Unset
      unset($Request->tenant);

      yield assert(
         assertion: isSet($Request->tenant) === false
            && $Request->tenant === null
            && isSet($Request->user) === true,
         description: 'unset() removes only the targeted attribute'
      );

      // ? Falsy values must still report as set
      $Request->flag = false;
      $Request->zero = 0;

      yield assert(
         assertion: isSet($Request->flag) === true
            && isSet($Request->zero) === true
            && $Request->flag === false
            && $Request->zero === 0,
         description: 'Falsy attribute values are preserved and isset()-visible'
      );

      // ? Declared properties are NOT routed through the bag
      yield assert(
         assertion: $Request->method === ''
            && isSet($Request->attributes['method']) === false,
         description: 'Declared properties bypass the attributes bag'
      );
   }
);
