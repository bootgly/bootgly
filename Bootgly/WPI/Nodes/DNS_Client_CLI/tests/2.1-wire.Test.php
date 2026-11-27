<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Tests\Wire;


use const STREAM_SERVER_BIND;
use function array_column;
use function array_map;
use function assert;
use function count;
use function fclose;
use function json_encode;
use function microtime;
use function str_repeat;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;

use Bootgly\ABI\Data\DNS;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\DNS_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Answer\Outcomes;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Cache;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Query;
use Bootgly\WPI\Nodes\DNS_Client_CLI\tests\fixtures\Harness;


return new Test(
   description: 'DNS_Client_CLI query(): validation, negative TTL, TC over TCP, rotation, EDNS fallback',
   test: function () {
      $Harness = Harness::start('dns-wire');
      $NS = $Harness->Nameserver;
      $DNS = new DNS_Client_CLI()->configure($Harness->configure());
      $data = static fn ($Answer): array => array_column(array_map(static fn ($R): array => [$R->data], $Answer->Records), 0);

      try {
         // @ Spoofed datagrams before the answer: never read, never cached
         $NS->script('spoof.test', DNS::TYPE_A, [
            'answers' => [['spoof.test', DNS::TYPE_A, 60, '192.0.2.1']],
            'decoys' => [
               ['spoof' => 'id', 'answers' => [['spoof.test', DNS::TYPE_A, 60, '198.51.100.66']]],
               ['spoof' => 'name', 'answers' => [['decoy.spoof.test', DNS::TYPE_A, 60, '198.51.100.67']]],
               ['spoof' => 'type', 'answers' => [['spoof.test', DNS::TYPE_AAAA, 60, '2001:db8::66']]],
               ['spoof' => 'class', 'answers' => [['spoof.test', DNS::TYPE_A, 60, '198.51.100.71']]],
               ['spoof' => 'opcode', 'answers' => [['spoof.test', DNS::TYPE_A, 60, '198.51.100.72']]],
               ['spoof' => 'response', 'answers' => [['spoof.test', DNS::TYPE_A, 60, '198.51.100.68']]],
               ['spoof' => 'size', 'answers' => [['spoof.test', DNS::TYPE_A, 60, '198.51.100.69']]],
               ['spoof' => 'source', 'answers' => [['spoof.test', DNS::TYPE_A, 60, '198.51.100.70']]],
            ],
         ]);
         $Answer = $DNS->query('Spoof.TEST.', DNS::TYPE_A);
         $Again = $DNS->query('spoof.test', DNS::TYPE_A);
         yield assert(
            assertion: $Answer->Outcome === Outcomes::Answered && $data($Answer) === ['192.0.2.1']
               && $Answer->name === 'spoof.test' && $Answer->TTL === 60
               && $data($Again) === ['192.0.2.1'] && count($NS->log()) === 1,
            description: 'decoys (wrong id, name, type, class, opcode, QR, size, source) are ignored; the real answer is cached: ' . json_encode($data($Answer))
         );

         // @ Negative answers: the SOA minimum, clamped 5–60
         $SOA = static fn (string $zone, int $TTL, int $minimum): array => [$zone, DNS::TYPE_SOA, $TTL, [
            'mname' => "ns.{$zone}", 'rname' => "admin.{$zone}", 'serial' => 1, 'refresh' => 60, 'retry' => 60, 'expire' => 60, 'minimum' => $minimum,
         ]];
         $NS->script('gone.test', DNS::TYPE_A, ['rcode' => DNS::RCODE_NXDOMAIN, 'authorities' => [$SOA('test', 300, 30)]]);
         $NS->script('empty.test', DNS::TYPE_A, ['authorities' => [$SOA('test', 300, 120)]]);
         $NS->script('bare.test', DNS::TYPE_A, ['rcode' => DNS::RCODE_NXDOMAIN]);
         $NS->script('other.test', DNS::TYPE_A, ['rcode' => DNS::RCODE_NXDOMAIN, 'authorities' => [$SOA('elsewhere.example', 300, 50)]]);
         $Gone = $DNS->query('gone.test', DNS::TYPE_A);
         $Empty = $DNS->query('empty.test', DNS::TYPE_A);
         $Bare = $DNS->query('bare.test', DNS::TYPE_A);
         $Other = $DNS->query('other.test', DNS::TYPE_A);
         yield assert(
            assertion: $Gone->Outcome === Outcomes::Absent && $Gone->TTL === 30
               && $Empty->Outcome === Outcomes::Empty && $Empty->TTL === 60
               && $Bare->Outcome === Outcomes::Absent && $Bare->TTL === 5
               && $Other->TTL === 5,
            description: 'NXDOMAIN/NODATA TTL from a covering SOA, clamped; an SOA of another zone is ignored: '
               . json_encode([$Gone->TTL, $Empty->TTL, $Bare->TTL, $Other->TTL])
         );

         // @ Truncated over UDP: the same question over TCP
         $NS->reset();
         $NS->script('big.test', DNS::TYPE_TXT, ['truncated' => true, 'tcp' => ['answers' => [['big.test', DNS::TYPE_TXT, 60, ['over tcp']]]]]);
         $Big = $DNS->query('big.test', DNS::TYPE_TXT);
         $transports = array_column($NS->log(), 'transport');
         $Cached = $DNS->query('big.test', DNS::TYPE_TXT);
         yield assert(
            assertion: $Big->Outcome === Outcomes::Answered && $data($Big) === [['over tcp']]
               && $transports === ['udp', 'tcp'] && $data($Cached) === [['over tcp']] && count($NS->log()) === 2,
            description: 'a truncated answer is asked again over TCP and the TCP answer is cached: ' . json_encode($transports)
         );

         // @ Truncated over TCP too: a failure, asked once over TCP, never the truncated answer
         $NS->reset();
         $NS->script('huge.test', DNS::TYPE_TXT, ['truncated' => true, 'tcp' => ['truncated' => true]]);
         // ! Three attempts: a retry would show as another UDP + TCP pair
         $Patient = new DNS_Client_CLI()->configure($Harness->configure(['attempts' => 3]));
         $Huge = $Patient->query('huge.test', DNS::TYPE_TXT);
         $transports = array_column($NS->log(), 'transport');
         yield assert(
            assertion: $Huge->Outcome === Outcomes::Failed && $transports === ['udp', 'tcp'],
            description: 'a truncated TCP answer fails without a retry: ' . json_encode([$Huge->Outcome->name, $transports])
         );

         // @ A TCP answer carrying an error rcode is a failure, never "no data"
         $NS->reset();
         $NS->script('err.test', DNS::TYPE_A, ['truncated' => true, 'tcp' => ['rcode' => DNS::RCODE_SERVFAIL]]);
         $Error = $DNS->query('err.test', DNS::TYPE_A);
         yield assert(
            assertion: $Error->Outcome === Outcomes::Failed,
            description: 'an error rcode over TCP fails the answer: ' . $Error->Outcome->name
         );

         // @ A TC answer whose TCP leg fails is not used (no NODATA from a truncated answer)
         $NS->reset();
         $NS->script('cut.test', DNS::TYPE_A, ['truncated' => true, 'tcp' => ['drop' => true]]);
         $Short = new DNS_Client_CLI()->configure($Harness->configure(['timeout' => 0.3]));
         $Cut = $Short->query('cut.test', DNS::TYPE_A);
         yield assert(
            assertion: $Cut->Outcome === Outcomes::Failed,
            description: 'a truncated answer is never half-used: ' . $Cut->Outcome->name
         );

         // @ SERVFAIL, then a timeout: the next tries answer
         $NS->reset();
         $Retry = new DNS_Client_CLI()->configure($Harness->configure(['nameservers' => ['127.0.0.1', '127.0.0.1'], 'timeout' => 0.3, 'attempts' => 2]));
         $NS->script('flaky.test', DNS::TYPE_A, ['rcode' => DNS::RCODE_SERVFAIL, 'times' => 1]);
         $NS->script('flaky.test', DNS::TYPE_A, ['drop' => true, 'times' => 1]);
         $NS->script('flaky.test', DNS::TYPE_A, ['answers' => [['flaky.test', DNS::TYPE_A, 60, '192.0.2.9']]]);
         $started = microtime(true);
         $Flaky = $Retry->query('flaky.test', DNS::TYPE_A);
         $elapsed = microtime(true) - $started;
         yield assert(
            assertion: $Flaky->Outcome === Outcomes::Answered && $data($Flaky) === ['192.0.2.9']
               && count($NS->log()) === 3 && $elapsed >= 0.29 && $elapsed < 1.5,
            description: "SERVFAIL and a lost answer move to the next try ({$elapsed}s, " . count($NS->log()) . ' queries)'
         );

         // @ Every try fails: Failed after attempts × nameservers queries
         $NS->reset();
         $NS->script('down.test', DNS::TYPE_A, ['rcode' => DNS::RCODE_SERVFAIL]);
         $Down = $Retry->query('down.test', DNS::TYPE_A);
         yield assert(
            assertion: $Down->Outcome === Outcomes::Failed && count($NS->log()) === 4,
            description: 'a name every try fails is Failed after 2 × 2 queries (' . count($NS->log()) . ')'
         );

         // @ FORMERR to an EDNS query: once more without EDNS
         $NS->reset();
         $EDNS = new DNS_Client_CLI()->configure($Harness->configure(['EDNS' => true]));
         $NS->script('old.test', DNS::TYPE_A, ['rcode' => DNS::RCODE_FORMERR, 'times' => 1]);
         $NS->script('old.test', DNS::TYPE_A, ['answers' => [['old.test', DNS::TYPE_A, 60, '192.0.2.10']]]);
         $Old = $EDNS->query('old.test', DNS::TYPE_A);
         $sizes = array_column($NS->log(), 'size');
         yield assert(
            assertion: $Old->Outcome === Outcomes::Answered && $sizes === [1232, null],
            description: 'FORMERR to EDNS(0) is retried without it: ' . json_encode($sizes)
         );

         // @ `rotate`: each query starts at the next nameserver (the first one here never answers)
         $NS->reset();
         $Silent = stream_socket_server("udp://127.0.0.2:{$NS->port}", $code, $message, STREAM_SERVER_BIND);
         $Rotating = new DNS_Client_CLI()->configure($Harness->configure([
            'nameservers' => ['127.0.0.2', '127.0.0.1'], 'timeout' => 0.3, 'rotate' => true,
         ]));
         $NS->script('*', DNS::TYPE_A, ['answers' => []]);
         $took = [];
         foreach (['r1.test', 'r2.test'] as $rotated) {
            $started = microtime(true);
            $Rotating->query($rotated, DNS::TYPE_A);
            $took[] = microtime(true) - $started;
         }
         if ($Silent !== false) {
            fclose($Silent);
         }
         yield assert(
            assertion: $Silent !== false && $took[0] >= 0.29 && $took[1] < 0.25,
            description: 'rotate starts the second query at the second nameserver: ' . json_encode($took)
         );

         // @ A question that cannot be encoded fails locally: nothing sent, nothing cached
         $NS->reset();
         $Cache = new Cache();
         $Unencodable = new Query(
            name: str_repeat('x', 64) . '.test', types: [DNS::TYPE_A], nameservers: ['127.0.0.1'], port: $NS->port,
            timeout: 0.3, attempts: 2, start: 0, EDNS: false, Cache: $Cache
         );
         $started = microtime(true);
         $Unencodable->execute(null, null);
         $elapsed = microtime(true) - $started;
         yield assert(
            assertion: $Unencodable->faults === [DNS::TYPE_A => 'local'] && $NS->log() === []
               && $Cache->entries === 0 && $elapsed < 0.25,
            description: 'an unencodable question is a local failure: ' . json_encode([$Unencodable->faults, $elapsed])
         );

         // @ Nobody listening (ICMP port unreachable): Failed at once, no timeout wait
         $Probe = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         $name = (string) stream_socket_get_name($Probe, false);
         fclose($Probe);
         $Closed = new DNS_Client_CLI()->configure($Harness->configure(['port' => (int) substr($name, (int) strrpos($name, ':') + 1), 'timeout' => 2]));
         $started = microtime(true);
         $Nobody = $Closed->query('nobody.test', DNS::TYPE_A);
         $elapsed = microtime(true) - $started;
         yield assert(
            assertion: $Nobody->Outcome === Outcomes::Failed && $elapsed < 1.0,
            description: "a refused port fails without waiting the timeout ({$elapsed}s)"
         );
      }
      finally {
         $Harness->stop();
      }
   }
);
