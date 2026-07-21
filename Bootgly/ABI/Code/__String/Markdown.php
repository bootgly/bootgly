<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Code\__String;


use function array_map;
use function array_slice;
use function array_splice;
use function count;
use function explode;
use function implode;
use function ltrim;
use function max;
use function min;
use function preg_match;
use function preg_replace;
use function preg_split;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strcspn;
use function strlen;
use function strpos;
use function strspn;
use function substr;
use function trim;

use Bootgly\ABI\Code\__String\Markdown\Blocks;
use Bootgly\ABI\Code\__String\Markdown\Inlines;
use Bootgly\ABI\Code\__String\Markdown\Node;


/**
 * Markdown parser — a pure string → AST tool: no styling, no output, no
 * side effects. Supports a pragmatic GFM subset: ATX headings, paragraphs
 * (with hard breaks), fenced code blocks, blockquotes (nested + lazy),
 * nested tight lists (+ task items), tables (with column alignments),
 * horizontal rules and the emphasis/code/link/image inlines.
 * Out of scope: setext headings, indented code blocks, reference links,
 * autolinks, raw HTML (kept as literal text), footnotes and loose lists.
 */
class Markdown
{
   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Parses a markdown string into its block AST.
    *
    * @param string $markdown The markdown source.
    *
    * @return array<int,Node> The top-level blocks.
    */
   public function parse (string $markdown): array
   {
      // ! Normalization — CRLF/CR to LF, tabs to 4 spaces, NUL stripped
      $markdown = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $markdown);
      $markdown = str_replace("\t", '    ', $markdown);

      // :
      return $this->walk(explode("\n", $markdown));
   }

   /**
    * Walks lines through the block state machine — re-entered recursively
    * for blockquote and list item content.
    *
    * @param array<int,string> $lines The (already dedented) lines.
    *
    * @return array<int,Node> The blocks.
    */
   private function walk (array $lines): array
   {
      // ! Blocks
      /** @var array<int,Node> $Blocks */
      $Blocks = [];
      // ! Open paragraph accumulator
      /** @var array<int,string> $paragraph */
      $paragraph = [];

      $count = count($lines);
      $index = 0;

      // @ Close the open paragraph
      $close = function () use (&$Blocks, &$paragraph): void {
         // ?
         if ($paragraph === []) {
            return;
         }

         $Node = new Node(Blocks::Paragraph);
         $Node->Children = $this->scan(implode("\n", $paragraph));

         $Blocks[] = $Node;
         $paragraph = [];
      };

      // @@ Lines
      while ($index < $count) {
         $line = $lines[$index];

         // # Blank — block separator
         if (trim($line) === '') {
            $close();

            $index++;
            continue;
         }

         // # Fenced code block
         if (preg_match('/^( {0,3})(`{3,}|~{3,})(.*)$/', $line, $match) === 1) {
            $fence = $match[2];
            $info = trim($match[3]);

            // ? Backtick fences reject backticks in the info string
            if ($fence[0] === '~' || str_contains($info, '`') === false) {
               $close();

               $indent = strlen($match[1]);
               $language = $info === '' ? '' : explode(' ', $info)[0];

               // @@ Consume verbatim until the closing fence or EOF
               $content = [];
               $index++;

               while ($index < $count) {
                  $candidate = $lines[$index];

                  // ? Closing fence — same character, run at least as long
                  if (preg_match("/^ {0,3}{$fence[0]}{" . strlen($fence) . ",}[ \t]*$/", $candidate) === 1) {
                     $index++;

                     break;
                  }

                  // ! Dedent up to the opening fence indent
                  $strip = min($indent, strspn($candidate, ' '));
                  $content[] = substr($candidate, $strip);

                  $index++;
               }

               $Node = new Node(Blocks::Fence);
               $Node->text = implode("\n", $content);
               $Node->language = $language;

               $Blocks[] = $Node;
               continue;
            }
         }

         // # ATX heading
         if (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*))?$/', $line, $match) === 1) {
            $close();

            // ? A space-preceded trailing hash run closes the heading
            $text = trim((string) preg_replace('/[ \t]+#+[ \t]*$/', '', $match[2] ?? ''));

            $Node = new Node(Blocks::Heading);
            $Node->level = strlen($match[1]);
            $Node->Children = $this->scan($text);

            $Blocks[] = $Node;
            $index++;
            continue;
         }

         // # Horizontal rule — before lists: `- - -` is a rule, not items
         if (preg_match('/^ {0,3}([-_*])[ \t]*(?:\1[ \t]*){2,}$/', $line) === 1) {
            $close();

            $Blocks[] = new Node(Blocks::Rule);
            $index++;
            continue;
         }

         // # Blockquote
         if (preg_match('/^ {0,3}> ?(.*)$/', $line, $match) === 1) {
            $close();

            // @@ Collect marked lines plus lazy paragraph continuations
            $content = [$match[1]];
            $index++;

            while ($index < $count) {
               $candidate = $lines[$index];

               if (preg_match('/^ {0,3}> ?(.*)$/', $candidate, $match) === 1) {
                  $content[] = $match[1];

                  $index++;
                  continue;
               }

               // ? Lazy continuation — plain text keeps the quote open
               if (trim($candidate) !== '' && $this->check($candidate) === false) {
                  $content[] = $candidate;

                  $index++;
                  continue;
               }

               break;
            }

            $Node = new Node(Blocks::Quote);
            $Node->Children = $this->walk($content);

            $Blocks[] = $Node;
            continue;
         }

         // # List
         $bullet = preg_match('/^( {0,3})([-+*])(?:( {1,4})(.*))?$/', $line, $match) === 1;
         $number = $bullet === false
            && preg_match('/^( {0,3})(\d{1,9})([.)])(?:( {1,4})(.*))?$/', $line, $match) === 1;

         if ($bullet === true || $number === true) {
            $close();

            $List = new Node(Blocks::List);
            $List->ordered = $number === true;
            if ($number === true) {
               $List->start = (int) $match[2];
            }
            // ! Marker signature — the bullet character or the ordered delimiter
            $signature = $number === true ? $match[3] : $match[2];

            // @@ Items
            while ($index < $count) {
               $line = $lines[$index];

               // ? A horizontal rule wins over a list item
               if (preg_match('/^ {0,3}([-_*])[ \t]*(?:\1[ \t]*){2,}$/', $line) === 1) {
                  break;
               }

               $matched = $List->ordered === false
                  ? preg_match('/^( {0,3})([-+*])(?:( {1,4})(.*))?$/', $line, $item) === 1
                  : preg_match('/^( {0,3})(\d{1,9})([.)])(?:( {1,4})(.*))?$/', $line, $item) === 1;

               // ?
               if ($matched === false) {
                  break;
               }

               // ! Item anatomy
               if ($List->ordered === false) {
                  $marker = $item[2];
                  $spacing = $item[3] ?? '';
                  $rest = $item[4] ?? '';
                  $flavor = $item[2];
               }
               else {
                  $marker = $item[2] . $item[3];
                  $spacing = $item[4] ?? '';
                  $rest = $item[5] ?? '';
                  $flavor = $item[3];
               }

               // ? A different marker flavor opens a new list
               if ($flavor !== $signature) {
                  break;
               }

               // ! Content column — indent + marker + spacing (at least one)
               $column = strlen($item[1]) + strlen($marker) + max(1, strlen($spacing));

               // @@ Collect the item content (indented continuation only)
               $content = [$rest];
               $index++;

               while ($index < $count) {
                  $candidate = $lines[$index];

                  // ? Indented continuation — dedent by the content column
                  if (trim($candidate) !== '' && strspn($candidate, ' ') >= $column) {
                     $content[] = substr($candidate, $column);

                     $index++;
                     continue;
                  }

                  // ? A single blank stays inside before an indented line
                  if (
                     trim($candidate) === ''
                     && isSet($lines[$index + 1]) === true
                     && trim($lines[$index + 1]) !== ''
                     && strspn($lines[$index + 1], ' ') >= $column
                  ) {
                     $content[] = '';

                     $index++;
                     continue;
                  }

                  break;
               }

               // ! Task state — on the item's first line only
               $checked = null;
               if (preg_match('/^\[([ xX])\] /', $content[0], $task) === 1) {
                  $checked = $task[1] !== ' ';
                  $content[0] = substr($content[0], 4);
               }

               $Item = new Node(Blocks::Item);
               $Item->checked = $checked;
               $Item->Children = $this->walk($content);

               $List->Children[] = $Item;
            }

            $Blocks[] = $List;
            continue;
         }

         // # Table — a pipe line followed by a matching separator row
         if (
            str_contains($line, '|') === true
            && isSet($lines[$index + 1]) === true
            && preg_match('/^ {0,3}\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/', $lines[$index + 1]) === 1
         ) {
            $headers = $this->split($line);
            $separators = $this->split($lines[$index + 1]);

            if ($headers !== [] && count($headers) === count($separators)) {
               $close();

               // ! Column alignments from the separator cells
               $alignments = [];
               foreach ($separators as $separator) {
                  $separator = trim($separator);

                  $left = str_starts_with($separator, ':');
                  $right = str_ends_with($separator, ':');

                  $alignments[] = match (true) {
                     $left === true && $right === true => 'center',
                     $right === true => 'right',
                     default => 'left'
                  };
               }

               $Table = new Node(Blocks::Table);
               $Table->alignments = $alignments;
               $columns = count($headers);

               // ! Row lines — the header first, then the body
               $rows = [$line];
               $index += 2;

               while ($index < $count) {
                  $candidate = $lines[$index];

                  // ? The table ends at blank lines, non-pipe lines or new blocks
                  if (
                     trim($candidate) === ''
                     || str_contains($candidate, '|') === false
                     || $this->check($candidate) === true
                  ) {
                     break;
                  }

                  $rows[] = $candidate;
                  $index++;
               }

               // @@ Rows — ragged rows pad missing cells and drop extras
               foreach ($rows as $raw) {
                  $cells = $this->split($raw);

                  $Row = new Node(Blocks::Row);
                  for ($cell = 0; $cell < $columns; $cell++) {
                     $Cell = new Node(Blocks::Cell);
                     $Cell->Children = $this->scan(trim($cells[$cell] ?? ''));

                     $Row->Children[] = $Cell;
                  }

                  $Table->Children[] = $Row;
               }

               $Blocks[] = $Table;
               continue;
            }
         }

         // # Paragraph — trailing spaces stay (hard breaks)
         $paragraph[] = ltrim($line);
         $index++;
      }

      // ! Trailing paragraph
      $close();

      // :
      return $Blocks;
   }

   /**
    * Checks whether a line starts a non-paragraph block — the lazy
    * continuation stopper.
    *
    * @param string $line The candidate line.
    *
    * @return bool
    */
   private function check (string $line): bool
   {
      // :
      return preg_match(
         '/^ {0,3}(?:#{1,6}(?:[ \t]|$)|`{3,}|~{3,}|>|[-+*](?: |$)|\d{1,9}[.)](?: |$)|([-_*])[ \t]*(?:\1[ \t]*){2,}$)/',
         $line
      ) === 1;
   }

   /**
    * Scans inline text into resolved nodes and emphasis delimiters, then
    * pairs the delimiters. Code spans win over emphasis by construction
    * (left-to-right consumption).
    *
    * @param string $text The inline text (may span multiple lines).
    *
    * @return array<int,Node> The inline nodes.
    */
   private function scan (string $text): array
   {
      // ! Tokens — ['node', Node] or ['delimiter', char, length, canOpen, canClose]
      /** @var array<int,array<int,mixed>> $tokens */
      $tokens = [];
      // ! Pending literal text
      $literal = '';

      $length = strlen($text);
      $offset = 0;

      // @ Flush pending literal text as a Text node — returns the empty buffer
      $flush = static function (string $pending) use (&$tokens): string {
         if ($pending !== '') {
            $Node = new Node(Inlines::Text);
            $Node->text = $pending;

            $tokens[] = ['node', $Node];
         }

         // :
         return '';
      };

      // @@ Bytes (delimiters are ASCII — multibyte text passes through verbatim)
      while ($offset < $length) {
         $jump = strcspn($text, "`*_~[!\\\n", $offset);
         if ($jump > 0) {
            $literal .= substr($text, $offset, $jump);
            $offset += $jump;
            continue;
         }

         $byte = $text[$offset];

         switch ($byte) {
            // # Escapes
            case "\\":
               $next = $text[$offset + 1] ?? '';

               if ($next !== '' && str_contains('!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~', $next) === true) {
                  $literal .= $next;
                  $offset += 2;
               }
               else {
                  $literal .= "\\";
                  $offset += 1;
               }

               break;
            // # Code spans
            case '`':
               $run = strspn($text, '`', $offset);

               // ! Closing run of the exact same length
               $closing = null;
               $cursor = $offset + $run;

               while (($position = strpos($text, str_repeat('`', $run), $cursor)) !== false) {
                  $extra = strspn($text, '`', $position);

                  if ($extra === $run) {
                     $closing = $position;

                     break;
                  }

                  $cursor = $position + $extra;
               }

               // ? No closer — literal backticks
               if ($closing === null) {
                  $literal .= str_repeat('`', $run);
                  $offset += $run;

                  break;
               }

               $literal = $flush($literal);

               $code = substr($text, $offset + $run, $closing - $offset - $run);
               // ? One padding space strips when both sides are padded
               if (strlen($code) > 2 && $code[0] === ' ' && $code[-1] === ' ' && trim($code) !== '') {
                  $code = substr($code, 1, -1);
               }
               // ? Newlines inside code spans render as spaces
               $code = str_replace("\n", ' ', $code);

               $Node = new Node(Inlines::Code);
               $Node->text = $code;

               $tokens[] = ['node', $Node];
               $offset = $closing + $run;

               break;
            // # Links and images
            case '[':
            case '!':
               $image = $byte === '!';

               // ? A bang without a bracket is literal
               if ($image === true && ($text[$offset + 1] ?? '') !== '[') {
                  $literal .= '!';
                  $offset += 1;

                  break;
               }

               $start = $offset + ($image === true ? 2 : 1);

               // ! Label — bracket matching with depth
               $label = null;
               $depth = 1;
               $cursor = $start;

               while ($cursor < $length) {
                  $character = $text[$cursor];

                  if ($character === "\\") {
                     $cursor += 2;
                     continue;
                  }
                  if ($character === '[') {
                     $depth++;
                  }
                  if ($character === ']') {
                     $depth--;

                     if ($depth === 0) {
                        $label = substr($text, $start, $cursor - $start);

                        break;
                     }
                  }

                  $cursor++;
               }

               // ? No label or no immediate destination — literal opener
               if ($label === null || ($text[$cursor + 1] ?? '') !== '(') {
                  $literal .= $image === true ? '![' : '[';
                  $offset += $image === true ? 2 : 1;

                  break;
               }

               // ! Destination — balanced/escaped parentheses
               $destination = null;
               $cursor += 2;
               $from = $cursor;
               $depth = 1;

               while ($cursor < $length) {
                  $character = $text[$cursor];

                  if ($character === "\\") {
                     $cursor += 2;
                     continue;
                  }
                  if ($character === '(') {
                     $depth++;
                  }
                  if ($character === ')') {
                     $depth--;

                     if ($depth === 0) {
                        $destination = substr($text, $from, $cursor - $from);

                        break;
                     }
                  }

                  $cursor++;
               }

               // ? Unbalanced destination — literal opener
               if ($destination === null) {
                  $literal .= $image === true ? '![' : '[';
                  $offset += $image === true ? 2 : 1;

                  break;
               }

               $literal = $flush($literal);

               // ? A quoted title is tolerated and discarded
               $URL = trim($destination);
               if (preg_match('/^(\S+)[ \t]+"[^"]*"$/', $URL, $match) === 1) {
                  $URL = $match[1];
               }

               $Node = new Node($image === true ? Inlines::Image : Inlines::Link);
               $Node->URL = $URL;

               if ($image === true) {
                  $Node->text = $label;
               }
               else {
                  $Node->Children = $this->scan($label);
               }

               $tokens[] = ['node', $Node];
               $offset = $cursor + 1;

               break;
            // # Emphasis delimiters
            case '*':
            case '_':
            case '~':
               $run = strspn($text, $byte, $offset);

               // ? Strikethrough pairs exactly two tildes
               if ($byte === '~' && $run !== 2) {
                  $literal .= str_repeat('~', $run);
                  $offset += $run;

                  break;
               }

               $previous = $offset > 0 ? $text[$offset - 1] : '';
               $next = $text[$offset + $run] ?? '';

               $canOpen = $next !== '' && $next !== ' ' && $next !== "\n";
               $canClose = $previous !== '' && $previous !== ' ' && $previous !== "\n";

               // ? Underscores never work intraword
               if ($byte === '_') {
                  $canOpen = $canOpen === true && preg_match('/[\p{L}\p{N}]/u', $previous) !== 1;
                  $canClose = $canClose === true && preg_match('/[\p{L}\p{N}]/u', $next) !== 1;
               }

               // ? Inert runs are literal
               if ($canOpen === false && $canClose === false) {
                  $literal .= str_repeat($byte, $run);
                  $offset += $run;

                  break;
               }

               $literal = $flush($literal);

               $tokens[] = ['delimiter', $byte, $run, $canOpen, $canClose];
               $offset += $run;

               break;
            // # Line breaks
            case "\n":
               // ? Two trailing spaces make a hard break
               if (str_ends_with($literal, '  ') === true) {
                  $literal = rtrim($literal, ' ');
                  $literal = $flush($literal);

                  $tokens[] = ['node', new Node(Inlines::Break)];
               }
               else {
                  // ? Soft breaks reflow as a single space
                  $literal = rtrim($literal, ' ') . ' ';
               }

               $offset += 1;

               break;
         }
      }

      $literal = $flush($literal);

      // :
      return $this->pair($tokens);
   }

   /**
    * Pairs emphasis delimiters — each closer matches the nearest compatible
    * opener; runs shrink and may match again (`***x***` resolves to
    * Italic(Bold)). Leftovers degrade to literal text.
    *
    * @param array<int,array<int,mixed>> $tokens The scanned tokens.
    *
    * @return array<int,Node> The final inline nodes.
    */
   private function pair (array $tokens): array
   {
      // @@ Closers, left to right
      for ($close = 0; $close < count($tokens); $close++) {
         $closer = $tokens[$close];

         // ?
         if ($closer[0] !== 'delimiter' || $closer[4] === false) {
            continue;
         }

         // @ Nearest compatible opener behind
         for ($open = $close - 1; $open >= 0; $open--) {
            $opener = $tokens[$open];

            if ($opener[0] !== 'delimiter' || $opener[3] === false || $opener[1] !== $closer[1]) {
               continue;
            }

            // ! Consumed delimiter length
            /** @var int $used */
            $used = min($opener[2], $closer[2], 2);

            $Node = new Node(match (true) {
               $closer[1] === '~' => Inlines::Strike,
               $used === 2 => Inlines::Bold,
               default => Inlines::Italic
            });
            $Node->Children = $this->resolve(array_slice($tokens, $open + 1, $close - $open - 1));

            // @ Splice — opener remainder, the node, closer remainder
            /** @var int $openings */
            $openings = $opener[2];
            /** @var int $closings */
            $closings = $closer[2];

            $spare = $openings - $used;
            $remnant = $closings - $used;

            $replacement = [];
            if ($spare > 0) {
               $opener[2] = $spare;
               $replacement[] = $opener;
            }
            $replacement[] = ['node', $Node];
            if ($remnant > 0) {
               $closer[2] = $remnant;
               $replacement[] = $closer;
            }

            array_splice($tokens, $open, $close - $open + 1, $replacement);

            // ! Rescan from the spliced region
            $close = $open - 1;

            break;
         }
      }

      // :
      return $this->resolve($tokens);
   }

   /**
    * Resolves leftover tokens — nodes unwrap, unmatched delimiters degrade
    * to literal text.
    *
    * @param array<int,array<int,mixed>> $tokens The tokens.
    *
    * @return array<int,Node>
    */
   private function resolve (array $tokens): array
   {
      /** @var array<int,Node> $Nodes */
      $Nodes = [];

      // @@
      foreach ($tokens as $token) {
         if ($token[0] === 'node') {
            /** @var Node $Node */
            $Node = $token[1];
            $Nodes[] = $Node;

            continue;
         }

         // ? Unmatched delimiters are literal
         $Node = new Node(Inlines::Text);
         /** @var string $character */
         $character = $token[1];
         /** @var int $length */
         $length = $token[2];
         $Node->text = str_repeat($character, $length);

         $Nodes[] = $Node;
      }

      // :
      return $Nodes;
   }

   /**
    * Splits a table row into raw cell strings — pipes escaped with a
    * backslash stay inside their cell.
    *
    * @param string $row The raw row line.
    *
    * @return array<int,string>
    */
   private function split (string $row): array
   {
      $row = trim($row);

      // ? Outer pipes are decorative
      if (str_starts_with($row, '|') === true) {
         $row = substr($row, 1);
      }
      if (str_ends_with($row, '|') === true && str_ends_with($row, '\\|') === false) {
         $row = substr($row, 0, -1);
      }

      /** @var array<int,string> $cells */
      $cells = preg_split('/(?<!\\\\)\|/', $row) ?: [];

      // :
      return array_map(
         static fn (string $cell): string => str_replace('\\|', '|', $cell),
         $cells
      );
   }
}
