<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components;


use function array_filter;
use function assert;
use function count;
use function explode;
use function implode;
use function in_array;
use function ini_restore;
use function ini_set;
use function preg_match;
use function preg_replace_callback;
use function str_contains;
use function str_repeat;
use function strlen;

use Bootgly\ABI\Code\__String\Markdown as Parser;
use Bootgly\ABI\Code\__String\Markdown\Node;
use Bootgly\ABI\Code\__String\Tokens\Highlighter;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'It should show document controls as visible escapes in every construct',
   test: function () {
      // ! Hostile payload — CSI (one of them a complete SGR that conceals text), OSC title
      //   and OSC 52 clipboard writes ended by BEL, a UTF-8 C1 OSC/ST pair, BS/FF/DEL and a
      //   byte that is not UTF-8 (an 8-bit CSI). Its brackets balance and it holds no space,
      //   pipe, backtick, parenthesis, backslash or emphasis delimiter, so it sits inside
      //   every construct without changing the parse.
      $payload = "\e[8m\e[2J\e]0;t\x07\e]52;c;SGVsbG8=\x07\u{9D}0;t\u{9C}\x08\x0C\x7F\x9B2J";
      // ! Its visible form — every control escaped, the stray byte scrubbed to `?`
      $shown = '\u001b[8m\u001b[2J\u001b]0;t\u0007\u001b]52;c;SGVsbG8=\u0007\u009d0;t\u009c\b\f\u007f?2J';

      // ! SGR — one complete sequence from a list of codes
      $Wrap = static function (array|string $codes): string {
         $joined = implode(';', (array) $codes);

         // :
         return "\e[{$joined}m";
      };
      // ! The component's own SGR — its palette, the reset and the PHP highlighter theme
      $Palette = new Markdown(new Output('php://memory'));
      $SGR = ["\e[0m"];
      foreach ($Palette->styles as $codes) {
         $SGR[] = $Wrap($codes);
      }
      foreach (Highlighter::$Themes['bootgly'] as $codes) {
         $SGR[] = $Wrap($codes);
      }

      // ! Table grid — the escaped cell sets the column width
      $rule = str_repeat('─', strlen($shown) + 3);
      $pad = str_repeat(' ', strlen($shown));

      // ! Constructs — source; plain lines; styled lines once the component SGR is
      //   stripped; the AST fields that carry the payload to the component; the SGR that
      //   proves the styled render took the construct's own path. The fence keeps its
      //   lines (LF) and the TAB is the parser's four-space indent, never `\t`.
      /** @var array<string,array{0:string,1:array<int,string>,2:array<int,string>,3:array<int,string>,4:array<int,string>}> $constructs */
      $constructs = [
         'Paragraph' => [
            "Para {$payload} *em{$payload}* end",
            ["Para {$shown} em{$shown} end"],
            ["Para {$shown} em{$shown} end"],
            ['Text.text'],
            [$Wrap($Palette->styles['italic'])]
         ],
         'Code span' => [
            "Code `span{$payload}` end",
            ["Code `span{$shown}` end"],
            ["Code span{$shown} end"],
            ['Code.text'],
            [$Wrap($Palette->styles['code'])]
         ],
         'Link text and URL' => [
            "See [label{$payload}](http://x/{$payload}) end",
            ["See label{$shown} (http://x/{$shown}) end"],
            ["See label{$shown} (http://x/{$shown}) end"],
            ['Text.text', 'Link.URL'],
            [$Wrap($Palette->styles['link']), $Wrap($Palette->styles['url'])]
         ],
         'Image alt and URL' => [
            "See ![alt{$payload}](http://x/{$payload}) end",
            ["See [alt{$shown}] (http://x/{$shown}) end"],
            ["See [alt{$shown}] (http://x/{$shown}) end"],
            ['Image.text', 'Image.URL'],
            [$Wrap($Palette->styles['image']), $Wrap($Palette->styles['url'])]
         ],
         'Fence language and body' => [
            "```lang{$payload}\nline{$payload}\n\tindented\nlast\n```",
            ["```lang{$shown}", "line{$shown}", '    indented', 'last', '```'],
            ["```lang{$shown}", "line{$shown}", '    indented', 'last', '```'],
            ['Fence.language', 'Fence.text'],
            [$Wrap($Palette->styles['fence']), $Wrap($Palette->styles['source'])]
         ],
         'Highlighted PHP fence' => [
            "```php\necho '{$payload}';\n// {$payload}\n```",
            ['```php', "echo '{$shown}';", "// {$shown}", '```'],
            ['```php', "echo '{$shown}';", "// {$shown}", '```'],
            ['Fence.text'],
            [$Wrap($Palette->styles['fence']), $Wrap(Highlighter::$Themes['bootgly'][Highlighter::TOKEN_STRING])]
         ],
         'Table cell' => [
            "| H{$payload} | B |\n|---|---|\n| c{$payload} | d |\n| e | f |",
            [" H{$shown} │ B", "{$rule}┼───", " c{$shown} │ d", " e{$pad} │ f"],
            [" H{$shown} │ B", "{$rule}┼───", " c{$shown} │ d", " e{$pad} │ f"],
            ['Text.text'],
            [$Wrap($Palette->styles['header']), $Wrap($Palette->styles['border'])]
         ],
         'Heading' => [
            "## Head{$payload}",
            ["## Head{$shown}"],
            ["## Head{$shown}"],
            ['Text.text'],
            [$Wrap($Palette->styles['h2'])]
         ],
         'List item' => [
            "- item{$payload}\n- next",
            ["• item{$shown}", '• next'],
            ["• item{$shown}", '• next'],
            ['Text.text'],
            [$Wrap($Palette->styles['marker'])]
         ],
         'Quote' => [
            "> quote{$payload}",
            ["│ quote{$shown}"],
            ["│ quote{$shown}"],
            ['Text.text'],
            [$Wrap($Palette->styles['quote'])]
         ]
      ];

      // ! Render — wide enough that no construct wraps
      $Render = static function (string $source, bool $decoration): string {
         $Markdown = new Markdown(new Output('php://memory'));
         $Markdown->width = 1000;
         $Markdown->decoration = $decoration;
         $Markdown->source = $source;

         // :
         return (string) $Markdown->render(Component::RETURN_OUTPUT);
      };

      // ! Inspect — strip the component's own SGR, then name every control left
      /**
       * @param array<int,string> $own
       *
       * @return array{0:array<int,string>,1:string}
       */
      $Inspect = static function (string $output, array $own): array {
         $residue = (string) preg_replace_callback(
            '/\e\[[0-9;]*m/',
            static fn (array $match): string => in_array($match[0], $own, true) ? '' : $match[0],
            $output
         );

         $violations = [];
         if (str_contains($residue, "\e") === true) {
            $violations[] = 'ESC outside the component SGR';
         }
         if (str_contains($residue, "\x07") === true) {
            $violations[] = 'BEL';
         }
         if (preg_match('/[\x00-\x06\x08\x0B-\x1A\x1C-\x1F\x7F]/', $residue) === 1) {
            $violations[] = 'C0 or DEL';
         }
         if (preg_match('/\xC2[\x80-\x9F]/', $residue) === 1) {
            $violations[] = 'C1';
         }
         if (preg_match('//u', $output) !== 1) {
            $violations[] = 'not UTF-8';
         }

         // :
         return [$violations, $residue];
      };

      // ! AST fields — `Type.field` => every string the parser stored there
      /**
       * @param array<int,Node> $Nodes
       * @param array<string,array<int,string>> $fields
       *
       * @return array<string,array<int,string>>
       */
      $Collect = static function (array $Nodes, array $fields = []) use (&$Collect): array {
         foreach ($Nodes as $Node) {
            $type = $Node->type->name;

            $fields["{$type}.text"][] = $Node->text;
            $fields["{$type}.language"][] = $Node->language;
            $fields["{$type}.URL"][] = (string) $Node->URL;

            $fields = $Collect($Node->Children, $fields);
         }

         // :
         return $fields;
      };

      try {
         // ! `?` per stray byte on every host — ext-mbstring's `mb_scrub()` writes whatever
         //   `mbstring.substitute_character` holds (the polyfill always writes `?`)
         ini_set('mbstring.substitute_character', '63');

         // @ Positive control — the payload is hostile (raw ESC, BEL, C1, DEL and a byte that
         //   is not UTF-8) and the parser hands it byte for byte to every field the legs aim
         //   at: the visible escapes below are the component's own doing
         $Parser = new Parser;
         $missing = [];

         foreach ($constructs as $construct => [$source, , , $targets]) {
            $fields = $Collect($Parser->parse($source));

            foreach ($targets as $target) {
               $carriers = array_filter(
                  $fields[$target] ?? [],
                  static fn (string $field): bool => str_contains($field, $payload)
               );

               if (count($carriers) === 0) {
                  $missing[] = "{$construct} ({$target})";
               }
            }
         }

         $hostile = str_contains($payload, "\e") === true
            && str_contains($payload, "\x07") === true
            && str_contains($payload, "\u{9D}") === true
            && str_contains($payload, "\x7F") === true
            && preg_match('//u', $payload) !== 1;
         $absent = implode(', ', $missing);
         $suffix = $missing === [] ? '' : " — the raw payload is missing from: {$absent}";

         yield assert(
            assertion: $hostile === true && $missing === [],
            description: "Positive control: the parser hands the raw payload to every construct{$suffix}"
         );

         // @@ Legs — every construct, plain and styled
         foreach ($constructs as $construct => [$source, $plain, $styled, , $marks]) {
            foreach ([false, true] as $decoration) {
               $mode = $decoration === true ? 'styled' : 'plain';
               $output = $Render($source, $decoration);

               [$violations, $residue] = $Inspect($output, $decoration === true ? $SGR : []);

               // ? TAB/LF structure — the construct keeps its lines
               $lines = $decoration === true ? $styled : $plain;
               if (count(explode("\n", $residue)) !== count($lines) + 1) {
                  $violations[] = 'line structure';
               }
               // ? Every control shows as its visible escape, in place
               $expected = implode("\n", $lines);
               if ($residue !== "{$expected}\n") {
                  $violations[] = 'visible text';
               }
               // ? The styled render took the construct's own path (else it repeats the plain leg)
               foreach ($decoration === true ? $marks : [] as $mark) {
                  if (str_contains($output, $mark) === false) {
                     $violations[] = 'construct styling';

                     break;
                  }
               }

               $found = implode(', ', $violations);
               $suffix = $violations === [] ? '' : " — found: {$found}";

               yield assert(
                  assertion: $violations === [],
                  description: "{$construct} ({$mode}): document controls show as visible escapes{$suffix}"
               );
            }
         }
      }
      finally {
         ini_restore('mbstring.substitute_character');
      }
   }
);
