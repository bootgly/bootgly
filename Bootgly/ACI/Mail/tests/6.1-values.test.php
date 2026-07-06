<?php

use Bootgly\ACI\Mail\Exceptioning;
use Bootgly\ACI\Mail\Exceptions\AuthenticationException;
use Bootgly\ACI\Mail\Exceptions\ConnectionException;
use Bootgly\ACI\Mail\Exceptions\CryptoException;
use Bootgly\ACI\Mail\Exceptions\PermanentException;
use Bootgly\ACI\Mail\Exceptions\ProtocolException;
use Bootgly\ACI\Mail\Exceptions\TransientException;
use Bootgly\ACI\Mail\Receipt;
use Bootgly\ACI\Mail\Reply;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Mail\Reply, Mail\Receipt and exception contract: value integrity',
   test: function () {
      // @ Reply
      $Reply = new Reply(250, '2.1.0', ['2.1.0 Sender ok', '2.1.0 Continue']);

      yield assert(
         assertion: $Reply->code === 250,
         description: 'Reply keeps the SMTP code'
      );
      yield assert(
         assertion: $Reply->status === '2.1.0',
         description: 'Reply keeps the enhanced status'
      );
      yield assert(
         assertion: $Reply->lines === ['2.1.0 Sender ok', '2.1.0 Continue'],
         description: 'Reply keeps the raw lines (status prefix preserved)'
      );
      yield assert(
         assertion: $Reply->text === '2.1.0 Sender ok 2.1.0 Continue',
         description: 'Reply->text joins all lines with a space'
      );

      $caught = false;
      try {
         // @phpstan-ignore-next-line assign.propertyReadOnly
         $Reply->text = 'tampered';
      }
      catch (Error) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'Reply->text is a virtual read-only property (write throws Error)'
      );

      $Reply = new Reply(220, '', ['smtp.example.com ESMTP ready']);
      yield assert(
         assertion: $Reply->status === '' && $Reply->text === 'smtp.example.com ESMTP ready',
         description: 'Reply without enhanced status keeps status empty'
      );

      // @ Receipt
      $Receipt = new Receipt(
         code: 250,
         status: '2.0.0',
         reply: '2.0.0 OK: queued as ABC123',
         recipients: ['user@example.net', 'other@example.net'],
         size: 1024
      );

      yield assert(
         assertion: $Receipt->code === 250,
         description: 'Receipt keeps the final reply code'
      );
      yield assert(
         assertion: $Receipt->status === '2.0.0',
         description: 'Receipt keeps the enhanced status'
      );
      yield assert(
         assertion: $Receipt->reply === '2.0.0 OK: queued as ABC123',
         description: 'Receipt keeps the server confirmation text'
      );
      yield assert(
         assertion: $Receipt->recipients === ['user@example.net', 'other@example.net'],
         description: 'Receipt keeps the accepted envelope recipients'
      );
      yield assert(
         assertion: $Receipt->size === 1024,
         description: 'Receipt keeps the transmitted DATA size'
      );

      // @ Exception contract — `Exceptioning` is the Mail catch-all marker
      $Exceptions = [
         new AuthenticationException('x'),
         new ConnectionException('x'),
         new CryptoException('x'),
         new PermanentException('x', 550, '5.1.1'),
         new ProtocolException('x'),
         new TransientException('x', 450, '4.2.0')
      ];
      $marked = true;
      foreach ($Exceptions as $Exception) {
         if ($Exception instanceof Exceptioning === false) {
            $marked = false;
         }
      }
      yield assert(
         assertion: $marked,
         description: 'every concrete Mail exception implements the Exceptioning catch-all'
      );
      yield assert(
         assertion: $Exceptions[3] instanceof PermanentException && $Exceptions[3]->status === '5.1.1'
            && $Exceptions[5] instanceof TransientException && $Exceptions[5]->status === '4.2.0',
         description: 'Permanent and Transient exceptions carry the enhanced status'
      );
   }
);
