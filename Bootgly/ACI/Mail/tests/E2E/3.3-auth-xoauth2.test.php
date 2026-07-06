<?php

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Exceptions\AuthenticationException;
use Bootgly\ACI\Mail\SMTP_Client;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'E2E: AUTH XOAUTH2 (bearer token) + 334 JSON error challenge',
   test: function () {
      // @ Valid token (token selects XOAUTH2 even with PLAIN advertised)
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9998,
         'secure' => 'none',
         'insecure' => true,
         'domain' => 'auth-oauth',
         'username' => 'user@example.com',
         'token' => 'good-token',
         'timeout' => 5.0,
         'wait' => 5.0
      ]));

      yield assert(
         assertion: $Client->connect() === true && $Client->authenticated === true,
         description: 'a valid bearer token authenticates via XOAUTH2'
      );

      $Client->disconnect();

      // @ Rejected token: 334 base64-JSON challenge → empty line → 535
      $Client = new SMTP_Client(new Config([
         'host' => '127.0.0.1',
         'port' => 9998,
         'secure' => 'none',
         'insecure' => true,
         'domain' => 'auth-oauth',
         'username' => 'user@example.com',
         'token' => 'bad-token',
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
         description: 'a rejected token ends in AuthenticationException with the final 535'
      );
      yield assert(
         assertion: $caught instanceof AuthenticationException
            && str_contains($caught->getMessage(), '"status":"401"'),
         description: 'the decoded 334 JSON challenge is carried in the exception message'
      );
   }
);
