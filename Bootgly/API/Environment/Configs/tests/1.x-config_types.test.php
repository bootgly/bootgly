<?php

use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environment\Configs\Config\Types;


return new Specification(
   description: 'Config Types: strict scalar parsing',
   test: function () {
      yield assert(
         assertion: Types::Boolean->cast('false') === false,
         description: 'boolean string false casts to false'
      );
      yield assert(
         assertion: Types::Boolean->cast('true') === true,
         description: 'boolean string true casts to true'
      );
      yield assert(
         assertion: Types::Boolean->cast('off') === false,
         description: 'boolean string off casts to false'
      );
      yield assert(
         assertion: Types::Boolean->cast('on') === true,
         description: 'boolean string on casts to true'
      );
      yield assert(
         assertion: Types::Integer->cast('8080') === 8080,
         description: 'integer numeric string casts strictly'
      );
      yield assert(
         assertion: Types::Float->cast('10.25') === 10.25,
         description: 'float numeric string casts strictly'
      );

      $boolean = false;
      try {
         Types::Boolean->cast('maybe');
      }
      catch (InvalidArgumentException) {
         $boolean = true;
      }
      yield assert(
         assertion: $boolean,
         description: 'invalid boolean string throws'
      );

      $integer = false;
      try {
         Types::Integer->cast('123abc');
      }
      catch (InvalidArgumentException) {
         $integer = true;
      }
      yield assert(
         assertion: $integer,
         description: 'invalid integer string throws'
      );

      $float = false;
      try {
         Types::Float->cast('1.2.3');
      }
      catch (InvalidArgumentException) {
         $float = true;
      }
      yield assert(
         assertion: $float,
         description: 'invalid float string throws'
      );
   }
);
