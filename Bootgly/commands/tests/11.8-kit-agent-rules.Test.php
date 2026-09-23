<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const GLOB_ONLYDIR;
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function assert;
use function basename;
use function class_exists;
use function dirname;
use function enum_exists;
use function escapeshellarg;
use function exec;
use function explode;
use function file_get_contents;
use function function_exists;
use function glob;
use function in_array;
use function interface_exists;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function preg_split;
use function sort;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function substr_count;
use function trim;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

use Bootgly\ACI\Tests\Suite\Test;


/**
 * The agent rules a kit lays down in `projects/` ship from ONE source in the
 * framework: one rule file per section, all listed and imported by the
 * `projects/AGENTS.md` template, every bullet tiered, within a context
 * budget, tracked (never swallowed by an ignore rule), no `CLAUDE.md` among
 * them — and every command, action, flag and class they name exists, none
 * of a platform's: each platform package ships its own build skill.
 */

return new Test(
   description: 'The kit agent rules: one file per section, all imported, tiered, within budget, tracked, naming only real commands and classes',
   test: function () {
      $templates = BOOTGLY_ROOT_BASE . '/Bootgly/commands/templates/projects';
      $sections = [
         'Architecture_principles',
         'Coding_styles',
         'Naming_conventions',
         'Organizational_structures',
         'Testing_guidelines',
         'Workflow_pipelines',
      ];

      // @ One rule file per section — nothing missing, nothing extra
      $files = array_map(static fn (string $file): string => basename($file, '.md'), (array) glob("{$templates}/.agents/rules/*.md"));
      sort($files);
      yield assert(
         assertion: $files === $sections,
         description: 'templates/projects/.agents/rules holds exactly one file per section, got: ' . json_encode($files)
      );

      // @ The entry point opens with the stamp `kit boot` recognizes as its own
      $entry = (string) file_get_contents("{$templates}/AGENTS.md");
      yield assert(
         assertion: str_starts_with($entry, KitCommand::STAMP),
         description: 'projects/AGENTS.md opens with KitCommand::STAMP — without it `kit boot` treats the file as the user\'s'
      );

      // @ The entry point lists and imports every section
      preg_match_all('/^@\.agents\/rules\/([A-Za-z_]+)\.md$/m', $entry, $imports);
      $unlisted = [];
      foreach ($sections as $section) {
         if (str_contains($entry, "- `.agents/rules/{$section}.md`") === false) {
            $unlisted[] = $section;
         }
      }
      yield assert(
         assertion: $imports[1] === $sections && $unlisted === [],
         description: 'projects/AGENTS.md imports each section once, in order, and lists it in plain text, got imports: '
            . json_encode($imports[1]) . ' unlisted: ' . json_encode($unlisted)
      );

      // @@ Every top-level bullet of every rule file opens with its tier
      $untiered = [];
      $size = 0;
      $text = '';
      foreach ($sections as $section) {
         $rules = (string) file_get_contents("{$templates}/.agents/rules/{$section}.md");
         $size += strlen($rules);
         $text .= "{$rules}\n";
         preg_match_all('/^- (.*)$/m', $rules, $bullets);
         foreach ($bullets[1] as $bullet) {
            if (preg_match('/^\*\*(MUST|SHOULD|RECOMMEND)\*\* — /', $bullet) !== 1) {
               $untiered[] = "{$section}: {$bullet}";
            }
         }
      }
      yield assert(
         assertion: $untiered === [],
         description: 'every rule bullet opens with **MUST**, **SHOULD** or **RECOMMEND**, got: ' . json_encode($untiered)
      );

      // @ The context budget: every agent session loads all of it
      yield assert(
         assertion: $size <= 17000 && strlen($entry) <= 2000,
         description: "the rules stay within 17000 bytes ({$size}) and the entry point within 2000 (" . strlen($entry) . ')'
      );

      // @ No CLAUDE.md among the templates: one would stop Claude Code from
      //   reading every AGENTS.md at and below it
      $claude = [];
      $Entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templates, RecursiveDirectoryIterator::SKIP_DOTS));
      /** @var \SplFileInfo $Entry */
      foreach ($Entries as $Entry) {
         if (in_array($Entry->getFilename(), ['CLAUDE.md', 'CLAUDE.local.md'], true) === true) {
            $claude[] = $Entry->getPathname();
         }
      }
      yield assert(
         assertion: $claude === [],
         description: 'the templates ship no CLAUDE.md, got: ' . json_encode($claude)
      );

      // @ Tracked: the framework ignores `*.md` and `.agents/`, so the templates
      //   need their exceptions — answered only by a git that knows the checkout
      if (function_exists('exec') === true && (is_file(BOOTGLY_ROOT_BASE . '/.git') || is_file(BOOTGLY_ROOT_BASE . '/.git/HEAD'))) {
         $ignored = [];
         $unknown = [];
         $tracked = [
            'AGENTS.md',
            ...array_map(static fn (string $section): string => ".agents/rules/{$section}.md", $sections),
            ...array_map(
               static fn (string $file): string => substr($file, strlen($templates) + 1),
               array_map('strval', [...(array) glob("{$templates}/.agents/skills/*/SKILL.md"), ...(array) glob("{$templates}/.agents/skills/*/references/*.md")])
            ),
         ];
         foreach ($tracked as $path) {
            $output = [];
            $status = -1;
            exec(
               'git -C ' . escapeshellarg(BOOTGLY_ROOT_BASE) . ' check-ignore -q '
                  . escapeshellarg("Bootgly/commands/templates/projects/{$path}") . ' 2>/dev/null',
               $output,
               $status
            );
            match ($status) {
               0 => $ignored[] = $path,
               1 => null,
               default => $unknown[] = $path,
            };
         }
         if ($unknown === []) {
            yield assert(
               assertion: $ignored === [],
               description: 'no rule template is ignored by the framework .gitignore, ignored: ' . json_encode($ignored)
            );
         }
      }

      // ! What the commands declare
      $Declared = static function (string $class): array {
         return (new ReflectionClass($class))->getDefaultProperties();
      };
      $Commands = [
         'kit'      => $Declared(KitCommand::class),
         'lint'     => $Declared(LintCommand::class),
         'project'  => $Declared(ProjectCommand::class),
         'projects' => $Declared(ProjectsCommand::class),
         'test'     => $Declared(TestCommand::class),
      ];
      $names = [];
      foreach ((array) glob(BOOTGLY_ROOT_BASE . '/Bootgly/commands/*Command.php') as $file) {
         $properties = $Declared('Bootgly\\commands\\' . basename((string) $file, '.php'));
         $names[] = (string) ($properties['name'] ?? '');
      }
      // ! Second-level actions (`project <Name> migrate <action>`), from their help
      $actions = [];
      foreach ($Commands['project']['arguments'] as $verb => $meta) {
         $described = (string) ($meta['arguments']['<action>'] ?? '');
         if ($described !== '') {
            // ! Parenthetical notes are not actions: `run (minute-aligned worker loop) or list`
            $described = (string) preg_replace('/\s*\([^)]*\)/', '', $described);
            $actions[$verb] = preg_split('/,\s*(?:or\s+)?|\s+or\s+/', $described) ?: [];
         }
      }

      // @@ The skills: agentskills.io frontmatter a strict YAML parser reads
      //    whole (name = folder; the description a double-quoted scalar that is
      //    also a JSON string, 1 to 1024 characters; nothing else), a SKILL.md
      //    body within 200 lines, each references/*.md within 250
      $skills = '';
      $malformed = [];
      $documents = [];
      $folders = (array) glob("{$templates}/.agents/skills/*", GLOB_ONLYDIR);
      foreach ($folders as $folder) {
         $name = basename((string) $folder);
         $skill = (string) @file_get_contents("{$folder}/SKILL.md");
         $skills .= "{$skill}\n";
         $documents[] = ".agents/skills/{$name}/SKILL.md";
         $framed = preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $skill, $parts) === 1;
         preg_match_all('/^([a-z][a-z-]*):/m', $framed ? $parts[1] : '', $keys);
         preg_match('/^name: ([a-z0-9-]+)$/m', $framed ? $parts[1] : '', $named);
         preg_match('/^description: ("(?:[^"\\\\]|\\\\.)*")$/m', $framed ? $parts[1] : '', $quoted);
         $described = json_decode($quoted[1] ?? 'null');
         $body = $framed ? substr_count(trim($parts[2]), "\n") + 1 : 0;
         // ! The stamp `kit boot` owns a skill by — the first line after the frontmatter
         $stamped = preg_match('/\A---\n(?:(?!---\n)[^\n]*\n)*---\n' . preg_quote(KitCommand::STAMP, '/') . '/', $skill) === 1;
         if ($framed === false || $keys[1] !== ['name', 'description'] || ($named[1] ?? '') !== $name
            || preg_match('/^bootgly-[a-z0-9-]+$/', $name) !== 1 || is_string($described) === false
            || strlen($described) === 0 || strlen($described) > 1024 || $body > 200 || $stamped === false) {
            $malformed[] = $name;
         }
         foreach ((array) glob("{$folder}/references/*.md") as $reference) {
            $content = (string) file_get_contents((string) $reference);
            $skills .= "{$content}\n";
            $documents[] = ".agents/skills/{$name}/references/" . basename((string) $reference);
            if (substr_count(trim($content), "\n") + 1 > 250) {
               $malformed[] = "{$name}/references/" . basename((string) $reference);
            }
         }
      }
      yield assert(
         assertion: $folders !== [] && $malformed === [],
         description: 'every bootgly-* skill has name = folder, a double-quoted description of 1 to 1024 characters, no other key, the stamp right after, a body within 200 lines and references within 250, malformed: '
            . json_encode($malformed)
      );

      // @@ Every relative link in the skills resolves — the templates mirror the
      //    kit's projects/, so it resolves there too
      $broken = [];
      foreach ($documents as $document) {
         preg_match_all('/\]\(([^)\s]+)\)/', (string) file_get_contents("{$templates}/{$document}"), $links);
         foreach ($links[1] as $link) {
            if (preg_match('#^https?://#', $link) !== 1 && is_file(dirname("{$templates}/{$document}") . "/{$link}") === false) {
               $broken[] = "{$document}: {$link}";
            }
         }
      }
      yield assert(
         assertion: $documents !== [] && $broken === [],
         description: 'every relative link in the skills resolves, broken: ' . json_encode($broken)
      );

      // @ The entry point names exactly the skills shipped
      preg_match_all('/`(bootgly-[a-z0-9-]+)`/', $entry, $listed);
      $shipped = array_map(static fn (string $folder): string => basename($folder), array_map('strval', $folders));
      $named = $listed[1];
      sort($named);
      sort($shipped);
      yield assert(
         assertion: $named === $shipped && str_contains($entry, '`bootgly-build-<platform>`') === true,
         description: 'projects/AGENTS.md names every shipped skill and no other, and the platforms\' build skills by their pattern, got: '
            . json_encode(['listed' => $named, 'shipped' => $shipped])
      );

      // @ The framework knows no platform's internals: its rules and skills name
      //   no platform class and no path into a platform package — each platform
      //   package ships its own `bootgly-build-<platform>` skill for those
      preg_match_all('/`[^`\n]*\b(?:Console|Web)\\\\[A-Z][^`\n]*`|^use (?:Console|Web)\\\\.*;$|\b(?:Console|Web)\/(?:Console|Web|projects)\/\S*|--from=Demo\/(?!Notes\b|\.\.\.)\S+/m', "{$entry}\n{$text}\n{$skills}", $leaks);
      yield assert(
         assertion: $leaks[0] === [],
         description: 'the rules and skills name no platform class or platform package path, got: ' . json_encode($leaks[0])
      );

      // ! Every span an agent may run: inline code, and each line of a fenced block
      $Spans = static function (string $markdown): array {
         $spans = [];
         preg_match_all('/^```[^\n]*\n(.*?)^```/ms', $markdown, $blocks);
         foreach ($blocks[1] as $block) {
            foreach (explode("\n", $block) as $line) {
               $spans[] = trim($line);
            }
         }
         preg_match_all('/`([^`\n]*)`/', (string) preg_replace('/^```[^\n]*\n.*?^```/ms', '', $markdown), $inline);

         return [...$spans, ...$inline[1]];
      };

      // @@ Every command, verb and action an agent is told to run exists
      $spans = [1 => $Spans("{$entry}\n{$text}\n{$skills}")];
      $unknown = [];
      $flags = [];
      foreach ($spans[1] as $span) {
         // ? A git or composer command — not ours to check
         if (preg_match('/\b(?:git|composer|docker exec)\b/', $span) === 1 && str_contains($span, 'bootgly') === false) {
            continue;
         }
         // @ `bootgly <command>` — the command must exist
         if (preg_match('/\bbootgly ([a-z]+)\b/', $span, $match) === 1 && in_array($match[1], $names, true) === false) {
            $unknown[] = $span;
            continue;
         }
         // @ `<command> <verb>` for the commands that route verbs — a span that
         //   runs `bootgly …` or opens with the command itself
         if (preg_match('/(?:^|\bbootgly )(kit|lint|projects?)\b(.*)$/', $span, $match) === 1) {
            [, $command, $rest] = $match;
            $pattern = $command === 'project' ? '/^\s+(?:<Name>|[A-Z][\w\/-]*)\s+([a-z]+)(?:\s+([a-z]+))?/' : '/^\s+([a-z]+)\b/';
            if (preg_match($pattern, $rest, $verb) === 1) {
               $verbs = array_keys((array) $Commands[$command]['arguments']);
               if (in_array($verb[1], $verbs, true) === false) {
                  $unknown[] = $span;
               }
               else if (isset($verb[2], $actions[$verb[1]]) && in_array($verb[2], $actions[$verb[1]], true) === false) {
                  $unknown[] = $span;
               }
               // ? A check-only lint submodule told to --fix
               else if ($command === 'lint' && str_contains($rest, '--fix')
                  && ($Commands['lint']['arguments'][$verb[1]]['fixable'] ?? false) === false) {
                  $unknown[] = $span;
               }
            }
         }
         // ! Flags of our commands only, checked below against their sources
         if (str_contains($span, 'bootgly ') === true || preg_match('/^(?:--|kit |lint |projects? |test )/', $span) === 1) {
            preg_match_all('/(?<![\w-])--([a-z][a-z-]*)/', $span, $found);
            $flags = array_merge($flags, $found[1]);
         }
      }
      yield assert(
         assertion: $spans[1] !== [] && $unknown === [],
         description: 'every command, verb and action the rules name exists (and --fix only on a fixable lint), unknown: ' . json_encode($unknown)
      );

      // @@ Every flag the rules name is handled by a command
      $sources = '';
      foreach ((array) glob(BOOTGLY_ROOT_BASE . '/Bootgly/commands/*Command.php') as $file) {
         $sources .= (string) file_get_contents((string) $file);
      }
      $missing = [];
      foreach (array_unique($flags) as $flag) {
         // ? Git's own flag in the "never" list
         if ($flag === 'remote') {
            continue;
         }
         // ! Declared as the flag itself, or read as an option key
         $declared = preg_match('/--' . preg_quote($flag, '/') . '(?![a-z-])/', $sources) === 1
            || preg_match('/\$options\[\'' . preg_quote($flag, '/') . '\'\]/', $sources) === 1;
         if ($declared === false) {
            $missing[] = "--{$flag}";
         }
      }
      yield assert(
         assertion: $flags !== [] && $missing === [],
         description: 'every flag the rules name is handled by a command, missing: ' . json_encode($missing)
      );

      // @@ Every framework class the rules and skills name — inline or imported — exists
      preg_match_all('/`(Bootgly(?:\\\\[A-Za-z_]+){3,})`|^use (Bootgly(?:\\\\[A-Za-z_]+)+)(?: as \w+)?;$/m', "{$text}\n{$skills}", $found);
      $classes = [1 => array_values(array_unique(array_filter([...$found[1], ...$found[2]])))];
      $absent = [];
      foreach ($classes[1] as $class) {
         if (class_exists($class) === false && interface_exists($class) === false && enum_exists($class) === false) {
            $absent[] = $class;
         }
      }
      yield assert(
         assertion: $classes[1] !== [] && $absent === [],
         description: 'every Bootgly class the rules and skills name exists, absent: ' . json_encode($absent)
      );
   }
);
