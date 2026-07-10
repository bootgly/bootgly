<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server;


use function explode;
use function str_contains;
use function str_replace;
use function strtr;


/**
 * Server-Sent Events wire encoder (WHATWG HTML — `text/event-stream`).
 *
 * Only serialization lives here: field lines are single-line by definition,
 * so CR/LF are stripped from `event`/`id` (a raw newline would forge extra
 * fields — an injection surface) and an `id` carrying U+0000 is dropped
 * entirely (clients ignore such ids per spec).
 */
final class SSE
{
   public const string TYPE = 'text/event-stream';


   /**
    * Serialize one event: optional `retry:`/`event:`/`id:` field lines,
    * one `data:` line per input line, terminated by a blank line.
    *
    * `null` data emits no `data:` lines — a field-only frame (e.g. a lone
    * `retry:`) that clients never dispatch. An empty string emits a single
    * empty `data:` line: clients dispatch one event whose data is `''`
    * (WHATWG event-stream parsing).
    */
   public static function encode (
      null|string $data, null|string $event = null, null|string $id = null, null|int $retry = null
   ): string
   {
      // ! Field lines
      $payload = '';

      // # retry (reconnection delay in milliseconds)
      if ($retry !== null && $retry > 0) {
         $payload .= "retry: {$retry}\n";
      }
      // # event (type)
      if ($event !== null && $event !== '') {
         $event = strtr($event, ["\r" => '', "\n" => '']);
         $payload .= "event: {$event}\n";
      }
      // # id (last event ID)
      if ($id !== null && $id !== '' && str_contains($id, "\0") === false) {
         $id = strtr($id, ["\r" => '', "\n" => '']);
         $payload .= "id: {$id}\n";
      }

      // @ data — one line per input line (clients rejoin them with LF);
      //   an empty string still emits its (empty) line: `data:` dispatches
      if ($data !== null) {
         $data = str_replace(["\r\n", "\r"], "\n", $data);

         foreach (explode("\n", $data) as $line) {
            $payload .= "data: {$line}\n";
         }
      }

      // : Event terminator (blank line)
      return "{$payload}\n";
   }

   /**
    * Serialize one comment line (`: <text>`) — ignored by clients;
    * the keep-alive heartbeat frame.
    */
   public static function comment (string $text = ''): string
   {
      // ? Single-line only — CR/LF would forge field lines
      $text = strtr($text, ["\r" => '', "\n" => '']);

      // : Comment line + event terminator
      return $text === '' ? ":\n\n" : ": {$text}\n\n";
   }
}
