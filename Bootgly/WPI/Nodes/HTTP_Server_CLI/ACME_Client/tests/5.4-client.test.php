<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Account;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptions\ProtocolException;

return new Specification(
   description: 'ACME Client: constructor invariants and bounded Retry-After parsing',
   test: function () {
      $path = sys_get_temp_dir() . '/bootgly-acme-client-contract-' . getmypid() . '/';
      $Account = new Account($path);

      $Cases = [
         static fn () => new ACME_Client($Account, 'https://ca.test/directory', polls: 0),
         static fn () => new ACME_Client($Account, 'https://ca.test/directory', wait: 0),
         static fn () => new ACME_Client($Account, 'https://ca.test/directory', wait: INF),
         static fn () => new ACME_Client($Account, 'http://ca.test/directory')
      ];
      foreach ($Cases as $Construct) {
         $rejected = false;
         try {
            $Construct();
         }
         catch (InvalidArgumentException) {
            $rejected = true;
         }

         yield assert(
            assertion: $rejected,
            description: 'invalid polling/wait/directory contracts fail at construction'
         );
      }

      $Client = new ACME_Client($Account, 'https://ca.test/directory');
      $Delay = new ReflectionMethod($Client, 'delay');
      $Response = new Response;
      $Response->Header->define('Retry-After: ' . str_repeat('9', 100));

      yield assert(
         assertion: $Delay->invoke($Client, $Response) === ACME_Client::MAX_RETRY_AFTER,
         description: 'an extreme numeric Retry-After is clamped before epoch arithmetic'
      );

      $Response->Header->define('Retry-After: invalid-date');
      yield assert(
         assertion: $Delay->invoke($Client, $Response) === null,
         description: 'an invalid Retry-After value is ignored deterministically'
      );

      $Expect = new ReflectionMethod($Client, 'expect');
      $unexpected = false;
      try {
         $Expect->invoke($Client, [
            'code' => 204,
            'location' => null,
            'body' => '',
            'JSON' => null,
            'retryAfter' => null
         ], [200], 'resource poll');
      }
      catch (ProtocolException) {
         $unexpected = true;
      }
      yield assert(
         assertion: $unexpected,
         description: 'an endpoint-specific unexpected 2xx status is rejected deterministically'
      );
   }
);
