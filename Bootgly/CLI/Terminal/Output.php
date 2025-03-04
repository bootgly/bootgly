<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal;


use const PHP_EOL;
use const STDOUT;
use const STR_PAD_RIGHT;
use function escapeshellcmd;
use function fopen;
use function fwrite;
use function json_encode;
use function str_split;
use function usleep;
use Throwable;
use Bootgly\ABI\Data\__String;
use Bootgly\ABI\Data\__String\Escapeable;
use Bootgly\ABI\Data\__String\Escapeable\Cursor\Positionable;
use Bootgly\ABI\Data\__String\Escapeable\Text\Modifiable;
use Bootgly\ABI\Templates\Template\Escaped as TemplateEscaped;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output\Cursor;
use Bootgly\CLI\Terminal\Output\Text;
use Bootgly\CLI\Terminal\Output\Viewport;


class Output
{
   use Escapeable;
   use Positionable;
   use Modifiable;


   // * Config
   // # Stream
   /** @var resource */
   public $stream;
   // # Delay
   public int $wait;
   public int $waiting;

   // * Data
   public string $text;

   // * Metadata
   // # Stats
   public int|false $written;

   public Cursor $Cursor;
   public Text $Text;
   public Viewport $Viewport;


   /**
    * @param resource|string $stream
    */
   public function __construct ($stream = STDOUT)
   {
      // * Config
      // # Stream
      if (is_resource($stream) === true) {
         $this->stream = $stream;
      }
      // # Delay
      $this->wait = -1;       // to write method
      $this->waiting = 30000; // to writing method

      // * Data
      $this->text = '';

      // * Metadata
      // # Stats
      $this->written = 0;

      // @
      $this->Cursor = new Cursor($this);
      $this->Text = new Text($this);
      $this->Viewport = new Viewport($this);

      if ($stream !== STDOUT && is_string($stream) === true) {
         $pointer = @fopen($stream, 'r+');

         if ($pointer !== false) {
            $this->stream = $pointer;
         }
      }
   }

   /**
    * Resets the Output instance by re-initializing it with default values.
    *
    * @return void
    */
   public function reset (): void
   {
      $this->__construct();
   }
   /**
    * Clears the terminal screen by moving the cursor to the top-left corner and erasing all content.
    *
    * @return true Always returns true.
    */
   public function clear (): true
   {
      $this->write(
         self::_START_ESCAPE . self::_CURSOR_POSITION .
         self::_START_ESCAPE . self::_TEXT_ERASE_IN_DISPLAY
      );

      return true;
   }
   /**
    * Expands the terminal output by the specified number of lines.
    *
    * This method checks if the given number of lines is greater than zero. If not, it returns the current instance without any changes.
    * If the given number of lines is greater than zero, it calculates the final row position by adding the given lines to the current cursor position.
    * If the final row position is less than the terminal height, it returns the current instance without any changes.
    * If the final row position is greater than or equal to the terminal height, it pans down the viewport by the given number of lines and moves the cursor up by the same number of lines.
    *
    * @param int $lines The number of lines to expand the terminal output.
    *
    * @return self The current instance.
    */
   public function expand (int $lines): self
   {
      if ($lines <= 0) {
         return $this;
      }

      // # Cursor
      // position
      $row = (int) $this->Cursor->position['row'];
      $final = $row + $lines;
      if ($final < Terminal::$height) {
         return $this;
      }

      $this->Viewport->panDown($lines);

      $this->Cursor->up($lines);

      return $this;
   }

   // # Raw
   /**
    * Writes the given data to the output stream.
    *
    * This method writes the provided data to the output stream, repeating the operation the specified number of times.
    * If the delay between writes is configured, it introduces a delay between each write operation.
    *
    * @param string $data The data to be written to the output stream.
    * @param int $times The number of times the data should be written to the output stream. Default is 1.
    *
    * @return self The current instance.
    */
   public function write (string $data, int $times = 1): self
   {
      // * Config
      $stream = &$this->stream;
      // # Delay
      $wait = $this->wait;

      // * Data
      // ...

      // @@
      do {
         try {
            $this->written = @fwrite($stream, $data);
         }
         catch (Throwable) {
            $this->written = false;
         }

         if ($wait > 0) {
            usleep($wait);
         }

         $times--;
      } while ($times > 0);

      return $this;
   }
   /**
    * Writes the given data to the output stream in parts, introducing a delay between each write operation.
    *
    * This method splits the provided data into individual parts and writes each part to the output stream.
    * If the delay between writes is configured, it introduces a delay between each write operation.
    * The total number of bytes written is tracked and stored in the 'written' property.
    *
    * @param string $data The data to be written to the output stream.
    *
    * @return self The current instance.
    */
   public function writing (string $data): self
   {
      // * Config
      $stream = $this->stream;
      // # Delay
      $waiting = $this->waiting;

      // * Data
      // ...

      // * Metadata
      $written = 0;


      $parts = str_split($data);
      foreach ($parts as $part) {
         try {
            $written += @fwrite($stream, $part);
         } catch (Throwable) {
            $written += false;
         }

         if ($waiting > 0) {
            usleep($waiting);
         }
      }

      $this->written += $written;

      return $this;
   }

   /**
    * Appends a string to the output stream.
    *
    * This function writes the provided data concatenated with a newline
    * to the underlying output stream. If an error occurs during writing,
    * it gracefully handles the error by catching a Throwable, marking the write operation as unsuccessful.
    *
    * @param string $data The string data to append to the output.
    *
    * @return self Returns the current instance for chaining.
    */
   public function append (string $data): self
   {
      try {
         $this->written = @fwrite($this->stream, $data . PHP_EOL);
      } catch (Throwable) {
         $this->written = false;
      }

      return $this;
   }

   /**
    * Pads the given string data to a specified length with the provided padding.
    *
    * This method writes the padded data to the output stream, after rendering it to escape any
    * special template symbols. If an error occurs during the write operation, the written property
    * is set to false.
    *
    * @param string $data   The string to pad.
    * @param int    $length The desired total length of the padded string.
    * @param string $pad    The character(s) to use for padding. Defaults to a single space.
    * @param int    $type   The type of padding to apply. Defaults to STR_PAD_RIGHT.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function pad (
      string $data,
      int $length,
      string $pad = ' ',
      int $type = STR_PAD_RIGHT
   ): self
   {
      try {
         $this->written = @fwrite(
            $this->stream,
            __String::pad( // @phpstan-ignore-line
               TemplateEscaped::render($data),
               $length,
               $pad,
               $type
            )
         );
      }
      catch (Throwable) {
         $this->written = false;
      }

      return $this;
   }

   /**
    * Renders the provided data to the terminal output stream.
    *
    * This method writes a rendered version of the given data to the stream using 
    * the TemplateEscaped renderer. It handles any errors during the writing process
    * by catching a Throwable, and sets the written state to false if an exception occurs.
    *
    * @param string $data The data string to be rendered.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function render (string $data): self
   {
      try {
         $this->written = @fwrite(
            $this->stream,
            TemplateEscaped::render($data)
         );
      }
      catch (Throwable) {
         $this->written = false;
      }

      return $this;
   }

   // # ANSI Code
   /**
    * Escapes and writes the provided string to the output stream.
    *
    * This method prepends a predefined escape sequence (self::_START_ESCAPE) to the
    * given data before writing it to the stream. If the write operation fails,
    * the method handles the exception gracefully by setting the internal written
    * status to false.
    *
    * @param string $data The data to be escaped and written to the stream.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function escape (string $data): self
   {
      try {
         $this->written = @fwrite($this->stream, self::_START_ESCAPE . $data);
      }
      catch (Throwable) {
         $this->written = false;
      }

      return $this;
   }
   /**
    * Escapes shell meta characters in the provided data and writes the result to the terminal's output stream.
    *
    * This method uses PHP's built-in escapeshellcmd function to secure the input by escaping any shell meta 
    * characters that might be present. It then attempts to write the escaped string into the output stream.
    * Should any error occur during the write operation, the method will catch the exception and mark the
    * operation as failed by setting the written property to false.
    *
    * @param string $data The input string to be processed and written.
    *
    * @return self Returns the current instance to allow method chaining.
    */
   public function metaescape (string $data): self
   {
      try {
         $this->written = @fwrite($this->stream, escapeshellcmd($data));
      }
      catch (Throwable) {
         $this->written = false;
      }

      return $this;
   }
   /**
    * Encodes the provided string data as JSON and writes it to the output stream.
    *
    * This method attempts to encode the given data in JSON format and writes the result
    * to the stream associated with the output. If an error occurs during the writing process,
    * the written value is set to false.
    *
    * @param string $data The string data to be JSON encoded and written to the stream.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function metaencode (string $data): self
   {
      try {
         // !
         $encoded = json_encode($data);
         // ?
         if ($encoded === false) {
            $this->written = false;

            return $this;
         }

         $this->written = @fwrite($this->stream, $encoded);
      }
      catch (Throwable) {
         $this->written = false;
      }

      return $this;
   }
}
