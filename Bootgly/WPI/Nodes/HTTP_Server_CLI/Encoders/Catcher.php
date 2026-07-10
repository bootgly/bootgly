<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders;


use const BOOTGLY_PROJECT;
use const BOOTGLY_WORKING_DIR;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use function defined;
use function htmlspecialchars;
use function is_file;
use function json_encode;
use function str_replace;
use Throwable;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Data\Language;
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\Debugging\Page;
use Bootgly\ABI\Templates\Template;
use Bootgly\API\Environments;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Modules\HTTP;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Negotiation;


/**
 * Environment-aware catcher for the HTTP dispatch pipeline.
 *
 * Turns a request throwable (or a wiring gap — no handler → 503) into the
 * response the current environment allows: byte-exact legacy bodies in Test,
 * the built-in debug page (HTML/JSON) in Development, and clean,
 * internals-free pages in Production/Staging.
 */
abstract class Catcher
{
   // * Config
   /**
    * One-shot environment override — consumed and reset by respond(), so an
    * E2E spec can exercise the Development/Production branches without
    * leaking state into the persistent test worker.
    */
   public static null|Environments $Environment = null;

   // * Data
   private const string CLEAN_PAGE = <<<'HTML'
   <!DOCTYPE html>
   <html lang="{locale}">
   <head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <meta name="robots" content="noindex, nofollow">
   <title>{status}</title>
   <style>
   body { background: #16181d; color: #d8dbe2; font: 15px/1.6 ui-sans-serif, system-ui, sans-serif;
      min-height: 100vh; display: grid; place-items: center; margin: 0; }
   main { text-align: center; padding: 2rem; }
   h1 { font-size: 3rem; font-weight: 600; margin: 0 0 .25rem; }
   p { color: #8b90a0; margin: 0; }
   </style>
   </head>
   <body>
   <main>
      <h1>{code}</h1>
      <p>{message}</p>
   </main>
   </body>
   </html>
   HTML;


   /**
    * Build the error response for the current environment.
    *
    * @param null|Request $Request The bound request (null when unavailable).
    * @param Response $Response The request-bound response (kept for context; a fresh Response is returned).
    * @param null|Throwable $Throwable The caught throwable (null on wiring gaps, e.g. no handler).
    * @param int $code The HTTP status to answer with (500 for throwables, 503 for wiring gaps).
    */
   public static function respond (
      null|Request $Request, Response $Response,
      null|Throwable $Throwable = null, int $code = 500
   ): Response
   {
      // ! Environment — one-shot override consumed here
      $Environment = self::$Environment ?? SAPI::$Environment;
      self::$Environment = null;

      // @ Report — exactly one intake per request throwable
      if ($Throwable !== null) {
         $report = ['interface' => 'WPI'];
         if ($Request !== null) {
            $report['method'] = $Request->method;
            $report['URI'] = $Request->URI;
            $report['peer'] = $Request->peer;
         }

         Throwables::notify($Throwable, $report);
      }

      // ?: Test — byte-exact legacy responses (wire-stable E2E specs)
      if ($Environment === Environments::Test) {
         return $Throwable !== null
            ? new Response(code: 500, body: ' ')
            : new Response(code: $code, body: '');
      }

      // ! Representation — HTML default; JSON when the client prefers it
      $chosen = Negotiation::choose(
         $Request !== null ? $Request->types : [],
         ['text/html', 'application/json']
      ) ?? 'text/html';

      // ?: Development — full disclosure
      if ($Environment === Environments::Development && $Throwable !== null) {
         // # JSON
         if ($chosen === 'application/json') {
            $payload = json_encode([
               'error' => $Throwable::class,
               'message' => $Throwable->getMessage(),
               'file' => Path::relativize($Throwable->getFile(), BOOTGLY_WORKING_DIR),
               'line' => $Throwable->getLine(),
               'trace' => Throwables::trace($Throwable)
            ]);

            $Errored = new Response(code: $code, body: (string) $payload);
            $Errored->Header->set('Content-Type', 'application/json');

            return $Errored;
         }

         // # HTML — built-in debug page with sanitized request context
         $context = [];
         if ($Request !== null) {
            $context['Request'] = [
               'method' => $Request->method,
               'URI' => $Request->URI,
               'protocol' => $Request->protocol,
               'peer' => $Request->peer
            ];

            $headers = $Request->headers;
            foreach (['authorization', 'cookie'] as $sensitive) {
               if (isSet($headers[$sensitive])) {
                  $headers[$sensitive] = '******';
               }
            }
            $context['Headers'] = $headers;
         }

         return new Response(code: $code, body: Page::render($Throwable, $context));
      }

      // ?: Production / Staging — never leak internals
      // ! Body message only — the wire status line keeps the English phrase
      $message = Language::translate(HTTP::RESPONSE_STATUS[$code] ?? 'Error', domain: 'errors');

      // # JSON
      if ($chosen === 'application/json') {
         $Errored = new Response(code: $code, body: (string) json_encode(['error' => $message]));
         $Errored->Header->set('Content-Type', 'application/json');
         if (Language::$roots !== []) {
            $Errored->Header->set('Vary', 'Accept-Language');
         }

         return $Errored;
      }

      // # Project custom page (views/errors/{code}.template.php)
      if (defined('BOOTGLY_PROJECT') === true) {
         $view = "errors/{$code}";
         $file = BOOTGLY_PROJECT->path . 'views/' . $view . Template::EXTENSION;

         if (is_file($file) === true) {
            $Errored = new Response;
            $Errored->View->render($view);
            if (Language::$roots !== []) {
               $Errored->Header->set('Vary', 'Accept-Language');
            }

            return $Errored->code($code);
         }
      }

      // # Built-in clean page
      // ! Escaped — catalog translations (project-supplied) flow into HTML
      $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $locale = htmlspecialchars(Language::$locale, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $body = str_replace(
         ['{locale}', '{status}', '{code}', '{message}'],
         [$locale, "$code $escaped", (string) $code, $escaped],
         self::CLEAN_PAGE
      );

      // :
      $Errored = new Response(code: $code, body: $body);
      if (Language::$roots !== []) {
         $Errored->Header->set('Vary', 'Accept-Language');
      }

      return $Errored;
   }
}
