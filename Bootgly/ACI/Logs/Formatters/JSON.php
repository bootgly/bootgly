<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Formatters;


use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_EOL;
use function json_encode;
use function preg_replace;

use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatter;


class JSON implements Formatter
{
   // ANSI escape sequence matcher (strip terminal styling for structured output).
   private const string ANSI = '/\x1b\[[0-9;?]*[ -\/]*[@-~]/';


   /**
    * Render a record as one structured JSON object terminated by a newline.
    *
    * Template tokens are rendered and ANSI styling is stripped, leaving plain text. The key
    * set and order are fixed (timestamp, level, project, instance, channel, message, context,
    * extra) and every key is always emitted: the live tap and the sink file must serialize
    * the same record to identical bytes.
    *
    * @param Record $Record The record to format.
    * @return string A single-line JSON document.
    */
   public function format (Record $Record): string
   {
      // @ Render templating + strip ANSI to plain text
      $rendered = TemplateEscaped::render($Record->message);
      $message = preg_replace(self::ANSI, '', $rendered) ?? $rendered;

      // @ Encode
      $json = json_encode([
         'timestamp' => $Record->timestamp,
         'level'     => $Record->Level->render(),
         'project'   => $Record->project,
         'instance'  => $Record->instance,
         'channel'   => $Record->channel,
         'message'   => $message,
         'context'   => $Record->context,
         'extra'     => $Record->extra,
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

      // :
      return ($json === false ? '{}' : $json) . PHP_EOL;
   }
}
