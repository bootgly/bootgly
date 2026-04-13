<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment;


use function file_exists;
use function getenv;
use function is_string;
use function trim;


class Agent
{
   // * Data
   public readonly bool $detected;
   public readonly ?string $name;
   public readonly ?Agents $known;


   public function __construct (bool $detected, ?string $name = null)
   {
      // * Data
      $this->detected = $detected;
      $this->name = $name;
      $this->known = $name !== null ? Agents::tryFrom($name) : null;
   }

   /**
    * Detect if the current process was started by an AI agent.
    *
    * @return self
    */
   public static function detect (): self
   {
      // @ Custom agent via AI_AGENT env var
      $aiAgent = getenv('AI_AGENT');
      if (is_string($aiAgent) && trim($aiAgent) !== '') {
         return new self(true, trim($aiAgent));
      }

      // @ Known agents via specific env vars
      /** @var array<string,array<string>> */
      $agents = [
         'amp'          => ['AMP_CURRENT_THREAD_ID'],
         'antigravity'  => ['ANTIGRAVITY_AGENT'],
         'augment'      => ['AUGMENT_AGENT'],
         'claude'       => ['CLAUDECODE', 'CLAUDE_CODE'],
         'codex'        => ['CODEX_SANDBOX', 'CODEX_THREAD_ID'],
         'copilot'      => ['COPILOT_CLI'],
         'cursor'       => ['CURSOR_AGENT'],
         'gemini'       => ['GEMINI_CLI'],
         'opencode'     => ['OPENCODE_CLIENT', 'OPENCODE'],
         'replit'       => ['REPL_ID'],
      ];

      foreach ($agents as $agent => $variables) {
         foreach ($variables as $variable) {
            if (getenv($variable) !== false) {
               return new self(true, $agent);
            }
         }
      }

      // @ Devin via filesystem sentinel
      if (file_exists('/opt/.devin')) {
         return new self(true, 'devin');
      }

      return new self(false);
   }
}
