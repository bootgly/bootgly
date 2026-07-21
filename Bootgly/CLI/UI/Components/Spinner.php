<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use const STR_PAD_LEFT;
use const STR_PAD_RIGHT;
use function count;
use function floor;
use function intdiv;
use function microtime;
use function preg_replace;
use function rewind;
use function str_pad;
use function stream_get_contents;
use function strlen;
use function strtr;
use function substr_count;
use ValueError;

use Bootgly\ABI\Code\__String;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Atoms\Text;
use Bootgly\CLI\UI\Atoms\Text\Effects;


/**
 * Indeterminate activity indicator — tick-driven (the caller loop drives `spin()`),
 * no process forking. Named animation sets, a live `(status)` segment (with an
 * auto-updating `@elapsed;` token), rotating tip lines below the spinner and an
 * optional description text effect (Shimmer wave / Fade pulse). Non-interactive
 * output renders the description once and the resolution line at the end.
 */
class Spinner extends Component
{
   private Output $Output;

   // * Config
   /** @var array<string,array<string>> Named animation sets */
   public static array $Sets = [
      'braille' => ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'],
      'star'    => ['✢', '✳', '✶', '✻', '✽', '✻', '✶', '✳'],
      'line'    => ['-', '\\', '|', '/'],
      'arc'     => ['◜', '◠', '◝', '◞', '◡', '◟'],
      'dots'    => ['⣷', '⣯', '⣟', '⡿', '⢿', '⣻', '⣽', '⣾']
   ];
   /** @var array<string> Animation frames */
   public array $frames;
   /** Named animation set — resolved from Spinner::$Sets, writes $frames */
   public string $set {
      set {
         $this->frames = self::$Sets[$value]
            ?? throw new ValueError("Unknown Spinner set: `{$value}`.");

         $this->set = $value;
      }
   }
   public float $throttle;
   /** Live status — rendered dim between parentheses (the `@elapsed;` token auto-updates) */
   public string $status;
   /** @var array<string> Rotating tip lines rendered dim below the spinner */
   public array $tips;
   /** Seconds each tip stays before rotating */
   public float $rotation;
   /** Description text effect — Effects::Shimmer (wave) or Effects::Fade (pulse) */
   public null|Effects $effect;
   // # Templating
   public string $template;

   // * Metadata
   public private(set) int $frame;
   public private(set) string $description;
   /** Description without escape sequences — the text-effect base */
   private string $plain;
   /** Painted rows of the last frame (spinner row + tip row) */
   private int $height;
   private null|Text $Text;
   // # Time
   public private(set) float $started;
   public private(set) float $rendered;
   public private(set) bool $finished;


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      $this->set = 'braille';
      $this->throttle = 0.08;
      $this->status = '';
      $this->tips = [];
      $this->rotation = 10.0;
      $this->effect = null;
      // # Templating
      $this->template = '@spinner; @description;@status;';

      // * Metadata
      $this->frame = 0;
      $this->description = '';
      $this->plain = '';
      $this->height = 1;
      $this->Text = null;
      // # Time
      $this->started = 0.0;
      $this->rendered = 0.0;
      $this->finished = false;
   }


   protected function render (int $mode = self::WRITE_OUTPUT): mixed
   {
      $this->rendered = microtime(true);

      // ! Description — optionally animated by the text effect
      $description = match ($this->effect) {
         Effects::Shimmer => $this->shimmer(),
         Effects::Fade => $this->pulse(),
         default => $this->description
      };

      // ! Status segment — dim parenthetical with the live `@elapsed;` token
      $status = '';
      if ($this->status !== '') {
         $live = strtr($this->status, ['@elapsed;' => $this->elapse()]);
         $status = ' ' . $this->paint("@#Black:({$live})@;");
      }

      // ! Templating
      $output = strtr($this->template, [
         '@spinner;' => $this->frames[$this->frame % count($this->frames)],
         '@description;' => $description,
         '@status;' => $status
      ]);

      // ! Tip row — rotates through the pool while the spinner runs
      $tip = $this->rotate();
      if ($tip !== '') {
         $output .= "\n  " . $this->paint("@#Black:└ {$tip}@;");
      }

      $output .= "\n";
      $this->height = substr_count($output, "\n");

      // ?: Frame as string
      if ($mode === self::RETURN_OUTPUT || $this->render === self::RETURN_OUTPUT) {
         return $output;
      }

      $this->Output->write($output);

      return null;
   }

   /**
    * Starts the spinner (reserves its rows and hides the cursor).
    *
    * @param string $description The activity description.
    *
    * @return void
    */
   public function start (string $description = ''): void
   {
      // ?
      if ($this->started > 0.0) {
         return;
      }

      $this->started = microtime(true);

      if ($description !== '') {
         $this->describe($description);
      }

      // ? Non-interactive output renders the description once
      if (BOOTGLY_TTY === false) {
         $this->Output->write("{$this->description}\n");

         return;
      }

      // @ Reserve the spinner rows and hide the cursor
      $this->Output->expand($this->tips === [] ? 1 : 2);
      $this->Output->Cursor->hide();

      $this->render();
   }

   /**
    * Advances the animation (throttled) — call it from the working loop.
    *
    * @return void
    */
   public function spin (): void
   {
      // ? Non-interactive output never animates
      if (BOOTGLY_TTY === false || $this->started === 0.0 || $this->finished === true) {
         return;
      }

      // ? Throttle
      if (microtime(true) - $this->rendered < $this->throttle) {
         return;
      }

      $this->frame++;

      // @ Repaint relatively (pipe-safe: no absolute cursor position involved)
      $this->Output->Cursor->up($this->height, column: 1);
      $this->Output->Text->clear(lines: $this->height);

      $this->render();
   }

   /**
    * Updates the activity description (shorter texts pad-clear the previous one).
    *
    * @param string $description The activity description.
    *
    * @return void
    */
   public function describe (string $description): void
   {
      // ?
      if ($this->description === $description) {
         return;
      }

      $length = strlen($this->description);

      if (strlen($description) < $length) {
         $description = str_pad($description, $length, ' ', STR_PAD_RIGHT);
      }

      $this->description = TemplateEscaped::render($description);
      $this->plain = (string) preg_replace(
         __String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $this->description
      );
   }

   /**
    * Finishes the spinner with a resolution line (e.g. `✔ done`).
    *
    * @param string $resolution The final line — empty keeps the last frame.
    *
    * @return void
    */
   public function finish (string $resolution = ''): void
   {
      // ?
      if ($this->finished === true || $this->started === 0.0) {
         return;
      }

      $this->finished = true;

      // ? Non-interactive output renders the resolution line only
      if (BOOTGLY_TTY === false) {
         if ($resolution !== '') {
            $this->Output->render("{$resolution}\n");
         }

         return;
      }

      // @ Replace the spinner rows with the resolution
      if ($resolution !== '') {
         $this->Output->Cursor->up($this->height, column: 1);
         $this->Output->Text->clear(lines: $this->height);
         $this->Output->render("{$resolution}\n");
      }

      $this->Output->Cursor->show();
   }

   // # Composers

   /**
    * Resolves Template markup into painted output (SGR).
    *
    * @param string $markup The content with Template markup.
    *
    * @return string
    */
   private function paint (string $markup): string
   {
      $Memory = new Output('php://memory');
      $Memory->render($markup);
      rewind($Memory->stream);

      // :
      return (string) stream_get_contents($Memory->stream);
   }

   /**
    * Composes the shimmered description — the Text atom wave at the spinner frame.
    *
    * @return string
    */
   private function shimmer (): string
   {
      $Text = $this->Text ??= new Text($this->Output);
      $Text->content = $this->plain;

      // :
      return $this->paint($Text->shimmer($this->frame));
   }

   /**
    * Composes the pulsed description — a dim → plain → bold breathing cycle.
    *
    * @return string
    */
   private function pulse (): string
   {
      // :
      return match (intdiv($this->frame, 4) % 3) {
         0 => $this->paint("@#Black:{$this->plain}@;"),
         1 => $this->plain,
         default => $this->paint("@*:{$this->plain}@;")
      };
   }

   /**
    * Formats the elapsed time since start (`47s`, `2m 07s`).
    *
    * @return string
    */
   private function elapse (): string
   {
      $seconds = (int) (microtime(true) - $this->started);

      // ?:
      if ($seconds < 60) {
         return "{$seconds}s";
      }

      $minutes = intdiv($seconds, 60);
      $rest = str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);

      // :
      return "{$minutes}m {$rest}s";
   }

   /**
    * Rotates the tip pool by elapsed time — the current tip line.
    *
    * @return string
    */
   private function rotate (): string
   {
      // ?:
      if ($this->tips === []) {
         return '';
      }

      $index = $this->rotation > 0.0
         ? (int) floor((microtime(true) - $this->started) / $this->rotation)
         : 0;

      // :
      return $this->tips[$index % count($this->tips)];
   }

   public function __destruct ()
   {
      $this->finish();
   }
}
