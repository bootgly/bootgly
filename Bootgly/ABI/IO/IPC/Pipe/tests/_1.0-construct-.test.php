<?php

use Bootgly\ABI\IO\IPC\Pipe;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Pipe should open, transfer data, guard invalid lengths, and close',

   test: new Assertions(Case: function (): Generator {
      $Pipe = new Pipe();

      yield (new Assertion(description: 'new pipe starts unpaired'))
         ->expect($Pipe->paired)
         ->to->be(false)
         ->assert();

      yield (new Assertion(description: 'read length guard rejects zero'))
         ->expect($Pipe->read(0))
         ->to->be(false)
         ->assert();

      yield (new Assertion(description: 'write length guard rejects zero'))
         ->expect($Pipe->write('x', 0))
         ->to->be(false)
         ->assert();

      $opened = $Pipe->open();

      yield (new Assertion(description: 'open creates a socket pair'))
         ->expect($opened)
         ->to->be(true)
         ->assert();

      if ($opened === false) {
         return;
      }

      $message = 'bootgly-pipe';
      $written = $Pipe->write($message);
      $read = $Pipe->read(1024);
      $closed = $Pipe->close();

      yield (new Assertion(description: 'open marks the pipe as paired'))
         ->expect($Pipe->paired)
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'write returns the message length'))
         ->expect($written)
         ->to->be(strlen($message))
         ->assert();

      yield (new Assertion(description: 'read receives the written message'))
         ->expect($read)
         ->to->be($message)
         ->assert();

      yield (new Assertion(description: 'close closes both pipe ends'))
         ->expect($closed)
         ->to->be(true)
         ->assert();
   })
);
