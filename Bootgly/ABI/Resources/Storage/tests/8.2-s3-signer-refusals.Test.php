<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage;


use function assert;
use function ini_get;
use function ini_set;
use function json_encode;
use function str_contains;
use function str_repeat;
use Closure;
use InvalidArgumentException;
use Throwable;

use Bootgly\ABI\Resources\Storage\Drivers\S3\Signer;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * What `S3\Signer` refuses to sign. Every field of a canonical request is one line of it:
 * a line break, a `?` in the path or an authentication parameter slipped into the query
 * would let one request be signed as another, so each is refused with an exception —
 * before any HMAC — and none of the messages carries the secret.
 */
return new Test(
   description: 'Storage(S3) Signer: refuses what is not canonical, never leaks the secret',
   test: function () {
      $secret = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
      $Signer = new Signer('AKIAIOSFODNN7EXAMPLE', $secret);
      $host = 'bucket.s3.amazonaws.com';
      $payload = Signer::UNSIGNED;
      $time = 1369353600;

      // ! The message of the InvalidArgumentException a call throws; '' when it does not throw
      $refuse = static function (Closure $Call): string {
         try {
            $Call();
         }
         catch (InvalidArgumentException $Exception) {
            return $Exception->getMessage();
         }

         return '';
      };

      // # presign(): the lifetime is bounded
      $lifetime = 'S3 Signer: a presigned URL expires in 1..604800 seconds.';
      yield assert(
         assertion: $refuse(static fn () => $Signer->presign('GET', $host, '/k', [], 0, $time)) === $lifetime
            && $refuse(static fn () => $Signer->presign('GET', $host, '/k', [], -1, $time)) === $lifetime
            && $refuse(static fn () => $Signer->presign('GET', $host, '/k', [], 604801, $time)) === $lifetime,
         description: 'presign() refuses a lifetime of 0, a negative one and one over 7 days'
      );
      yield assert(
         assertion: $refuse(static fn () => $Signer->presign('GET', $host, '/k', [], 1, $time)) === ''
            && $refuse(static fn () => $Signer->presign('GET', $host, '/k', [], Signer::LIFETIME, $time)) === '',
         description: 'presign() accepts both ends of the range: 1 second and 604800'
      );

      // # presign(): the authentication parameters belong to the signer
      $reserved = 'S3 Signer: the X-Amz-* authentication parameters cannot be supplied.';
      $refused = true;
      foreach ([
         'X-Amz-Expires', 'x-amz-expires', 'X-AMZ-EXPIRES', 'X-Amz-Date', 'X-Amz-Credential', 'X-Amz-Algorithm',
         'X-Amz-SignedHeaders', 'x-amz-signedheaders', 'X-Amz-Signature', 'X-Amz-Security-Token',
      ] as $name) {
         $refused = $refused && $refuse(static fn () => $Signer->presign('GET', $host, '/k', [$name => '1'], 60, $time)) === $reserved;
      }
      yield assert(
         assertion: $refused,
         description: 'presign() refuses every X-Amz-* authentication parameter in the query, in any letter case'
      );

      // # The request line
      $method = 'S3 Signer: the method is an uppercase HTTP method.';
      yield assert(
         assertion: $refuse(static fn () => $Signer->presign('get', $host, '/k', [], 60, $time)) === $method
            && $refuse(static fn () => $Signer->presign('', $host, '/k', [], 60, $time)) === $method
            && $refuse(static fn () => $Signer->presign("GET\n/other", $host, '/k', [], 60, $time)) === $method
            && $refuse(static fn () => $Signer->sign("PUT\n", $host, '/k', [], [], $payload, $time)) === $method,
         description: 'a method that is not uppercase letters — lowercase, empty, carrying a line break — is refused'
      );
      $wire = 'S3 Signer: the host is the wire Host, without spaces or control characters.';
      yield assert(
         assertion: $refuse(static fn () => $Signer->presign('GET', '', '/k', [], 60, $time)) === $wire
            && $refuse(static fn () => $Signer->presign('GET', "a.example\nx-amz-acl:public-read", '/k', [], 60, $time)) === $wire
            && $refuse(static fn () => $Signer->sign('GET', 'a.example evil.example', '/k', [], [], $payload, $time)) === $wire
            && $refuse(static fn () => $Signer->sign('GET', "a.example\r", '/k', [], [], $payload, $time)) === $wire,
         description: 'a host that is empty or holds a space, a CR or a line break is refused'
      );
      $encoded = 'S3 Signer: the path is percent-encoded and starts with "/".';
      yield assert(
         assertion: $refuse(static fn () => $Signer->presign('GET', $host, '', [], 60, $time)) === $encoded
            && $refuse(static fn () => $Signer->presign('GET', $host, 'k', [], 60, $time)) === $encoded
            && $refuse(static fn () => $Signer->presign('GET', $host, '/k?acl', [], 60, $time)) === $encoded
            && $refuse(static fn () => $Signer->presign('GET', $host, '/k#fragment', [], 60, $time)) === $encoded
            && $refuse(static fn () => $Signer->presign('GET', $host, '/a b', [], 60, $time)) === $encoded
            && $refuse(static fn () => $Signer->presign('GET', $host, "/k\n", [], 60, $time)) === $encoded
            && $refuse(static fn () => $Signer->sign('DELETE', $host, "/k\n/other", [], [], $payload, $time)) === $encoded
            && $refuse(static fn () => $Signer->sign('GET', $host, '/é', [], [], $payload, $time)) === $encoded,
         description: 'a path that is relative, carries a query, a fragment, a raw space, a line break or a raw non-ASCII byte is refused'
      );

      // # sign(): the payload hash and the headers
      yield assert(
         assertion: $refuse(static fn () => $Signer->sign('GET', $host, '/k', [], [], '', $time)) === 'S3 Signer: the payload is a hex SHA-256 or UNSIGNED-PAYLOAD.'
            && $refuse(static fn () => $Signer->sign('GET', $host, '/k', [], [], "{$payload}\nx", $time)) === 'S3 Signer: the payload is a hex SHA-256 or UNSIGNED-PAYLOAD.',
         description: 'sign() refuses an empty payload hash and one carrying a line break'
      );
      $header = 'S3 Signer: a signed header has an illegal name or a control character in its value.';
      yield assert(
         assertion: $refuse(static fn () => $Signer->sign('GET', $host, '/k', [], ['x-amz-meta-a' => "b\r\nx-amz-acl: public-read"], $payload, $time)) === $header
            && $refuse(static fn () => $Signer->sign('GET', $host, '/k', [], ['x-amz-meta-a' => "b\nc"], $payload, $time)) === $header
            && $refuse(static fn () => $Signer->sign('GET', $host, '/k', [], ['x-amz-meta-a' => "b\x00"], $payload, $time)) === $header
            && $refuse(static fn () => $Signer->sign('GET', $host, '/k', [], ["x-amz-acl:public-read\nx" => 'v'], $payload, $time)) === $header
            && $refuse(static fn () => $Signer->sign('GET', $host, '/k', [], ['bad name' => 'v'], $payload, $time)) === $header
            && $refuse(static fn () => $Signer->sign('GET', $host, '/k', [], ['' => 'v'], $payload, $time)) === $header,
         description: 'sign() refuses a header whose value holds CR, LF or NUL, or whose name is not a token'
      );

      // # The constructor: what reaches the wire is printable, with no space
      $printable = 'S3 Signer: the key, region, service and token must be printable ASCII without spaces.';
      yield assert(
         assertion: $refuse(static fn () => new Signer('AKIA EXAMPLE', $secret)) === $printable
            && $refuse(static fn () => new Signer("AKIA\nEXAMPLE", $secret)) === $printable
            && $refuse(static fn () => new Signer('AKIA', $secret, "us-east-1\n")) === $printable
            && $refuse(static fn () => new Signer('AKIA', $secret, 'us-east-1', 's3/aws4_request x')) === $printable
            && $refuse(static fn () => new Signer('AKIA', $secret, 'us-east-1', 's3', "token\r\nx-amz-acl: public-read")) === $printable,
         description: 'the constructor refuses a key, region, service or token with a space or a control character'
      );
      yield assert(
         assertion: $refuse(static fn () => new Signer('', '')) === ''
            && $refuse(static fn () => new Signer('AKIA', "any \n bytes \x00 at all")) === ''
            && (new Signer('AKIA', 'one'))->presign('GET', $host, '/k', [], 60, $time)
               !== (new Signer('AKIA', 'two'))->presign('GET', $host, '/k', [], 60, $time),
         description: 'credentials are opaque: empty ones and any secret bytes are accepted, and the secret decides the signature'
      );

      // # The secret never leaves the signer
      $leaked = false;
      $ignored = ini_get('zend.exception_ignore_args');
      ini_set('zend.exception_ignore_args', '0');
      try {
         foreach ([
            static fn () => new Signer('AKIA EXAMPLE', $secret, 'us-east-1', 's3', "session-{$secret}"),
            static fn () => $Signer->presign('GET', $host, '/k', [], 0, $time),
            static fn () => $Signer->presign('GET', $host, '/k?x', ['X-Amz-Date' => '1'], 60, $time),
            static fn () => $Signer->sign('GET', $host, '/k', [], ['a' => "b\n"], $payload, $time),
         ] as $Call) {
            try {
               $Call();
            }
            catch (Throwable $Throwable) {
               $leaked = $leaked
                  || str_contains($Throwable->getMessage(), $secret) === true
                  || str_contains($Throwable->getTraceAsString(), $secret) === true
                  || str_contains((string) json_encode($Throwable->getTrace()), 'bPxRfiCYEXAMPLEKEY') === true;
            }
         }
      }
      finally {
         ini_set('zend.exception_ignore_args', (string) $ignored);
      }
      $signed = $Signer->sign('GET', $host, '/k', [], [], $payload, $time);
      $URL = $Signer->presign('GET', $host, '/k', [], 60, $time);
      yield assert(
         assertion: $leaked === false
            && str_contains(json_encode($signed) . $URL, $secret) === false
            && str_contains(json_encode($signed) . $URL, 'bPxRfiCYEXAMPLEKEY') === false,
         description: 'no exception message, no stack trace argument and no output carries the secret or the token'
      );

      // # A long query value is signed, not truncated
      $long = str_repeat('a', 4096);
      yield assert(
         assertion: str_contains($Signer->presign('GET', $host, '/k', ['response-content-disposition' => $long], 60, $time), "&response-content-disposition={$long}&X-Amz-Signature=") === true,
         description: 'a long query value is carried whole'
      );
   }
);
