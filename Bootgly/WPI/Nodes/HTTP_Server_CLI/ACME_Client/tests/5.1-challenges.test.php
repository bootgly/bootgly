<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Challenges;

return new Specification(
   description: 'ACME Challenges: token file round-trip and path-traversal proofing',
   test: function () {
      $path = sys_get_temp_dir() . '/bootgly-acme-challenges-' . getmypid() . '/';
      $other = sys_get_temp_dir() . '/bootgly-acme-challenges-other-' . getmypid() . '/';

      try {
         // @ Disabled by default
         Challenges::configure(null);

         yield assert(
            assertion: Challenges::save('token', 'auth') === false
               && Challenges::load('token') === null,
            description: 'a null path keeps every operation disabled'
         );

         // @ Round-trip
         Challenges::configure($path);

         yield assert(
            assertion: Challenges::save('a-Token_123', 'a-Token_123.thumbprint') === true,
            description: 'save() persists a key authorization'
         );
         yield assert(
            assertion: Challenges::load('a-Token_123') === 'a-Token_123.thumbprint',
            description: 'load() returns the exact key authorization'
         );
         yield assert(
            assertion: Challenges::load('unknown-token') === null,
            description: 'an unknown token loads as null'
         );
         yield assert(
            assertion: Challenges::drop('a-Token_123') === true
               && Challenges::load('a-Token_123') === null,
            description: 'drop() removes the token file'
         );

         // @ Path-traversal proofing — invalid tokens never touch the fs
         yield assert(
            assertion: Challenges::load('../etc/passwd') === null
               && Challenges::load('.') === null
               && Challenges::load('') === null
               && Challenges::save('a/b', 'x') === false
               && Challenges::save(str_repeat('a', 257), 'x') === false,
            description: 'non-base64url tokens are rejected before any filesystem access'
         );
         yield assert(
            assertion: Challenges::save('empty-authorization', '') === false
               && Challenges::save('oversized-authorization', str_repeat('a', 8193)) === false,
            description: 'challenge authorizations are bounded to 1-8192 bytes'
         );

         // @ Final-component safety — atomic rename replaces a planted token
         //   symlink itself; neither save nor load follows its outside target.
         $victim = sys_get_temp_dir() . '/bootgly-acme-challenge-victim-' . getmypid();
         file_put_contents($victim, 'keep');
         symlink($victim, "{$path}planted-token");
         $saved = Challenges::save('planted-token', 'authorization');

         yield assert(
            assertion: $saved
               && file_get_contents($victim) === 'keep'
               && is_link("{$path}planted-token") === false
               && Challenges::load('planted-token') === 'authorization',
            description: 'a planted final token symlink is replaced, never followed'
         );
         Challenges::drop('planted-token');
         unlink($victim);

         // @ Directory normalization — callers need not supply a trailing slash.
         Challenges::configure(rtrim($path, '/'));
         $adjacent = rtrim($path, '/') . 'adjacent-token';
         yield assert(
            assertion: Challenges::save('adjacent-token', 'inside')
               && is_file("{$path}adjacent-token")
               && is_file($adjacent) === false,
            description: 'a challenge path without a trailing slash still writes inside its directory'
         );
         Challenges::drop('adjacent-token');

         // @ Multiple server objects own independent charters. An issuer must
         //   choose its exact spool, responders see both, and releasing one
         //   lease never disables its sibling.
         Challenges::configure(null);
         Challenges::charter('server-a', $path);
         Challenges::charter('server-b', $other);
         yield assert(
            assertion: Challenges::save('ambiguous', 'x') === false
               && Challenges::save('token-a', 'authorization-a', $path)
               && Challenges::save('token-b', 'authorization-b', $other)
               && Challenges::load('token-a') === 'authorization-a'
               && Challenges::load('token-b') === 'authorization-b',
            description: 'two instance-owned challenge spools coexist without an ambiguous writer'
         );
         Challenges::release('server-a');
         yield assert(
            assertion: Challenges::load('token-a') === null
               && Challenges::load('token-b') === 'authorization-b',
            description: 'releasing one challenge charter preserves the sibling responder'
         );
         Challenges::drop('token-a', $path);
         Challenges::drop('token-b', $other);
      }
      finally {
         Challenges::configure(null);
         @unlink(sys_get_temp_dir() . '/bootgly-acme-challenge-victim-' . getmypid());
         @unlink(rtrim($path, '/') . 'adjacent-token');
         if (is_dir($path)) {
            foreach (glob("{$path}*") ?: [] as $file) {
               unlink($file);
            }
            rmdir($path);
         }
         if (is_dir($other)) {
            foreach (glob("{$other}*") ?: [] as $file) {
               unlink($file);
            }
            rmdir($other);
         }
      }
   }
);
