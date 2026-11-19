<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Unit;


use const PHP_INT_MAX;
use function assert;
use function chr;
use function count;
use function debug_zval_dump;
use function implode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function sort;
use function str_contains;
use function strlen;
use function substr;
use function var_dump;
use Closure;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;


/**
 * An HTTP/2 connection decoder holds the request bytes it has not resolved
 * yet: the partial frame it carries between reads and the header block
 * fragments it accumulates until CONTINUATION ends them — the `Authorization`
 * and `Cookie` of a request among them. Its HPACK codec keeps every field a
 * client indexed in its dynamic table. A debug dump of the decoder or of its
 * HPACK codec shows none of them, while the frames the decoder queued and the
 * codec's limit still show — and the dump never changes what either holds.
 */
return new Test(
   description: 'HTTP/2 decoder: debug dumps mask the carried request bytes, the header block fragments and the HPACK dynamic table',
   test: function () {
      // ! SYNTH credentials — never real ones
      $indexed = 'SYNTH-HTTP2-DECODER-INDEXED-BEARER-1.87';
      $remembered = 'SYNTH-HTTP2-DECODER-INDEXED-COOKIE-1.87';
      $fragment = 'SYNTH-HTTP2-DECODER-FRAGMENT-COOKIE-1.87';
      $carried = 'SYNTH-HTTP2-DECODER-CARRIED-BEARER-1.87';
      $allow = 'GET, VISIBLE-ALLOW-1.87';
      $path = '/visible-path-1.87';
      $Dumper = new Dumper('plain');
      // ! No limit may hide a leak
      $Dumper->depth = 32;
      $Dumper->strings = 1_000_000;
      $Dumper->items = 10_000;
      // ! Each debug channel
      $Channels = [
         'var_dump' => static function (object $Object): string {
            ob_start();
            var_dump($Object);

            return (string) ob_get_clean();
         },
         'print_r' => static fn (object $Object): string => print_r($Object, true),
         'debug_zval_dump' => static function (object $Object): string {
            ob_start();
            debug_zval_dump($Object);

            return (string) ob_get_clean();
         },
         'Dumper' => static fn (object $Object): string => $Dumper->dump($Object),
      ];

      // ! A connection decoder, built as the server builds one
      $Decoder = new Decoder_HTTP2;
      // ! An earlier request indexed its credentials (RFC 7541 §6.2.1, static
      //   names `authorization` = 23 and `cookie` = 32): the codec decodes the
      //   block and keeps both fields in its dynamic table
      $Indexed = static fn (int $index, string $value): string => chr(0x40 | $index) . chr(strlen($value)) . $value;
      $decoded = $Decoder->HPACK->decode(
         $Indexed(23, "Bearer {$indexed}") . $Indexed(32, "sid={$remembered}"),
         PHP_INT_MAX
      );
      // ! A canned answer queued for a refused stream: the frames still show
      $Decoder->deny(5, 405, [['allow', $allow]]);
      // ! A request on stream 7 mid-head: its HEADERS fragment awaits
      //   CONTINUATION, and that CONTINUATION arrived only in part. `decode()`
      //   reaches this state only through a live transport, so the state is
      //   set as the frame loop leaves it
      $head = HPACK::encode([
         [':method', 'GET'],
         [':scheme', 'https'],
         [':authority', 'origin.test'],
         [':path', $path],
         ['cookie', "sid={$fragment}"]
      ]);
      $tail = HPACK::encode([['accept', '*/*']]);
      $continuation = Frame::pack(
         HTTP2::FRAME_CONTINUATION, HTTP2::FLAG_END_HEADERS, 7,
         HPACK::encode([['authorization', "Bearer {$carried}"]]) . $tail
      );
      $Decoder->buffer = substr($continuation, 0, -strlen($tail));
      Closure::bind(static function (Decoder_HTTP2 $Decoder, string $head): void {
         $Decoder->expected = 7;
         $Decoder->fragments = $head;
      }, null, Decoder_HTTP2::class)($Decoder, $head);
      // ! The protected state, read as the decoder and its codec hold it
      $Fragments = Closure::bind(static fn (Decoder_HTTP2 $Decoder): string => $Decoder->fragments, null, Decoder_HTTP2::class);
      $Table = Closure::bind(static fn (HPACK $HPACK): array => $HPACK->dynamic, null, HPACK::class);
      $carry = $Decoder->buffer;
      $outbox = $Decoder->outbox;
      $fragments = $Fragments($Decoder);
      $table = $Table($Decoder->HPACK);

      // ? Every credential is live before any dump — never a vacuous pass
      yield assert(
         assertion: $decoded === [['authorization', "Bearer {$indexed}"], ['cookie', "sid={$remembered}"]]
            && count($table) === 2
            && str_contains($fragments, "sid={$fragment}") === true
            && str_contains($carry, "Bearer {$carried}") === true
            && str_contains($outbox, $allow) === true,
         description: 'The decoder holds every credential live: in its dynamic table, its fragments and its carried partial frame'
      );

      // ! The holders, what each must mask and what each must still show
      $Holders = [
         'Decoder_HTTP2' => [$Decoder, [$indexed, $remembered, $fragment, $carried], [$allow]],
         'HTTP2\HPACK' => [$Decoder->HPACK, [$indexed, $remembered], ["{$Decoder->HPACK->limit}"]],
      ];

      // @@ Every holder through every channel
      $leaks = [];
      $missing = [];
      foreach ($Holders as $holder => [$Holder, $secrets, $shown]) {
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Holder);

            foreach ($secrets as $secret) {
               if (str_contains($output, $secret) === true) {
                  $leaks[] = "{$secret} in {$holder} via {$channel}";
               }
            }
            foreach ([...$shown, Secret::MASK] as $expected) {
               if (str_contains($output, $expected) === false) {
                  $missing[] = "{$expected} in {$holder} via {$channel}";
               }
            }
         }
      }
      $leaked = implode(', ', $leaks);
      $absent = implode(', ', $missing);
      yield assert(
         assertion: $leaks === [] && $missing === [],
         description: "No carried request byte, header block fragment nor indexed field reaches var_dump, print_r, debug_zval_dump or Dumper, while the queued frames and the codec limit show (leaked: [{$leaked}], missing: [{$absent}])"
      );

      // @ Dumping never writes
      yield assert(
         assertion: $Decoder->buffer === $carry
            && $Decoder->outbox === $outbox
            && $Fragments($Decoder) === $fragments
            && $Table($Decoder->HPACK) === $table,
         description: 'The live carry, fragments, queued frames and dynamic table are untouched by the dumps'
      );

      // @ The one way: the trait's __debugInfo, #[Secret] on the stored properties only
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $expected = [
         'Decoder_HTTP2' => ['buffer', 'fragments'],
         'HTTP2\HPACK' => ['dynamic'],
      ];
      $deviations = [];
      foreach ($Holders as $holder => [$Holder]) {
         $Own = new ReflectionMethod($Holder, '__debugInfo');
         if (
            $Own->getFileName() !== $Trait->getFileName()
            || $Own->getStartLine() !== $Trait->getStartLine()
         ) {
            $deviations[] = "{$holder} dumps through its own __debugInfo";
         }

         $marked = [];
         foreach (new ReflectionClass($Holder)->getProperties() as $Property) {
            if ($Property->getAttributes(Secret::class) === []) {
               continue;
            }

            $marked[] = $Property->name;
            if ($Property->isStatic() === true || $Property->isVirtual() === true) {
               $deviations[] = "{$holder}::\${$Property->name} is static or virtual";
            }
         }
         sort($marked);
         if ($marked !== $expected[$holder]) {
            $listed = implode(', ', $marked);
            $deviations[] = "{$holder} marks [{$listed}]";
         }
      }
      $deviated = implode('; ', $deviations);
      yield assert(
         assertion: $deviations === [],
         description: "The decoder and its codec dump through the Redactable trait, #[Secret] on the stored properties only (deviations: [{$deviated}])"
      );
   }
);
