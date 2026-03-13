<?php


use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should construct combined',
   test: function () {
      $Path = new Path;
      // * Config
      // @ convert
      $Path->convert = true;
      $Path->lowercase = true;
      // @ fix
      $Path->fix = true;
      $Path->dir_ = true;
      $Path->normalize = true;
      // @ valid
      $Path->real = true;

      $Path2 = clone $Path;
      // @
      // Valid
      $Path->construct('\\ETC\/php\\8.2/..');
      yield assert(
         assertion: (string) $Path === '/etc/php',
         description: 'Combined path is not valid!'
      );
      // Invalid
      $Path2->construct('/usr/bin/fakebootgly');
      yield assert(
         assertion: (string) $Path2 === '',
         description: 'Fake path valid?!'
      );
   }
);
