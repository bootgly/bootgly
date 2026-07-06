<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Exceptions\AuthenticationException;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: AUTH failure (535) and unsupported mechanism set',
   test: function () {
      // @ Server rejects every AUTH
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9998,
         'secure' => 'none',
         'insecure' => true,
         'domain' => 'auth-fail',
         'username' => 'user@example.com',
         'password' => 'secret',
         'timeout' => 5.0,
         'wait' => 5.0
      ]));

      $caught = null;
      try {
         $Client->connect();
      }
      catch (AuthenticationException $Exception) {
         $caught = $Exception;
      }

      yield assert(
         assertion: $caught instanceof AuthenticationException && $caught->getCode() === 535,
         description: 'a 535 rejection throws AuthenticationException carrying the code'
      );

      // @ Credentials configured but the server advertises no usable mechanism
      //   (scenario `no-starttls` advertises no AUTH at all)
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9998,
         'secure' => 'none',
         'insecure' => true,
         'domain' => 'no-starttls',
         'username' => 'user@example.com',
         'password' => 'secret',
         'timeout' => 5.0,
         'wait' => 5.0
      ]));

      $caught = null;
      try {
         $Client->connect();
      }
      catch (AuthenticationException $Exception) {
         $caught = $Exception;
      }

      yield assert(
         assertion: $caught instanceof AuthenticationException,
         description: 'a server without a supported AUTH mechanism throws AuthenticationException'
      );
   }
);
