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
use function explode;
use function gmmktime;
use function preg_match;
use function print_r;
use function str_contains;
use function substr;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Signer;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * The authority of an S3 endpoint — the wire `Host`, the host the signature covers and the
 * origin of a presigned URL. The port is left out only when it is the default of the
 * endpoint's own scheme: `http` on 443 or `https` on 80 keeps it, or the URL handed to a
 * client points at another port than the one the disk talks to.
 */
return new Test(
   description: 'Storage(S3) offline: the endpoint port is dropped only when it is the default of its scheme',
   test: function () {
      $presign = static function (string $endpoint): string {
         $S3 = new S3('', [
            'bucket' => 'my-bucket',
            'key' => 'AKIAEXAMPLE',
            'secret' => 'secret',
            'endpoint' => $endpoint,
            'insecure' => true,
         ]);

         return (string) $S3->presign('GET', 'a.txt', 60);
      };
      $origin = static fn (string $endpoint): string => explode('/my-bucket/', $presign($endpoint))[0];

      // # The default port of the scheme is implicit
      yield assert(
         assertion: $origin('https://store.internal') === 'https://store.internal'
            && $origin('https://store.internal:443') === 'https://store.internal'
            && $origin('http://store.internal') === 'http://store.internal'
            && $origin('http://store.internal:80') === 'http://store.internal',
         description: 'https on 443 and http on 80 carry no port, written or not'
      );

      // # Any other port is part of the authority
      yield assert(
         assertion: $origin('https://store.internal:9000') === 'https://store.internal:9000'
            && $origin('http://store.internal:8080') === 'http://store.internal:8080',
         description: 'a port that is neither 80 nor 443 is kept'
      );

      // # The other scheme's default is not this scheme's default
      yield assert(
         assertion: $origin('http://store.internal:443') === 'http://store.internal:443'
            && $origin('https://store.internal:80') === 'https://store.internal:80',
         description: 'http on 443 and https on 80 keep the port: the URL points where the disk connects'
      );

      // # The signature covers that authority
      $URL = $presign('http://store.internal:443');
      $matched = preg_match('#^http://store\.internal:443(/my-bucket/a\.txt)\?(.+&X-Amz-Date=(\d{4})(\d\d)(\d\d)T(\d\d)(\d\d)(\d\d)Z&.+)$#D', $URL, $matches) === 1;
      $time = $matched === true
         ? (int) gmmktime((int) $matches[6], (int) $matches[7], (int) $matches[8], (int) $matches[4], (int) $matches[5], (int) $matches[3])
         : 0;
      $Signer = new Signer('AKIAEXAMPLE', 'secret');
      yield assert(
         assertion: $matched === true
            && $Signer->presign('GET', 'store.internal:443', $matches[1], [], 60, time: $time) === $matches[2]
            && $Signer->presign('GET', 'store.internal', $matches[1], [], 60, time: $time) !== $matches[2],
         description: 'the presigned URL is signed for the host with its port, not for the bare host'
      );

      // # A dump shows the same endpoint
      $Disk = new S3('', ['bucket' => 'my-bucket', 'endpoint' => 'http://store.internal:443', 'insecure' => true]);
      yield assert(
         assertion: str_contains(print_r($Disk, true), '[endpoint] => http://store.internal:443') === true
            && substr($origin('https://STORE.internal:444'), 0, 27) === 'https://store.internal:444',
         description: 'the dump of the disk names the endpoint with its port; the host is lowercased as before'
      );
   }
);
