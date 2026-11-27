<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\DNS\Tests;


use function array_map;
use function assert;
use function file_get_contents;
use function hex2bin;
use function str_replace;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\Data\DNS\Message;
use Bootgly\ABI\Data\DNS\Message\Question;
use Bootgly\ABI\Data\DNS\Message\Record;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `DNS::decode()` — real answers, captured once from a local stub resolver
 * and from a public resolver (`fixtures/*.txt`, hex). No network at run time.
 * The expected values were written by an independent parser, not by this
 * codec. Decode only: a stub's TC answer drops its records, so it is not
 * re-encoded here (1.3 and the next assertion check the re-encoding).
 */

return new Test(
   description: 'DNS decodes captured real answers to their independently written fields',
   test: function () {
      $expected = [
      'stub-nxdomain.answer' => [
         'header' => ['id' => 42546, 'response' => true, 'opcode' => 0, 'authoritative' => true, 'truncated' => false, 'recursion' => true, 'recursive' => true, 'authentic' => false, 'unchecked' => false, 'rcode' => 3, 'size' => 65494],
         'Questions' => [['bootgly-5c.invalid', 1, 1]],
         'Answers' => [],
         'Authorities' => [],
         'Additionals' => [],
      ],
      'stub-tc.answer' => [
         'header' => ['id' => 58045, 'response' => true, 'opcode' => 0, 'authoritative' => false, 'truncated' => true, 'recursion' => true, 'recursive' => true, 'authentic' => false, 'unchecked' => false, 'rcode' => 0, 'size' => null],
         'Questions' => [['google.com', 16, 1]],
         'Answers' => [],
         'Authorities' => [],
         'Additionals' => [],
      ],
      'stub-txt.answer' => [
         'header' => ['id' => 750, 'response' => true, 'opcode' => 0, 'authoritative' => false, 'truncated' => false, 'recursion' => true, 'recursive' => true, 'authentic' => false, 'unchecked' => false, 'rcode' => 0, 'size' => 65494],
         'Questions' => [['google.com', 16, 1]],
         'Answers' => [
            ['google.com', 16, 1, 119, ['globalsign-smime-dv=CDYX+XFHUw2wml6/Gb8+59BsH31KzUr6c1l2BPvqKX8=']],
            ['google.com', 16, 1, 119, ['google-site-verification=4ibFUgB-wXLQ_S7vsXVomSTVamuOXBiVAzpR5IZ87D0']],
            ['google.com', 16, 1, 119, ['_r4rd1pvwyrpi7sw4a3hzmw8e51yh9td']],
            ['google.com', 16, 1, 119, ['v=spf1 include:_spf.google.com ~all']],
            ['google.com', 16, 1, 119, ['arcules-domain-verification=2R4t5G7nI6tRCo61lpxxbLksPYstxhGmtV75cpr8x9K']],
            ['google.com', 16, 1, 119, ['google-site-verification=wD8N7i1JTNTkezJ49swvWW48f8_9xveREV4oB-0Hf5o']],
            ['google.com', 16, 1, 119, ['google-site-verification=TV9-DBe4R80X4v0M4U_bd_J9cpOJM0nikft0jAgjmsQ']],
            ['google.com', 16, 1, 119, ['cisco-ci-domain-verification=47c38bc8c4b74b7233e9053220c1bbe76bcc1cd33c7acf7acd36cd6a5332004b']],
            ['google.com', 16, 1, 119, ['apple-domain-verification=30afIBcvSuDV2PLX']],
            ['google.com', 16, 1, 119, ['MS=E4A68B9AB2BB9670BCE15412F62916164C0B20BB']],
            ['google.com', 16, 1, 119, ['docusign=1b0a6754-49b1-4db5-8540-d2c12664b289']],
            ['google.com', 16, 1, 119, ['onetrust-domain-verification=6d685f1d41a94696ad7ef771f68993e0']],
            ['google.com', 16, 1, 119, ['docusign=05958488-4752-4ef2-95eb-aa7ba8a3bd0e']],
            ['google.com', 16, 1, 119, ['onetrust-domain-verification=0d477fe608074e6f9c12bca7826035cc']],
            ['google.com', 16, 1, 119, ['work-accounts-domain-verification=Tcj6JjIMZOw2KsSEw2Nt2rLae89tN6']],
            ['google.com', 16, 1, 119, ['facebook-domain-verification=22rm551cu4k0ab0bxsw536tlds4h95']],
            ['google.com', 16, 1, 119, ['Z29vZ2xl']],
         ],
         'Authorities' => [],
         'Additionals' => [],
      ],
      'upstream-a.answer' => [
         'header' => ['id' => 6805, 'response' => true, 'opcode' => 0, 'authoritative' => false, 'truncated' => false, 'recursion' => true, 'recursive' => true, 'authentic' => false, 'unchecked' => false, 'rcode' => 0, 'size' => 1232],
         'Questions' => [['example.com', 1, 1]],
         'Answers' => [
            ['example.com', 1, 1, 297, '172.66.147.243'],
            ['example.com', 1, 1, 297, '104.20.23.154'],
         ],
         'Authorities' => [],
         'Additionals' => [],
      ],
      'upstream-a.query' => [
         'header' => ['id' => 6805, 'response' => false, 'opcode' => 0, 'authoritative' => false, 'truncated' => false, 'recursion' => true, 'recursive' => false, 'authentic' => false, 'unchecked' => false, 'rcode' => 0, 'size' => 1232],
         'Questions' => [['example.com', 1, 1]],
         'Answers' => [],
         'Authorities' => [],
         'Additionals' => [],
      ],
      'upstream-aaaa.answer' => [
         'header' => ['id' => 21418, 'response' => true, 'opcode' => 0, 'authoritative' => false, 'truncated' => false, 'recursion' => true, 'recursive' => true, 'authentic' => false, 'unchecked' => false, 'rcode' => 0, 'size' => 1232],
         'Questions' => [['example.com', 28, 1]],
         'Answers' => [
            ['example.com', 28, 1, 51, '2606:4700:10::6814:179a'],
            ['example.com', 28, 1, 51, '2606:4700:10::ac42:93f3'],
         ],
         'Authorities' => [],
         'Additionals' => [],
      ],
      'upstream-caa.answer' => [
         'header' => ['id' => 36262, 'response' => true, 'opcode' => 0, 'authoritative' => false, 'truncated' => false, 'recursion' => true, 'recursive' => true, 'authentic' => false, 'unchecked' => false, 'rcode' => 0, 'size' => 1232],
         'Questions' => [['google.com', 257, 1]],
         'Answers' => [
            ['google.com', 257, 1, 86400, ['flags' => 0, 'tag' => 'issue', 'value' => 'pki.goog']],
         ],
         'Authorities' => [],
         'Additionals' => [],
      ],
      'upstream-cname.answer' => [
         'header' => ['id' => 36069, 'response' => true, 'opcode' => 0, 'authoritative' => false, 'truncated' => false, 'recursion' => true, 'recursive' => true, 'authentic' => false, 'unchecked' => false, 'rcode' => 0, 'size' => 1232],
         'Questions' => [['www.github.com', 1, 1]],
         'Answers' => [
            ['www.github.com', 5, 1, 3528, 'github.com'],
            ['github.com', 1, 1, 48, '4.228.31.150'],
         ],
         'Authorities' => [],
         'Additionals' => [],
      ],
      'upstream-mx.answer' => [
         'header' => ['id' => 9193, 'response' => true, 'opcode' => 0, 'authoritative' => false, 'truncated' => false, 'recursion' => true, 'recursive' => true, 'authentic' => false, 'unchecked' => false, 'rcode' => 0, 'size' => 1232],
         'Questions' => [['gmail.com', 15, 1]],
         'Answers' => [
            ['gmail.com', 15, 1, 1168, ['preference' => 30, 'exchange' => 'alt3.gmail-smtp-in.l.google.com']],
            ['gmail.com', 15, 1, 1168, ['preference' => 5, 'exchange' => 'gmail-smtp-in.l.google.com']],
            ['gmail.com', 15, 1, 1168, ['preference' => 10, 'exchange' => 'alt1.gmail-smtp-in.l.google.com']],
            ['gmail.com', 15, 1, 1168, ['preference' => 20, 'exchange' => 'alt2.gmail-smtp-in.l.google.com']],
            ['gmail.com', 15, 1, 1168, ['preference' => 40, 'exchange' => 'alt4.gmail-smtp-in.l.google.com']],
         ],
         'Authorities' => [],
         'Additionals' => [],
      ],
      'upstream-nodata-soa.answer' => [
         'header' => ['id' => 5111, 'response' => true, 'opcode' => 0, 'authoritative' => false, 'truncated' => false, 'recursion' => true, 'recursive' => true, 'authentic' => false, 'unchecked' => false, 'rcode' => 0, 'size' => 1232],
         'Questions' => [['bootgly-5c-nonexistent.example.com', 1, 1]],
         'Answers' => [],
         'Authorities' => [
            ['example.com', 6, 1, 1791, ['mname' => 'elliott.ns.cloudflare.com', 'rname' => 'dns.cloudflare.com', 'serial' => 2421050422, 'refresh' => 10000, 'retry' => 2400, 'expire' => 604800, 'minimum' => 1800]],
         ],
         'Additionals' => [],
      ],
      'upstream-null-mx.answer' => [
         'header' => ['id' => 16594, 'response' => true, 'opcode' => 0, 'authoritative' => false, 'truncated' => false, 'recursion' => true, 'recursive' => true, 'authentic' => false, 'unchecked' => false, 'rcode' => 0, 'size' => 1232],
         'Questions' => [['example.com', 15, 1]],
         'Answers' => [
            ['example.com', 15, 1, 300, ['preference' => 0, 'exchange' => '']],
         ],
         'Authorities' => [],
         'Additionals' => [],
      ],
      'upstream-nxdomain.answer' => [
         'header' => ['id' => 3426, 'response' => true, 'opcode' => 0, 'authoritative' => false, 'truncated' => false, 'recursion' => true, 'recursive' => true, 'authentic' => false, 'unchecked' => false, 'rcode' => 3, 'size' => 1232],
         'Questions' => [['bootgly-5c.invalid', 1, 1]],
         'Answers' => [],
         'Authorities' => [
            ['', 6, 1, 86400, ['mname' => 'a.root-servers.net', 'rname' => 'nstld.verisign-grs.com', 'serial' => 2026112601, 'refresh' => 1800, 'retry' => 900, 'expire' => 604800, 'minimum' => 86400]],
         ],
         'Additionals' => [],
      ],
      'upstream-soa.answer' => [
         'header' => ['id' => 54832, 'response' => true, 'opcode' => 0, 'authoritative' => false, 'truncated' => false, 'recursion' => true, 'recursive' => true, 'authentic' => false, 'unchecked' => false, 'rcode' => 0, 'size' => 1232],
         'Questions' => [['example.com', 6, 1]],
         'Answers' => [
            ['example.com', 6, 1, 1800, ['mname' => 'elliott.ns.cloudflare.com', 'rname' => 'dns.cloudflare.com', 'serial' => 2421050422, 'refresh' => 10000, 'retry' => 2400, 'expire' => 604800, 'minimum' => 1800]],
         ],
         'Authorities' => [],
         'Additionals' => [],
      ],
      'upstream-srv.answer' => [
         'header' => ['id' => 13922, 'response' => true, 'opcode' => 0, 'authoritative' => false, 'truncated' => false, 'recursion' => true, 'recursive' => true, 'authentic' => false, 'unchecked' => false, 'rcode' => 0, 'size' => 1232],
         'Questions' => [['_imaps._tcp.gmail.com', 33, 1]],
         'Answers' => [
            ['_imaps._tcp.gmail.com', 33, 1, 86400, ['priority' => 5, 'weight' => 0, 'port' => 993, 'target' => 'imap.gmail.com']],
         ],
         'Authorities' => [],
         'Additionals' => [],
      ],
      'upstream-tc.answer' => [
         'header' => ['id' => 5840, 'response' => true, 'opcode' => 0, 'authoritative' => false, 'truncated' => true, 'recursion' => true, 'recursive' => true, 'authentic' => false, 'unchecked' => false, 'rcode' => 0, 'size' => null],
         'Questions' => [['google.com', 16, 1]],
         'Answers' => [],
         'Authorities' => [],
         'Additionals' => [],
      ],
      'upstream-txt.answer' => [
         'header' => ['id' => 32143, 'response' => true, 'opcode' => 0, 'authoritative' => false, 'truncated' => false, 'recursion' => true, 'recursive' => true, 'authentic' => false, 'unchecked' => false, 'rcode' => 0, 'size' => 1232],
         'Questions' => [['google.com', 16, 1]],
         'Answers' => [
            ['google.com', 16, 1, 119, ['globalsign-smime-dv=CDYX+XFHUw2wml6/Gb8+59BsH31KzUr6c1l2BPvqKX8=']],
            ['google.com', 16, 1, 119, ['docusign=1b0a6754-49b1-4db5-8540-d2c12664b289']],
            ['google.com', 16, 1, 119, ['facebook-domain-verification=22rm551cu4k0ab0bxsw536tlds4h95']],
            ['google.com', 16, 1, 119, ['MS=E4A68B9AB2BB9670BCE15412F62916164C0B20BB']],
            ['google.com', 16, 1, 119, ['arcules-domain-verification=2R4t5G7nI6tRCo61lpxxbLksPYstxhGmtV75cpr8x9K']],
            ['google.com', 16, 1, 119, ['v=spf1 include:_spf.google.com ~all']],
            ['google.com', 16, 1, 119, ['_r4rd1pvwyrpi7sw4a3hzmw8e51yh9td']],
            ['google.com', 16, 1, 119, ['docusign=05958488-4752-4ef2-95eb-aa7ba8a3bd0e']],
            ['google.com', 16, 1, 119, ['Z29vZ2xl']],
            ['google.com', 16, 1, 119, ['google-site-verification=TV9-DBe4R80X4v0M4U_bd_J9cpOJM0nikft0jAgjmsQ']],
            ['google.com', 16, 1, 119, ['onetrust-domain-verification=0d477fe608074e6f9c12bca7826035cc']],
            ['google.com', 16, 1, 119, ['cisco-ci-domain-verification=47c38bc8c4b74b7233e9053220c1bbe76bcc1cd33c7acf7acd36cd6a5332004b']],
            ['google.com', 16, 1, 119, ['apple-domain-verification=30afIBcvSuDV2PLX']],
            ['google.com', 16, 1, 119, ['google-site-verification=wD8N7i1JTNTkezJ49swvWW48f8_9xveREV4oB-0Hf5o']],
            ['google.com', 16, 1, 119, ['work-accounts-domain-verification=Tcj6JjIMZOw2KsSEw2Nt2rLae89tN6']],
            ['google.com', 16, 1, 119, ['onetrust-domain-verification=6d685f1d41a94696ad7ef771f68993e0']],
            ['google.com', 16, 1, 119, ['google-site-verification=4ibFUgB-wXLQ_S7vsXVomSTVamuOXBiVAzpR5IZ87D0']],
         ],
         'Authorities' => [],
         'Additionals' => [],
      ],
      ];

      foreach ($expected as $fixture => $fields) {
         $bytes = (string) hex2bin(str_replace("\n", '', (string) file_get_contents(__DIR__ . "/fixtures/{$fixture}.txt")));
         $Message = DNS::decode($bytes);

         $read = null;
         if ($Message instanceof Message) {
            $records = fn (array $Records): array => array_map(
               fn (Record $Record): array => [$Record->name, $Record->type, $Record->class, $Record->TTL, $Record->data],
               $Records
            );
            $read = [
               'header' => [
                  'id' => $Message->id, 'response' => $Message->response, 'opcode' => $Message->opcode,
                  'authoritative' => $Message->authoritative, 'truncated' => $Message->truncated,
                  'recursion' => $Message->recursion, 'recursive' => $Message->recursive,
                  'authentic' => $Message->authentic, 'unchecked' => $Message->unchecked,
                  'rcode' => $Message->rcode, 'size' => $Message->size
               ],
               'Questions' => array_map(
                  fn (Question $Question): array => [$Question->name, $Question->type, $Question->class],
                  $Message->Questions
               ),
               'Answers' => $records($Message->Answers),
               'Authorities' => $records($Message->Authorities),
               'Additionals' => $records($Message->Additionals)
            ];
         }

         yield assert(
            assertion: $read === $fields,
            description: "{$fixture}: every field as captured"
         );

         // @ A complete answer re-encodes to the bytes the server sent
         if ($Message instanceof Message && $Message->truncated === false) {
            yield assert(
               assertion: DNS::encode($Message) === $bytes,
               description: "{$fixture}: re-encodes to the captured bytes"
            );
         }
      }
   }
);
