<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Nonces;

return new Specification(
   description: 'ACME Nonces: pool store/take/clear behavior',
   test: function () {
      $Nonces = new Nonces();

      yield assert(
         assertion: $Nonces->take() === null,
         description: 'a fresh pool is dry (take returns null)'
      );

      $Nonces->store('nonce-a');
      $Nonces->store('nonce-b');
      $Nonces->store('');

      $first = $Nonces->take();
      $second = $Nonces->take();

      yield assert(
         assertion: $first === 'nonce-b' && $second === 'nonce-a',
         description: 'stored nonces are consumed one per take (empty strings ignored)'
      );
      yield assert(
         assertion: $Nonces->take() === null,
         description: 'the pool is dry after all nonces are consumed'
      );

      $Nonces->store('nonce-c');
      $Nonces->clear();

      yield assert(
         assertion: $Nonces->take() === null,
         description: 'clear() drops every pooled nonce'
      );
   }
);
