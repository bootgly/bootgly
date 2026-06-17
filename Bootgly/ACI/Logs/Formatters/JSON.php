<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
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
use Bootgly\ACI\Logs\Formatter;
use Bootgly\ACI\Logs\Data\Record;


class JSON implements Formatter
{
   // ANSI escape sequence matcher (strip terminal styling for structured output).
   private const string ANSI = '/\x1b\[[0-9;?]*[ -\/]*[@-~]/';


   /**
    * Render a record as one structured JSON object terminated by a newline.
    *
    * Template tokens are rendered and ANSI styling is stripped, leaving plain text.
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
         'channel'   => $Record->channel,
         'message'   => $message,
         'context'   => $Record->context,
         'extra'     => $Record->extra,
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

      // :
      return ($json === false ? '{}' : $json) . PHP_EOL;
   }
}
