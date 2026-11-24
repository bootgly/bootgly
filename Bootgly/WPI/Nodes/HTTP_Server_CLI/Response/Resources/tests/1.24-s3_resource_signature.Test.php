<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\S3;


use const T_OBJECT_OPERATOR;
use const T_STRING;
use const T_VARIABLE;
use const T_WHITESPACE;
use function assert;
use function count;
use function file_get_contents;
use function in_array;
use function is_array;
use function json_encode;
use function str_contains;
use function strtolower;
use function substr_count;
use function token_get_all;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ABI\Resources\Storage\Drivers\S3 as Driver;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Writer;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\S3;


require_once __DIR__ . '/s3.php';


/**
 * The resource signs nothing of its own: every request it sends is the one the S3 driver of the
 * same disk would sign — byte for byte, at a fixed clock — composed by the disk's `S3\Writer`,
 * and the `Host` it sends is the authority that was signed.
 */
return new Test(
   description: 'Resources: the S3 resource sends the canonical requests of the S3 driver, composed by the disk Writer, with the signed Host',
   test: function () {
      $Rig = mount();

      try {
         // ! The disks of the driver's signature pin (6.2), at its clocks
         $disks = [
            'aws' => ['', [
               'bucket' => 'examplebucket',
               'region' => 'us-east-1',
               'key' => 'AKIAIOSFODNN7EXAMPLE',
               'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            ]],
            'compatible' => ['media/library', [
               'bucket' => 'videos',
               'region' => 'auto',
               'key' => 'R2TESTACCESSKEYEXAMPLE',
               'secret' => 'r2/test+secret/EXAMPLEKEY0123456789abcd',
               'token' => 'FQoGZXIvYXdzEXAMPLETOKEN',
               'endpoint' => 'http://127.0.0.1:9000',
               'insecure' => true,
            ]],
         ];
         // ! [disk, method, path, query, body, extra headers, time]
         $requests = [
            'get' => ['aws', 'GET', '/test.txt', [], '', [], 1369353600],
            'put-type-meta' => ['aws', 'PUT', '/a%20b/c%2Bd.txt', [], 'Welcome to Amazon S3.', [
               'Content-Type' => 'text/plain',
               'x-amz-meta-owner' => 'rodrigo',
               'x-amz-meta-b' => '2',
            ], 1369353600],
            'range' => ['aws', 'GET', '/test.txt', [], '', ['Range' => 'bytes=0-9'], 1369353600],
            'create' => ['compatible', 'POST', '/videos/media/library/big.bin', ['uploads' => ''], '', [
               'Content-Type' => 'video/mp4',
               'x-amz-meta-title' => 'Clip',
            ], 1794141000],
            'part' => ['compatible', 'PUT', '/videos/media/library/big.bin', [
               'partNumber' => '3',
               'uploadId' => 'AbC~1/2+3==',
            ], 'PARTBYTES', [], 1794141000],
            'complete' => ['compatible', 'POST', '/videos/media/library/big.bin', [
               'uploadId' => 'AbC~1/2+3==',
            ], '<CompleteMultipartUpload></CompleteMultipartUpload>', ['Content-Type' => 'application/xml'], 1794141000],
            'abort' => ['compatible', 'DELETE', '/videos/media/library/big.bin', ['uploadId' => 'AbC~1/2+3=='], '', [], 1794227400],
            'head' => ['compatible', 'HEAD', '/videos/media/library/a%20b.txt', [], '', [], 1794227400],
         ];

         $Drivers = [];
         $Resources = [];
         foreach ($disks as $name => [$root, $options]) {
            $Drivers[$name] = new Driver($root, $options);
            $Resources[$name] = new S3(new Writer($root, $options));
         }
         $Signs = new ReflectionMethod(Driver::class, 'sign');
         $Composes = new ReflectionMethod(S3::class, 'sign');

         $differ = [];
         $hosts = [];
         foreach ($requests as $label => [$disk, $method, $path, $query, $body, $extra, $time]) {
            $expected = $Signs->invoke($Drivers[$disk], $method, $path, $query, $body, $extra, $time);
            $sent = $Composes->invoke($Resources[$disk], $method, $path, $query, $body, $extra, $time);
            $host = is_array($sent) ? ($sent[1]['Host'] ?? null) : null;
            $hosts[$label] = $host;
            unset($sent[1]['Host']);
            if ($sent !== $expected) {
               $differ[] = $label;
            }
         }
         yield assert(
            assertion: $differ === []
               && in_array(null, $hosts, true) === false
               && $hosts['get'] === 'examplebucket.s3.us-east-1.amazonaws.com'
               && $hosts['part'] === '127.0.0.1:9000',
            description: 'Every canonical request — request target and headers in wire order — is the driver\'s, plus the signed Host, differing: ' . json_encode([$differ, $hosts])
         );

         // # The Host on the wire is the signed authority, also where the port is not the scheme's
         $wire = [];
         foreach (['http://127.0.0.1:443', 'https://127.0.0.1:80', 'http://127.0.0.1:80'] as $endpoint) {
            $Writer = new Writer('', ['endpoint' => $endpoint] + $disks['compatible'][1]);
            [$URI, $headers] = $Writer->compose('GET', '/videos/a.txt', [], '', [], 1794227400);
            $raw = '';
            foreach ($headers as $field => $value) {
               $raw .= "{$field}: {$value}\r\n";
            }
            $encoded = Encoder_::encode('GET', $URI, 'HTTP/1.1', $raw, '', $Writer->host, $Writer->port);
            $wire[$endpoint] = [$Writer->authority, substr_count(strtolower($encoded), "\r\nhost: "), str_contains($encoded, "\r\nHost: {$Writer->authority}\r\n")];
         }
         yield assert(
            assertion: $wire === [
               'http://127.0.0.1:443' => ['127.0.0.1:443', 1, true],
               'https://127.0.0.1:80' => ['127.0.0.1:80', 1, true],
               'http://127.0.0.1:80' => ['127.0.0.1', 1, true],
            ],
            description: 'The client sends the signed Host once and as signed, a port that is not the scheme\'s included, found: ' . json_encode($wire)
         );

         // # Source pin: no signing, canonical form, XML or validation of its own
         $File = (string) (new ReflectionClass(S3::class))->getFileName();
         $Tokens = token_get_all((string) file_get_contents($File));
         $forbidden = ['hash', 'hash_hmac', 'hash_init', 'hash_update', 'hash_final', 'md5', 'base64_encode', 'rawurlencode', 'urlencode', 'http_build_query', 'gmdate', 'ksort', 'simplexml_load_string', 'preg_match', 'preg_replace', 'preg_split', 'Signer', 'XMLReader', 'DOMDocument'];
         $found = [];
         $composes = 0;
         $indexed = 0;
         $count = count($Tokens);
         for ($i = 0; $i < $count; $i++) {
            $Token = $Tokens[$i];
            if (is_array($Token) === false) {
               continue;
            }
            if ($Token[0] === T_STRING && in_array($Token[1], $forbidden, true)) {
               $found[] = $Token[1];
            }
            // @ `->compose(`: the one source of what is sent
            if ($Token[0] === T_OBJECT_OPERATOR) {
               $next = $Tokens[$i + 1] ?? null;
               if (is_array($next) && $next[0] === T_STRING && $next[1] === 'compose') {
                  $composes++;
               }
            }
            // @ `$headers[`: the signed headers are never edited
            if ($Token[0] === T_VARIABLE && $Token[1] === '$headers') {
               $j = $i + 1;
               while (is_array($Tokens[$j] ?? null) && $Tokens[$j][0] === T_WHITESPACE) {
                  $j++;
               }
               if (($Tokens[$j] ?? null) === '[') {
                  $indexed++;
               }
            }
         }
         yield assert(
            assertion: $found === [] && $composes === 1 && $indexed === 0,
            description: 'The resource composes every request through the Writer, once, and never signs, encodes, parses or edits one itself, found: ' . json_encode(['forbidden' => $found, 'compose' => $composes, 'edited' => $indexed])
         );
      }
      finally {
         ($Rig->restore)();
      }
   }
);
