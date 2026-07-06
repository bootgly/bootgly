<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment;


enum Agents: string
{
   case Amp = 'amp';
   case Antigravity = 'antigravity';
   case Augment = 'augment';
   case Claude = 'claude';
   case Codex = 'codex';
   case Copilot = 'copilot';
   case Cursor = 'cursor';
   case Devin = 'devin';
   case Gemini = 'gemini';
   case Opencode = 'opencode';
   case Replit = 'replit';
}
