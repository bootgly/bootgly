<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Directory;

return new Specification(
   description: 'ACME Directory: endpoint map validation',
   test: function () {
      // @ Valid map
      $Directory = new Directory([
         'newAccount' => 'https://ca.example.test/acme/new-acct',
         'newNonce' => 'https://ca.example.test/acme/new-nonce',
         'newOrder' => 'https://ca.example.test/acme/new-order',
         'revokeCert' => 'https://ca.example.test/acme/revoke-cert',
         'meta' => ['termsOfService' => 'https://ca.example.test/tos']
      ]);

      yield assert(
         assertion: $Directory->newAccount === 'https://ca.example.test/acme/new-acct'
            && $Directory->newNonce === 'https://ca.example.test/acme/new-nonce'
            && $Directory->newOrder === 'https://ca.example.test/acme/new-order'
            && $Directory->revokeCert === 'https://ca.example.test/acme/revoke-cert',
         description: 'a valid directory exposes the four endpoint URLs'
      );

      // @ Optional revokeCert
      $Minimal = new Directory([
         'newAccount' => 'https://ca.example.test/acme/new-acct',
         'newNonce' => 'https://ca.example.test/acme/new-nonce',
         'newOrder' => 'https://ca.example.test/acme/new-order'
      ]);

      yield assert(
         assertion: $Minimal->revokeCert === null,
         description: 'revokeCert is optional (null when absent)'
      );

      // @ Missing required endpoints throw
      foreach (['newAccount', 'newNonce', 'newOrder'] as $required) {
         $endpoints = [
            'newAccount' => 'https://ca.example.test/acme/new-acct',
            'newNonce' => 'https://ca.example.test/acme/new-nonce',
            'newOrder' => 'https://ca.example.test/acme/new-order'
         ];
         unset($endpoints[$required]);

         $thrown = false;
         try {
            new Directory($endpoints);
         }
         catch (InvalidArgumentException) {
            $thrown = true;
         }

         yield assert(
            assertion: $thrown,
            description: "a directory missing `{$required}` throws"
         );
      }

      // @ Non-string endpoint throws
      $thrown = false;
      try {
         new Directory([
            'newAccount' => ['nested' => 'nope'],
            'newNonce' => 'https://ca.example.test/acme/new-nonce',
            'newOrder' => 'https://ca.example.test/acme/new-order'
         ]);
      }
      catch (InvalidArgumentException) {
         $thrown = true;
      }

      yield assert(
         assertion: $thrown,
         description: 'a non-string endpoint throws'
      );
   }
);
