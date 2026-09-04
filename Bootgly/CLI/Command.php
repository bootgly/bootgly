<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


use const PHP_EOL;
use function array_merge;
use function implode;
use function in_array;
use function ltrim;
use function max;
use function preg_replace;
use function rtrim;
use function str_pad;
use function str_replace;
use function strlen;
use function strtok;
use Closure;

use const Bootgly\CLI;
use Bootgly\ABI\Code\__String\Path;
use Bootgly\CLI\UI\Base\Fieldset;
use Bootgly\CLI\UI\Components\Alert;
use Bootgly\CLI\UI\Components\Textbox;


abstract class Command
{
   // * Config
   // # Signature
   /** @var array<string> */
   public array $arguments = [];

   /** @var array<string,array<string>> */
   public array $options = [
      // Global options
      'Increase the verbosity of the command' => ['-v', '-vv', '-vvv'],
      'Show help information' => ['--help', '-h'],
      // Local options
      // ...
   ];
   // # Display
   public bool $separate;
   public int $group;
   public int $verbosity = 0;
   // # Runtime
   public null|object $context;
   public null|string $input = null;

   // * Data
   // # Display
   public string $description;
   // # Signature
   public string $name;
   // # Runtime
   protected null|Closure $Command = null;

   // * Metadata
   public string $script {
      get => $this->script ?? '';
      set => $this->script ??= $value;
   }


   /**
    * Define a new command instance.
    *
    * @param null|string $name The name of the command.
    * @param null|string $description The description of the command.
    * @param null|array<string> $arguments The arguments of the command.
    * @param null|array<string,array<string>> $options The options of the command.
    * @param null|object $context The context of the command.
    * @param null|Closure $Command The command to run.
    */
   public function __construct
   (
      // * Config
      null|string $name = null,
      null|string $description = null,
      null|array $arguments = null,
      null|array $options = null,

      null|object $context = null,

      // * Data
      null|Closure $Command = null,
   )
   {
      // * Config
      $this->name = $name ?? $this->name;
      $this->description = $description ?? $this->description;
      $this->arguments = $arguments ?? $this->arguments;
      $this->options = array_merge($this->options, $options ?? []);

      $this->context = $context;
      // * Data
      $this->Command = $Command;

      // @
      if ($context !== null) {
         $this($context);
      }

      if ($Command !== null) {
         $this->Command = $Command->bindTo($this, $this);
      }
   }
   /**
    * Set the context of the command.
    *
    * @param null|object $context The context of the command.
    *
    * @return void
    */
   public function __invoke (null|object $context = null): void
   {
      if ($context !== null) {
         $input = $this->input; // pipe the input to the context
         $Closure = function (Closure $Callback)
         use ($context, $input) {
            $Callback = $Callback->bindTo($context, $context);
            $Callback($input);
         };

         $this->context = $Closure;
      }
   }

   /**
    * Clean text that came from outside — a tag annotation, a path, a remote
    * name, the caller's own argument — before it enters a rendered line or
    * the JSON document.
    *
    * Control characters, C0 and C1 alike, would drive the terminal (title,
    * colours, erased lines); an `@` that could open or close Output markup —
    * one not followed by a letter or a digit (`@#`, `@;`, `@.`, `@:`, `@@`,
    * `@*`, `@\\`), or one right after `*`, `~`, `_`, `-` (the closers) — would
    * drive it and goes; a plain `@` between word characters, legal in a path
    * and in a ref, stays; a byte that is not UTF-8 would make the JSON
    * encoder throw.
    * Line breaks go too, unless the text is a multi-line note.
    *
    * @param string $text
    * @param bool $breaks Keep line feeds.
    *
    * @return string
    */
   protected function clean (string $text, bool $breaks = false): string
   {
      // ! C0, C1, and the zero-width / bidi format characters that disguise a path
      $invisible = '\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}';
      $controls = $breaks
         ? "/[\\x00-\\x09\\x0B-\\x1F\\x7F\\x{80}-\\x{9F}{$invisible}]/u"
         : "/[\\x00-\\x1F\\x7F\\x{80}-\\x{9F}{$invisible}]/u";
      $cleaned = preg_replace($controls, '', $text);
      // ? Not UTF-8 at all: keep printable ASCII (and the break) only
      if ($cleaned === null) {
         $cleaned = preg_replace($breaks ? '/[^\x0A\x20-\x7E]/' : '/[^\x20-\x7E]/', '?', $text) ?? '';
      }

      // @@ To a FIXED POINT: one pass is not closed under its own deletions —
      //   in `*@@`, dropping the first `@` leaves the second one preceded by
      //   `*`, which is the reset directive. Each pass shortens the text or
      //   ends the loop.
      do {
         $previous = $cleaned;
         $cleaned = preg_replace('/(?<=[*~_-])@|@(?![\p{L}\p{N}])/u', '', $cleaned)
            ?? str_replace('@', '', $cleaned);
      }
      while ($cleaned !== $previous);

      // :
      return $cleaned;
   }


   /**
    * Refuse an option this subcommand does not implement.
    *
    * The parser accepts any `--flag` (`CLI/Commands/Arguments.php`) and a
    * command's option table only renders help, so an inapplicable flag used to
    * be taken and silently dropped: `--dry-run` — the seeder's flag — once
    * made `projects create` write the project for real while the caller read
    * the run as a preview, and the create that followed was then refused for a
    * name the preview had consumed. Naming where the flag does apply (when the
    * command declares it elsewhere) keeps the refusal actionable.
    *
    * @param array<int,string> $accepted
    * @param array<string,bool|int|string> $options
    */
   protected function admit (array $accepted, array $options): bool
   {
      // ! The global flags every command carries are always admitted.
      $accepted = [...$accepted, 'help', 'h', 'v'];

      foreach ($options as $option => $value) {
         $option = (string) $option;

         if (in_array($option, $accepted, true) === true) {
            continue;
         }

         // ! Where the flag DOES apply, when this command declares it elsewhere.
         $applies = '';
         foreach ($this->options as $description => $flags) {
            foreach ($flags as $flag) {
               if (ltrim((string) strtok($flag, '='), '-') === $option) {
                  $applies = $description;

                  break 2;
               }
            }
         }

         $Output = CLI->Terminal->Output;

         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = 'Unknown option @#cyan:--' . $this->clean($option) . '@; for this command.';
         $Alert->render();

         // ! The Alert clips a long line, so the actionable half gets its own —
         //   knowing the flag is refused is loud, knowing where it applies is
         //   what lets the caller fix the command.
         if ($applies !== '') {
            $Output->render('@#Green:Note:@; @#Blue:--' . $this->clean($option) . "@; is: {$applies}.@.;");
         }

         return false;
      }

      return true;
   }

   /**
    * Confirm one destructive CLI action.
    */
   protected function confirm (string $question, bool $default = false): bool
   {
      $Terminal = CLI->Terminal;

      $Textbox = new Textbox($Terminal->Input, $Terminal->Output);

      return $Textbox->confirm($question, default: $default);
   }

   /**
    * Render this command's own help — its name, description and options.
    *
    * This is the standardized default triggered by the global `--help`/`-h`
    * option (dispatched centrally in `Commands::route()`). Commands that ship
    * a richer, argument-aware help override this method.
    *
    * @param array<string> $arguments The subcommand path (unused by the default).
    *
    * @return bool
    */
   public function help (array $arguments = []): bool
   {
      // !
      $Output = CLI->Terminal->Output;

      $Output->write(PHP_EOL);

      // @
      // # Header
      $Fieldset = new Fieldset($Output);
      $Fieldset->title = "@#Cyan: {$this->name} @;";
      $Fieldset->content = $this->description;
      $Fieldset->render();

      // # Options
      // * Metadata
      $width = 0;
      foreach ($this->options as $flags) {
         $width = max($width, strlen(implode(', ', $flags)));
      }
      // @@
      $content = '';
      foreach ($this->options as $description => $flags) {
         $joined = implode(', ', $flags);
         $padding = str_pad('', $width - strlen($joined));
         $content .= "@#Yellow:{$joined}@;{$padding}  {$description}" . PHP_EOL;
      }
      $content = rtrim($content);

      $Fieldset = new Fieldset($Output);
      $Fieldset->title = '@#Green: Commands options @;';
      $Fieldset->content = $content;
      $Fieldset->render();

      // # Usage
      $script = $this->script;
      $script = match ($script[0] ?? '') {
         '/'     => new Path($script)->current,
         '.'     => $script,
         default => "php {$script}",
      };
      $Fieldset = new Fieldset($Output);
      $Fieldset->title = '@#Green: Commands usage @;';
      $Fieldset->content = "{$script} {$this->name} @#Black: [arguments] [...options] @;";
      $Fieldset->render();

      // :
      return true;
   }

   /**
    * Run the command with the given arguments and options.
    *
    * @param array<string> $arguments The arguments passed to the command.
    * @param array<string,bool|int|string> $options The options passed to the command.
    *
    * @return bool True if the command was successful, false otherwise.
    */
   abstract public function run (array $arguments = [], array $options = []): bool;
}
