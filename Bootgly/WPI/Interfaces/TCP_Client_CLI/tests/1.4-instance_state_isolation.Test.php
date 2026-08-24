<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Events;


return new Test(
   description: 'It should keep two clients in one process fully isolated (reactor, hooks, registry)',
   test: new Assertions(Case: function (): Generator {
      // ! Client A with a registered hook and a sentinel registry entry
      $A = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $A->on(Events::DataRead, static function (): void {});
      $Registry = $A->Connections;
      $Registry->errors['connection'] = 7;

      // @ Constructing B must not clear or clobber anything owned by A
      $B = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);

      yield new Assertion(
         description: 'Each client owns its own reactor',
         fallback: 'Two clients ended up sharing one Select reactor!'
      )
         ->expect($A->Event !== $B->Event)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Each client owns its own connection registry',
         fallback: 'Two clients ended up sharing one Connections registry!'
      )
         ->expect($A->Connections !== $B->Connections)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'A hook registered on A never appears on B',
         fallback: 'A hook registered on one client leaked into another!'
      )
         ->expect($A->onDataRead !== null && $B->onDataRead === null)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Constructing B does not reset the counters of A',
         fallback: 'Constructing a second client cleared the first client state!'
      )
         ->expect($A->Connections->errors['connection'])
         ->to->be(7)
         ->assert();
   })
);
