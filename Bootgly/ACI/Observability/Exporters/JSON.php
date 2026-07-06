<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Observability\Exporters;


use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_EOL;
use function json_encode;
use JsonException;

use Bootgly\ACI\Observability\Data\Snapshot;
use Bootgly\ACI\Observability\Exporter;


class JSON implements Exporter
{
   /**
    * Encode a snapshot as one JSON document `{timestamp, metrics}` terminated by a newline.
    *
    * The newline lets snapshots be newline-framed when streamed over a pipe (worker → master).
    * Metrics are cast to an object so an empty set serializes as `{}` rather than `[]`.
    *
    * Returns an empty string on an encoding failure (e.g. non-finite floats or invalid UTF-8) so the
    * caller can refuse to overwrite a previously good snapshot — never a misleading `{}`.
    *
    * @param Snapshot $Snapshot The snapshot to encode.
    * @return string A single-line JSON document, or '' on encoding failure.
    */
   public function export (Snapshot $Snapshot): string
   {
      // @ Encode (fail loud — caller treats '' as "do not overwrite")
      try {
         $json = json_encode([
            'timestamp' => $Snapshot->timestamp,
            'metrics'   => (object) $Snapshot->metrics,
         ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
      }
      catch (JsonException) {
         return '';
      }

      // :
      return $json . PHP_EOL;
   }
}
