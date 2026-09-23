<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use function array_map;
use function assert;
use function basename;
use function chmod;
use function copy;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getenv;
use function glob;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function json_decode;
use function json_encode;
use function mkdir;
use function posix_geteuid;
use function preg_replace;
use function proc_close;
use function proc_open;
use function readlink;
use function rename;
use function rewind;
use function rmdir;
use function sort;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function stream_get_contents;
use function symlink;
use function time;
use function touch;
use function unlink;
use Closure;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\CLI\Terminal\Output;


/**
 * The agent rules a kit keeps in `projects/`: `kit boot` lays down
 * `AGENTS.md`, `.agents/rules/` and the `bootgly-*` skills (the framework's
 * and each platform package's, linked for Claude Code) and REFRESHES them
 * whenever they differ — but only while they are Bootgly's (stamped): a
 * user's own file or skill in their place is left alone and said, the rest
 * of `.agents/` is never touched, a link is never written through, a swap
 * that fails is rolled back and an interrupted run's staging is swept. After
 * a move the new release's launcher re-lays them, and a release that
 * predates them takes Bootgly's away.
 */

return new Test(
   description: '`kit boot` lays down and refreshes the framework\'s agent rules in projects/, never the user\'s; a move re-lays them or takes them away',
   test: function () {
      $base = Temporaries::reserve('kit-agent-rules');
      $stamp = KitCommand::STAMP . ": do not edit. -->\n";
      // ! A skill of Bootgly's: the stamp is the first line after its frontmatter
      $Skill = static fn (string $name, string $description): string
         => "---\nname: {$name}\ndescription: \"{$description}\"\n---\n" . KitCommand::STAMP . ": do not edit. -->\n\n{$description}\n";

      // ! A miniature framework checkout as the template source
      $Framework = static function (string $root, bool $rules) use ($stamp, $Skill): string {
         mkdir("{$root}/Bootgly/commands/stubs", 0775, true);
         copy(BOOTGLY_ROOT_BASE . '/Bootgly/commands/stubs/Bootgly.projects.php', "{$root}/Bootgly/commands/stubs/Bootgly.projects.php");
         mkdir("{$root}/scripts", 0775, true);
         file_put_contents("{$root}/scripts/autoboot.php", "<?php return [];\n");
         if ($rules === true) {
            mkdir("{$root}/Bootgly/commands/templates/projects/.agents/rules", 0775, true);
            file_put_contents("{$root}/Bootgly/commands/templates/projects/AGENTS.md", "{$stamp}\n# Rules\n\n@.agents/rules/Alpha.md\n");
            file_put_contents("{$root}/Bootgly/commands/templates/projects/.agents/rules/Alpha.md", "# Alpha\n\n- **MUST** — alpha.\n");
            mkdir("{$root}/Bootgly/commands/templates/projects/.agents/skills/bootgly-alpha", 0775, true);
            file_put_contents("{$root}/Bootgly/commands/templates/projects/.agents/skills/bootgly-alpha/SKILL.md", $Skill('bootgly-alpha', 'Alpha.'));
         }

         return $root;
      };
      $templates = $Framework("{$base}/templates", true);
      $old = $Framework("{$base}/old", false);
      $rules = "{$templates}/Bootgly/commands/templates/projects";

      $Bind = static function (string $kit, string $templates): KitCommand {
         return new class ($kit, $templates) extends KitCommand {
            public function __construct (string $kit, string $templates)
            {
               parent::__construct();
               $this->kit = $kit;
               $this->templates = $templates;
            }
         };
      };
      $Capture = static function (callable $Run): array {
         $Host = new Output('php://memory');
         $Terminal = CLI->Terminal;
         $Restore = $Terminal->Output;
         $Terminal->Output = $Host;
         try {
            $result = $Run();
         }
         finally {
            $Terminal->Output = $Restore;
         }
         rewind($Host->stream);

         return [$result, (string) preg_replace('/\e\[[0-9;]*m/', '', (string) stream_get_contents($Host->stream))];
      };
      $Boot = static fn (KitCommand $Command, array $options = []): array => $Capture(static fn (): bool => $Command->run(['boot'], $options));
      $Same = static fn (string $kit): bool => file_get_contents("{$kit}/projects/AGENTS.md") === file_get_contents("{$rules}/AGENTS.md")
         && file_get_contents("{$kit}/projects/.agents/rules/Alpha.md") === file_get_contents("{$rules}/.agents/rules/Alpha.md");

      // # A fresh kit: the directories and the rules
      $kit = "{$base}/kit";
      mkdir($kit, 0775, true);
      $Command = $Bind($kit, $templates);
      [$result, $output] = $Boot($Command);
      yield assert(
         assertion: $result === true && $Same($kit) === true && str_contains($output, 'Agent rules laid down'),
         description: '`kit boot` lays down projects/AGENTS.md and projects/.agents/rules/ from the templates, got: ' . json_encode($output)
      );

      // # The skills too — and a link per skill for Claude Code, which reads .claude/skills/ only
      yield assert(
         assertion: file_get_contents("{$kit}/projects/.agents/skills/bootgly-alpha/SKILL.md") === file_get_contents("{$rules}/.agents/skills/bootgly-alpha/SKILL.md")
            && is_link("{$kit}/projects/.claude/skills/bootgly-alpha") === true
            && readlink("{$kit}/projects/.claude/skills/bootgly-alpha") === '../../.agents/skills/bootgly-alpha'
            && is_file("{$kit}/projects/.claude/skills/bootgly-alpha/SKILL.md") === true,
         description: '`kit boot` lays down the bootgly-* skills and links each into projects/.claude/skills/'
      );

      // # Up to date: nothing is written
      [$result, $output] = $Boot($Command);
      yield assert(
         assertion: $result === true && $Same($kit) === true && str_contains($output, 'Agent rules laid down') === false,
         description: 'a kit whose rules match the templates is left untouched, got: ' . json_encode($output)
      );

      // # The framework's rules drift: a hand edit under the stamp, a stray file,
      //   a template reworded with the same set of files — each replaced
      file_put_contents("{$kit}/projects/AGENTS.md", "{$stamp}\n# edited by hand\n");
      [$result] = $Boot($Command);
      yield assert(
         assertion: $result === true && $Same($kit) === true,
         description: 'a hand edit of the stamped AGENTS.md is replaced by the template'
      );
      file_put_contents("{$kit}/projects/.agents/rules/Stray.md", "stray\n");
      [$result] = $Boot($Command);
      yield assert(
         assertion: $result === true && file_exists("{$kit}/projects/.agents/rules/Stray.md") === false,
         description: 'a stray file in .agents/rules/ goes with the whole-directory swap'
      );
      file_put_contents("{$rules}/.agents/rules/Alpha.md", "# Alpha\n\n- **MUST** — alpha, reworded.\n");
      [$result] = $Boot($Command);
      yield assert(
         assertion: $result === true && $Same($kit) === true,
         description: 'a template reworded with the same set of files reaches the kit'
      );

      // # The rest of .agents/ is the user's
      mkdir("{$kit}/projects/.agents/skills/deploy", 0775, true);
      file_put_contents("{$kit}/projects/.agents/skills/deploy/SKILL.md", "mine\n");
      file_put_contents("{$kit}/projects/AGENTS.md", "{$stamp}\n# edited again\n");
      [$result] = $Boot($Command);
      yield assert(
         assertion: $result === true && $Same($kit) === true
            && file_get_contents("{$kit}/projects/.agents/skills/deploy/SKILL.md") === "mine\n",
         description: 'a refresh never touches the user\'s own files elsewhere in .agents/'
      );

      // # A skill the templates dropped goes (its link too); the user's own
      //   skills and .claude/skills/ entries stay
      mkdir("{$kit}/projects/.agents/skills/bootgly-old", 0775, true);
      file_put_contents("{$kit}/projects/.agents/skills/bootgly-old/SKILL.md", $Skill('bootgly-old', 'Old.'));
      symlink('../../.agents/skills/bootgly-old', "{$kit}/projects/.claude/skills/bootgly-old");
      // ! Under the reserved prefix but unstamped — the user's, with a link shaped like ours
      mkdir("{$kit}/projects/.agents/skills/bootgly-older", 0775, true);
      file_put_contents("{$kit}/projects/.agents/skills/bootgly-older/SKILL.md", "mine\n");
      symlink('../../.agents/skills/bootgly-older', "{$kit}/projects/.claude/skills/bootgly-older");
      mkdir("{$kit}/projects/.claude/skills/own", 0775, true);
      file_put_contents("{$kit}/projects/AGENTS.md", "{$stamp}\n# drift\n");
      [$result] = $Boot($Command);
      yield assert(
         assertion: $result === true && file_exists("{$kit}/projects/.agents/skills/bootgly-old") === false
            && is_link("{$kit}/projects/.claude/skills/bootgly-old") === false
            && is_dir("{$kit}/projects/.claude/skills/own") === true
            && is_dir("{$kit}/projects/.agents/skills/deploy") === true
            && file_get_contents("{$kit}/projects/.agents/skills/bootgly-older/SKILL.md") === "mine\n"
            && is_link("{$kit}/projects/.claude/skills/bootgly-older") === true,
         description: 'a stamped skill no longer in the templates is removed with its link; the user\'s skills stay, an unstamped bootgly-* one and its link too'
      );

      // # A skill reworded in the templates — nothing else changed — reaches the kit
      file_put_contents("{$rules}/.agents/skills/bootgly-alpha/SKILL.md", $Skill('bootgly-alpha', 'Alpha, reworded.'));
      [$result] = $Boot($Command);
      yield assert(
         assertion: $result === true
            && file_get_contents("{$kit}/projects/.agents/skills/bootgly-alpha/SKILL.md") === file_get_contents("{$rules}/.agents/skills/bootgly-alpha/SKILL.md"),
         description: 'a reworded template skill reaches the kit on the next boot'
      );

      // # The Claude links: a deleted one comes back on an up-to-date boot; a
      //   real entry under a skill's name and a link pointing elsewhere are the user's
      unlink("{$kit}/projects/.claude/skills/bootgly-alpha");
      [$result, $output] = $Boot($Command);
      yield assert(
         assertion: $result === true && is_link("{$kit}/projects/.claude/skills/bootgly-alpha") === true
            && str_contains($output, 'Agent rules laid down') === false,
         description: 'a deleted Claude link is restored by an up-to-date boot, which writes nothing else, got: ' . json_encode($output)
      );
      unlink("{$kit}/projects/.claude/skills/bootgly-alpha");
      file_put_contents("{$kit}/projects/.claude/skills/bootgly-alpha", "mine\n");
      mkdir("{$base}/elsewhere", 0775, true);
      symlink("{$base}/elsewhere", "{$kit}/projects/.claude/skills/bootgly-mine");
      [$result] = $Boot($Command);
      yield assert(
         assertion: $result === true && is_link("{$kit}/projects/.claude/skills/bootgly-alpha") === false
            && file_get_contents("{$kit}/projects/.claude/skills/bootgly-alpha") === "mine\n"
            && is_link("{$kit}/projects/.claude/skills/bootgly-mine") === true,
         description: 'a real entry under a skill\'s name and a bootgly-* link pointing elsewhere are left alone'
      );
      unlink("{$kit}/projects/.claude/skills/bootgly-alpha");
      unlink("{$kit}/projects/.claude/skills/bootgly-mine");
      symlink("{$base}/elsewhere", "{$kit}/projects/.claude/skills/bootgly-alpha");
      [$result] = $Boot($Command);
      yield assert(
         assertion: $result === true && readlink("{$kit}/projects/.claude/skills/bootgly-alpha") === "{$base}/elsewhere",
         description: 'a link of the user\'s under a current skill\'s name is left pointing where they put it'
      );
      unlink("{$kit}/projects/.claude/skills/bootgly-alpha");
      $Boot($Command);

      // # A platform package set up in the kit brings its build skill — the
      //   framework names no platform: any kit-root `<Platform>/` with its
      //   `autoboot.php` whose `<Platform>/templates/projects/.agents/skills/`
      //   holds skills named `bootgly-<action>-<platform>`. Any other name it
      //   ships is ignored, a linked one too, and a framework skill wins
      $platform = "{$kit}/Acme/Acme/templates/projects/.agents/skills";
      mkdir("{$rules}/.agents/skills/bootgly-x-acme", 0775, true);
      file_put_contents("{$rules}/.agents/skills/bootgly-x-acme/SKILL.md", $Skill('bootgly-x-acme', 'Framework.'));
      foreach (['bootgly-build-acme' => 'Acme.', 'bootgly-deploy' => 'Unsuffixed.', 'bootgly-x-acme' => 'Platform.'] as $name => $description) {
         mkdir("{$platform}/{$name}", 0775, true);
         file_put_contents("{$platform}/{$name}/SKILL.md", $Skill($name, $description));
      }
      // ! Unstamped — laid once it would never be owned again; CRLF — a checkout's line endings keep the stamp
      mkdir("{$platform}/bootgly-plain-acme", 0775, true);
      file_put_contents("{$platform}/bootgly-plain-acme/SKILL.md", "---\nname: bootgly-plain-acme\ndescription: \"Plain.\"\n---\n\nPlain.\n");
      mkdir("{$platform}/bootgly-crlf-acme", 0775, true);
      file_put_contents("{$platform}/bootgly-crlf-acme/SKILL.md", str_replace("\n", "\r\n", $Skill('bootgly-crlf-acme', 'Windows.')));
      // ! A framework skill that is a link — never taken either
      mkdir("{$base}/framework-linked", 0775, true);
      file_put_contents("{$base}/framework-linked/SKILL.md", $Skill('bootgly-linked', 'Linked.'));
      symlink("{$base}/framework-linked", "{$rules}/.agents/skills/bootgly-linked");
      mkdir("{$base}/acme-linked", 0775, true);
      file_put_contents("{$base}/acme-linked/SKILL.md", $Skill('bootgly-link-acme', 'Linked.'));
      symlink("{$base}/acme-linked", "{$platform}/bootgly-link-acme");
      file_put_contents("{$kit}/Acme/autoboot.php", "<?php\n");
      [$result] = $Boot($Command);
      [, $again] = $Boot($Command);
      yield assert(
         assertion: $result === true
            && file_get_contents("{$kit}/projects/.agents/skills/bootgly-build-acme/SKILL.md") === file_get_contents("{$platform}/bootgly-build-acme/SKILL.md")
            && readlink("{$kit}/projects/.claude/skills/bootgly-build-acme") === '../../.agents/skills/bootgly-build-acme'
            && file_exists("{$kit}/projects/.agents/skills/bootgly-deploy") === false
            && file_exists("{$kit}/projects/.agents/skills/bootgly-link-acme") === false
            && file_exists("{$kit}/projects/.agents/skills/bootgly-linked") === false
            && file_exists("{$kit}/projects/.agents/skills/bootgly-plain-acme") === false
            && is_file("{$kit}/projects/.agents/skills/bootgly-crlf-acme/SKILL.md") === true
            && str_contains($again, 'kept') === false
            && file_get_contents("{$kit}/projects/.agents/skills/bootgly-x-acme/SKILL.md") === file_get_contents("{$rules}/.agents/skills/bootgly-x-acme/SKILL.md")
            && str_contains($again, 'Agent rules laid down') === false,
         description: 'a platform package\'s stamped bootgly-<action>-<platform> skill is laid down and linked (CRLF too); other names, unstamped and linked ones are ignored, the framework\'s wins; a second boot writes nothing and blocks nothing, got: ' . json_encode($again)
      );
      unlink("{$rules}/.agents/skills/bootgly-linked");
      // @ Two packages whose names differ only in case: neither is the platform
      //   (a case-insensitive filesystem cannot hold both: skipped there)
      mkdir("{$base}/case-probe/a", 0775, true);
      if (is_dir("{$base}/case-probe/A") === false) {
         mkdir("{$kit}/ACME/ACME/templates/projects/.agents/skills/bootgly-build-acme", 0775, true);
         file_put_contents("{$kit}/ACME/autoboot.php", "<?php\n");
         file_put_contents("{$kit}/ACME/ACME/templates/projects/.agents/skills/bootgly-build-acme/SKILL.md", $Skill('bootgly-build-acme', 'Shadow.'));
         [$result] = $Boot($Command);
         yield assert(
            assertion: $result === true && file_exists("{$kit}/projects/.agents/skills/bootgly-build-acme") === false,
            description: 'two platform packages whose names differ only in case bring no skill'
         );
         rename("{$kit}/ACME", "{$base}/acme-twin");
      }
      // @ Reworded by its package, it reaches the kit
      file_put_contents("{$platform}/bootgly-build-acme/SKILL.md", $Skill('bootgly-build-acme', 'Acme, reworded.'));
      [$result] = $Boot($Command);
      yield assert(
         assertion: $result === true
            && file_get_contents("{$kit}/projects/.agents/skills/bootgly-build-acme/SKILL.md") === file_get_contents("{$platform}/bootgly-build-acme/SKILL.md"),
         description: 'a platform skill reworded by its package reaches the kit on the next boot'
      );
      // @ A package removed from the kit (and a framework skill dropped) takes its skill and link away
      rename("{$kit}/Acme", "{$base}/acme-removed");
      rename("{$rules}/.agents/skills/bootgly-x-acme", "{$base}/x-acme-retired");
      [$result, $output] = $Boot($Command);
      yield assert(
         assertion: $result === true && file_exists("{$kit}/projects/.agents/skills/bootgly-build-acme") === false
            && is_link("{$kit}/projects/.claude/skills/bootgly-build-acme") === false
            && file_exists("{$kit}/projects/.agents/skills/bootgly-x-acme") === false
            && is_file("{$kit}/projects/.agents/skills/bootgly-alpha/SKILL.md") === true
            && str_contains($output, 'bootgly-build-acme') && str_contains($output, 'bootgly-x-acme'),
         description: 'a platform package removed from the kit takes its skill and link away, each removal named; the framework\'s stay, got: ' . json_encode($output)
      );

      // # A kit whose .agents/skills/ already holds bootgly-* entries of the
      //   user's before its first boot (they predate the reserved prefix):
      //   nothing of theirs is replaced or removed, and the blocked skill is said
      $prior = "{$base}/prior";
      mkdir("{$prior}/projects/.agents/skills/bootgly-deploy", 0775, true);
      mkdir("{$prior}/projects/.agents/skills/bootgly-alpha", 0775, true);
      // ! The stamp quoted in the body — not where `kit boot` stamps — is not Bootgly's
      $quoted = "---\nname: bootgly-deploy\ndescription: \"Mine.\"\n---\n\nMine, quoting:\n" . KitCommand::STAMP . ": do not edit. -->\n";
      file_put_contents("{$prior}/projects/.agents/skills/bootgly-deploy/SKILL.md", $quoted);
      file_put_contents("{$prior}/projects/.agents/skills/bootgly-alpha/NOTES.md", "mine\n");
      [$result, $output] = $Boot($Bind($prior, $templates));
      [, $again] = $Boot($Bind($prior, $templates));
      yield assert(
         assertion: $result === true && is_file("{$prior}/projects/.agents/rules/Alpha.md") === true
            && file_get_contents("{$prior}/projects/.agents/skills/bootgly-deploy/SKILL.md") === $quoted
            && file_get_contents("{$prior}/projects/.agents/skills/bootgly-alpha/NOTES.md") === "mine\n"
            && file_exists("{$prior}/projects/.agents/skills/bootgly-alpha/SKILL.md") === false
            && file_exists("{$prior}/projects/.claude/skills/bootgly-alpha") === false
            && str_contains($output, 'bootgly-alpha') && str_contains($output, "Rename yours and the next boot lays Bootgly's bootgly-alpha.")
            && str_contains($again, 'Agent rules laid down') === false,
         description: 'bootgly-* entries of the user\'s that predate the first boot are kept whole and the blocked skill is said; a second boot writes nothing, got: '
            . json_encode([$output, $again])
      );

      // # A link under a shipped skill's name — even to a stamped skill — is the user's
      $linkedSkill = "{$base}/linked-skill";
      mkdir("{$linkedSkill}/projects/.agents/skills", 0775, true);
      mkdir("{$base}/stamped-elsewhere", 0775, true);
      file_put_contents("{$base}/stamped-elsewhere/SKILL.md", $Skill('bootgly-alpha', 'Elsewhere.'));
      symlink("{$base}/stamped-elsewhere", "{$linkedSkill}/projects/.agents/skills/bootgly-alpha");
      [$result, $output] = $Boot($Bind($linkedSkill, $templates));
      yield assert(
         assertion: $result === true && is_link("{$linkedSkill}/projects/.agents/skills/bootgly-alpha") === true
            && file_get_contents("{$base}/stamped-elsewhere/SKILL.md") === $Skill('bootgly-alpha', 'Elsewhere.')
            && str_contains($output, 'bootgly-alpha'),
         description: 'a link under a shipped skill\'s name is kept and said, never replaced nor written through, got: ' . json_encode($output)
      );

      // # A linked .claude/skills/ is the user's: no link is written through it
      $claudeShelf = "{$base}/claude-shelf";
      mkdir("{$claudeShelf}/projects/.claude", 0775, true);
      mkdir("{$base}/their-skills", 0775, true);
      symlink("{$base}/their-skills", "{$claudeShelf}/projects/.claude/skills");
      [$result, $output] = $Boot($Bind($claudeShelf, $templates));
      yield assert(
         assertion: $result === true && is_file("{$claudeShelf}/projects/AGENTS.md") === true
            && is_link("{$base}/their-skills/bootgly-alpha") === false && file_exists("{$base}/their-skills/bootgly-alpha") === false
            && str_contains($output, 'Claude links skipped'),
         description: 'no skill link is written through a linked .claude/skills/, and that is said, got: ' . json_encode($output)
      );

      // # A linked .agents/skills/ is the user's: nothing is written through it,
      //   and the boot converges (the rules alone are compared)
      $agentShelf = "{$base}/agent-shelf";
      mkdir("{$agentShelf}/projects/.agents", 0775, true);
      mkdir("{$base}/their-agent-skills", 0775, true);
      symlink("{$base}/their-agent-skills", "{$agentShelf}/projects/.agents/skills");
      mkdir("{$base}/agent-shelf-claude", 0775, true);
      symlink("{$base}/agent-shelf-claude", "{$agentShelf}/projects/.claude");
      [$result] = $Boot($Bind($agentShelf, $templates));
      [, $again] = $Boot($Bind($agentShelf, $templates));
      yield assert(
         assertion: $result === true && is_file("{$agentShelf}/projects/.agents/rules/Alpha.md") === true
            && file_exists("{$base}/their-agent-skills/bootgly-alpha") === false
            && str_contains($again, 'Agent rules laid down') === false && str_contains($again, 'Skills skipped')
            && str_contains($again, 'Claude links skipped') === false,
         description: 'no skill is written through a linked .agents/skills/, and a second boot writes nothing, got: ' . json_encode($again)
      );

      // # A link in place of .agents/rules/ is replaced; what it pointed at is left alone
      $outside = "{$base}/outside";
      mkdir($outside, 0775, true);
      file_put_contents("{$outside}/Alpha.md", "outside\n");
      rename("{$kit}/projects/.agents/rules", "{$base}/retired-rules");
      symlink($outside, "{$kit}/projects/.agents/rules");
      [$result] = $Boot($Command);
      yield assert(
         assertion: $result === true && is_link("{$kit}/projects/.agents/rules") === false && $Same($kit) === true
            && file_get_contents("{$outside}/Alpha.md") === "outside\n",
         description: 'a link in place of .agents/rules/ is replaced by a real directory, and its target is left alone'
      );

      // # A swap that fails midway is rolled back: the kit keeps its previous
      //   set (root writes everywhere: skipped there)
      if (function_exists('posix_geteuid') === false || posix_geteuid() !== 0) {
         file_put_contents("{$kit}/projects/.agents/rules/Alpha.md", "drifted\n");
         file_put_contents("{$kit}/projects/.agents/skills/bootgly-alpha/SKILL.md", $Skill('bootgly-alpha', 'Drifted.'));
         chmod("{$kit}/projects/.agents/skills", 0555);
         try {
            [$result, $output] = $Boot($Command, ['agents' => true]);
         }
         finally {
            chmod("{$kit}/projects/.agents/skills", 0775);
         }
         yield assert(
            assertion: $result === false && str_contains($output, 'Could not lay down')
               && file_get_contents("{$kit}/projects/.agents/rules/Alpha.md") === "drifted\n",
            description: 'a skill swap that fails rolls back the rules swap before it — the kit keeps its previous set, got: ' . json_encode($output)
         );
         [$result] = $Boot($Command);
         yield assert(
            assertion: $result === true && $Same($kit) === true
               && file_get_contents("{$kit}/projects/.agents/skills/bootgly-alpha/SKILL.md") === file_get_contents("{$rules}/.agents/skills/bootgly-alpha/SKILL.md"),
            description: 'the next boot lays the whole set down'
         );

         // @ A stale skill with an unreadable subtree: it leaves its place whole,
         //   the boot never throws, and the staging keeps only what it cannot read
         mkdir("{$kit}/projects/.agents/skills/bootgly-gone/deep/inner", 0775, true);
         file_put_contents("{$kit}/projects/.agents/skills/bootgly-gone/SKILL.md", $Skill('bootgly-gone', 'Gone.'));
         chmod("{$kit}/projects/.agents/skills/bootgly-gone/deep", 0000);
         file_put_contents("{$kit}/projects/.agents/rules/Alpha.md", "drifted again\n");
         try {
            [$result, $output] = $Boot($Command);
         }
         finally {
            foreach (["{$kit}/projects/.agents/skills/bootgly-gone/deep", ...(array) glob("{$kit}/projects/.bootgly.*/stale-bootgly-gone/deep")] as $deep) {
               @chmod((string) $deep, 0775);
            }
         }
         $leftovers = (array) glob("{$kit}/projects/.bootgly.*/*");
         $inside = array_map(static fn ($path): string => basename((string) $path), (array) glob("{$kit}/projects/.bootgly.*/stale-bootgly-gone/*"));
         yield assert(
            assertion: $result === true && file_exists("{$kit}/projects/.agents/skills/bootgly-gone") === false
               && str_contains($output, 'bootgly-gone') && $Same($kit) === true
               && array_map(static fn ($path): string => basename((string) $path), $leftovers) === ['stale-bootgly-gone']
               && $inside === ['deep'],
            description: 'a stale skill that cannot be read whole leaves its place, everything readable in the staging goes, and the boot completes, got: '
               . json_encode([$output, $leftovers, $inside])
         );
         foreach ((array) glob("{$kit}/projects/.bootgly.*") as $leftover) {
            $Wipe = Closure::bind(function (string $path): void {
               $this->wipe($path);
            }, $Command, KitCommand::class);
            $Wipe((string) $leftover);
         }

         // @ A stale skill that cannot leave its place (moving a directory
         //   rewrites its `..`) is said as such — never called removed
         mkdir("{$kit}/projects/.agents/skills/bootgly-stuck", 0775, true);
         file_put_contents("{$kit}/projects/.agents/skills/bootgly-stuck/SKILL.md", $Skill('bootgly-stuck', 'Stuck.'));
         chmod("{$kit}/projects/.agents/skills/bootgly-stuck", 0555);
         try {
            [$result, $output] = $Boot($Command);
         }
         finally {
            chmod("{$kit}/projects/.agents/skills/bootgly-stuck", 0775);
         }
         yield assert(
            assertion: $result === true && is_file("{$kit}/projects/.agents/skills/bootgly-stuck/SKILL.md") === true
               && str_contains($output, 'bootgly-stuck could not be removed') && str_contains($output, 'bootgly-stuck removed') === false,
            description: 'a stale skill that cannot be moved away is said to stay, got: ' . json_encode($output)
         );
         [$result, $output] = $Boot($Command);
         yield assert(
            assertion: $result === true && file_exists("{$kit}/projects/.agents/skills/bootgly-stuck") === false
               && str_contains($output, 'bootgly-stuck removed'),
            description: 'once it can move, the next boot removes it, got: ' . json_encode($output)
         );
      }

      // # An interrupted run's staging is swept once it is old — a concurrent
      //   run's live staging (fresh) is not
      mkdir("{$kit}/projects/.bootgly.4242.0123456789ab/rules", 0700, true);
      touch("{$kit}/projects/.bootgly.4242.0123456789ab", time() - 3600);
      mkdir("{$kit}/projects/.bootgly.4343.0123456789ab", 0700);
      file_put_contents("{$kit}/projects/AGENTS.md", "{$stamp}\n# drift\n");
      [$result] = $Boot($Command);
      yield assert(
         assertion: $result === true && file_exists("{$kit}/projects/.bootgly.4242.0123456789ab") === false
            && is_dir("{$kit}/projects/.bootgly.4343.0123456789ab") === true,
         description: 'an old leftover staging directory is swept, a fresh one (a concurrent run) is left'
      );
      rmdir("{$kit}/projects/.bootgly.4343.0123456789ab");

      // # The user's own AGENTS.md — no stamp — is never replaced, and said
      $theirs = "{$base}/theirs";
      mkdir("{$theirs}/projects", 0775, true);
      file_put_contents("{$theirs}/projects/AGENTS.md", "# Use pnpm. Generated code is Machine-managed by our codegen.\n");
      [$result, $output] = $Boot($Bind($theirs, $templates));
      yield assert(
         assertion: $result === true
            && file_get_contents("{$theirs}/projects/AGENTS.md") === "# Use pnpm. Generated code is Machine-managed by our codegen.\n"
            && file_exists("{$theirs}/projects/.agents/rules") === false && str_contains($output, 'is not Bootgly'),
         description: 'an unstamped AGENTS.md is left alone and the boot says why (still a successful boot), got: ' . json_encode($output)
      );
      [$result] = $Boot($Bind($theirs, $templates), ['agents' => true]);
      yield assert(
         assertion: $result === true && file_exists("{$theirs}/projects/.agents/rules") === false,
         description: 'a skip is not a failure: `kit boot --agents` succeeds and still lays nothing over the user\'s entry point'
      );

      // # A stamped entry point under a linked .agents/: the link leads outside
      //   the kit — neither laid through nor written
      $linkedAgents = "{$base}/linked-agents";
      mkdir("{$linkedAgents}/projects", 0775, true);
      mkdir("{$base}/dotfiles/rules", 0775, true);
      file_put_contents("{$base}/dotfiles/rules/Mine.md", "mine\n");
      file_put_contents("{$linkedAgents}/projects/AGENTS.md", "{$stamp}\n# laid once\n");
      symlink("{$base}/dotfiles", "{$linkedAgents}/projects/.agents");
      [$result, $output] = $Boot($Bind($linkedAgents, $templates));
      yield assert(
         assertion: $result === true && file_get_contents("{$base}/dotfiles/rules/Mine.md") === "mine\n"
            && file_exists("{$base}/dotfiles/rules/Alpha.md") === false && str_contains($output, 'projects/.agents is not Bootgly'),
         description: 'a linked .agents/ is never laid through, even under a stamped AGENTS.md — and it is the path named, got: ' . json_encode($output)
      );
      // @ Rules with no entry point beside them: that path is named
      $bare = "{$base}/bare-rules";
      mkdir("{$bare}/projects/.agents/rules", 0775, true);
      [$result, $output] = $Boot($Bind($bare, $templates));
      yield assert(
         assertion: $result === true && str_contains($output, 'projects/.agents/rules is not Bootgly'),
         description: 'rules without an entry point are left alone, and that path is named, got: ' . json_encode($output)
      );

      // # A link in place of AGENTS.md is the user's: neither it nor its target is written
      $linked = "{$base}/linked";
      mkdir("{$linked}/projects", 0775, true);
      file_put_contents("{$base}/victim.txt", "secret\n");
      symlink("{$base}/victim.txt", "{$linked}/projects/AGENTS.md");
      [$result] = $Boot($Bind($linked, $templates));
      yield assert(
         assertion: $result === true && is_link("{$linked}/projects/AGENTS.md") === true
            && file_get_contents("{$base}/victim.txt") === "secret\n",
         description: 'an AGENTS.md link is left alone, and what it points at is never written'
      );

      // # A linked .claude/ is the user's: no skill link is written through it
      $claudeLinked = "{$base}/claude-linked";
      mkdir("{$claudeLinked}/projects", 0775, true);
      mkdir("{$base}/their-claude", 0775, true);
      symlink("{$base}/their-claude", "{$claudeLinked}/projects/.claude");
      [$result, $output] = $Boot($Bind($claudeLinked, $templates));
      yield assert(
         assertion: $result === true && is_file("{$claudeLinked}/projects/AGENTS.md") === true
            && file_exists("{$base}/their-claude/skills") === false && str_contains($output, 'Claude links skipped: projects/.claude is'),
         description: 'the rules are laid, but no link is written through a linked .claude/ — and that is said, got: ' . json_encode($output)
      );
      // @ A .claude/skills/ that already leads to .agents/skills/ serves Claude Code: nothing to say
      $through = "{$base}/claude-through";
      mkdir("{$through}/projects/.agents/skills", 0775, true);
      mkdir("{$through}/projects/.claude", 0775, true);
      symlink('../.agents/skills', "{$through}/projects/.claude/skills");
      [$result, $output] = $Boot($Bind($through, $templates));
      yield assert(
         assertion: $result === true && is_file("{$through}/projects/.claude/skills/bootgly-alpha/SKILL.md") === true
            && str_contains($output, 'Claude links skipped') === false,
         description: 'a .claude/skills/ linked to .agents/skills/ reads every skill and is not reported, got: ' . json_encode($output)
      );
      // @ A file in place of .agents/skills/ is said too
      $filed = "{$base}/filed-shelf";
      mkdir("{$filed}/projects/.agents", 0775, true);
      file_put_contents("{$filed}/projects/.agents/skills", "mine\n");
      [$result, $output] = $Boot($Bind($filed, $templates));
      yield assert(
         assertion: $result === true && file_get_contents("{$filed}/projects/.agents/skills") === "mine\n"
            && str_contains($output, 'Skills skipped: projects/.agents/skills is not a directory.'),
         description: 'a file in place of .agents/skills/ is left alone and said, got: ' . json_encode($output)
      );

      // # The sets: --resources lays no rules, --agents lays nothing else
      $sets = "{$base}/sets";
      mkdir($sets, 0775, true);
      [$result] = $Boot($Bind($sets, $templates), ['resources' => true]);
      yield assert(
         assertion: $result === true && is_file("{$sets}/projects/Bootgly.projects.php") === true
            && file_exists("{$sets}/projects/AGENTS.md") === false,
         description: '`kit boot --resources` lays down the directories only'
      );
      $only = "{$base}/only";
      mkdir("{$only}/projects", 0775, true);
      [$result] = $Boot($Bind($only, $templates), ['agents' => true]);
      yield assert(
         assertion: $result === true && is_file("{$only}/projects/AGENTS.md") === true
            && is_dir("{$only}/scripts") === false && is_dir("{$only}/storage") === false
            && is_file("{$only}/projects/Bootgly.projects.php") === false,
         description: '`kit boot --agents` lays down the rules only'
      );

      // # A framework that predates the rules lays nothing
      $bare = "{$base}/bare";
      mkdir($bare, 0775, true);
      [$result, $output] = $Boot($Bind($bare, $old));
      yield assert(
         assertion: $result === true && file_exists("{$bare}/projects/AGENTS.md") === false && file_exists("{$bare}/projects/.agents") === false,
         description: 'a framework without the templates lays no agent rules, got: ' . json_encode($output)
      );

      // ---

      // # After a move — `refresh()`, called once the swap landed
      $Refresh = static function (KitCommand $Command): array {
         // ! Private to KitCommand — bound to its scope, not the subclass's
         $Run = Closure::bind(function (): array {
            $this->refresh();

            return $this->document;
         }, $Command, KitCommand::class);

         return $Run();
      };

      // @ A release that predates the rules (the kit's own Bootgly/ has no
      //   templates): the framework's go, the user's own .agents/ files stay
      mkdir("{$kit}/Bootgly", 0775, true);
      [$document] = $Capture(static fn (): array => $Refresh($Bind($kit, $old)));
      yield assert(
         assertion: ($document['agents'] ?? null) === 'removed'
            && file_exists("{$kit}/projects/AGENTS.md") === false && file_exists("{$kit}/projects/.agents/rules") === false
            && file_exists("{$kit}/projects/.agents/skills/bootgly-alpha") === false
            && is_link("{$kit}/projects/.claude/skills/bootgly-alpha") === false
            && is_dir("{$kit}/projects/.claude/skills/own") === true
            && file_get_contents("{$kit}/projects/.agents/skills/deploy/SKILL.md") === "mine\n"
            && file_get_contents("{$kit}/projects/.agents/skills/bootgly-older/SKILL.md") === "mine\n"
            && is_link("{$kit}/projects/.claude/skills/bootgly-older") === true,
         description: 'moving to a release without the templates removes Bootgly\'s rules and stamped skills only, got: ' . json_encode($document)
      );
      // @ …and never through a linked .claude/, nor a real entry under a skill's name
      $dotted = "{$base}/dotted";
      mkdir("{$dotted}/Bootgly", 0775, true);
      mkdir("{$dotted}/projects/.agents/rules", 0775, true);
      mkdir("{$base}/dotfiles-claude/skills/bootgly-deploy", 0775, true);
      file_put_contents("{$base}/dotfiles-claude/skills/bootgly-deploy/SKILL.md", "mine\n");
      // ! A link there shaped like one of ours — still the user's, behind their .claude/
      symlink('../../.agents/skills/bootgly-alpha', "{$base}/dotfiles-claude/skills/bootgly-alpha");
      file_put_contents("{$dotted}/projects/Bootgly.projects.php", "<?php\n\nreturn [];\n");
      file_put_contents("{$dotted}/projects/AGENTS.md", "{$stamp}\n");
      symlink("{$base}/dotfiles-claude", "{$dotted}/projects/.claude");
      [$document] = $Capture(static fn (): array => $Refresh($Bind($dotted, $old)));
      yield assert(
         assertion: ($document['agents'] ?? null) === 'removed'
            && file_get_contents("{$base}/dotfiles-claude/skills/bootgly-deploy/SKILL.md") === "mine\n"
            && is_link("{$base}/dotfiles-claude/skills/bootgly-alpha") === true,
         description: 'a downgrade never deletes through a linked .claude/, got: ' . json_encode($document)
      );
      $real = "{$base}/real-claude";
      mkdir("{$real}/Bootgly", 0775, true);
      mkdir("{$real}/projects/.claude/skills/bootgly-notes", 0775, true);
      file_put_contents("{$real}/projects/.claude/skills/bootgly-notes/SKILL.md", "mine\n");
      file_put_contents("{$real}/projects/Bootgly.projects.php", "<?php\n\nreturn [];\n");
      file_put_contents("{$real}/projects/AGENTS.md", "{$stamp}\n");
      [$document] = $Capture(static fn (): array => $Refresh($Bind($real, $old)));
      yield assert(
         assertion: ($document['agents'] ?? null) === 'removed'
            && file_get_contents("{$real}/projects/.claude/skills/bootgly-notes/SKILL.md") === "mine\n",
         description: 'a downgrade never deletes a real .claude/skills/bootgly-* entry, got: ' . json_encode($document)
      );

      // @ …never an AGENTS.md without the stamp, whatever it mentions
      file_put_contents("{$kit}/projects/AGENTS.md", "# ours — Machine-managed by our codegen\n");
      [$document] = $Capture(static fn (): array => $Refresh($Bind($kit, $old)));
      yield assert(
         assertion: ($document['agents'] ?? null) === 'kept'
            && file_get_contents("{$kit}/projects/AGENTS.md") === "# ours — Machine-managed by our codegen\n",
         description: 'an unstamped AGENTS.md is kept by the refresh, got: ' . json_encode($document)
      );
      // @ …nor anything through a linked .agents/, under a stamped entry point
      mkdir("{$linkedAgents}/Bootgly", 0775, true);
      file_put_contents("{$linkedAgents}/projects/Bootgly.projects.php", "<?php\n\nreturn [];\n");
      [$document] = $Capture(static fn (): array => $Refresh($Bind($linkedAgents, $old)));
      yield assert(
         assertion: ($document['agents'] ?? null) === 'kept' && file_get_contents("{$base}/dotfiles/rules/Mine.md") === "mine\n",
         description: 'a downgrade never deletes through a linked .agents/, got: ' . json_encode($document)
      );

      // @ A release that carries them: its own launcher re-lays them (a stub
      //   launcher stands in, leaving a trace of the call)
      file_put_contents("{$kit}/projects/AGENTS.md", "{$stamp}\n");
      mkdir("{$kit}/Bootgly/Bootgly/commands/templates/projects", 0775, true);
      copy("{$rules}/AGENTS.md", "{$kit}/Bootgly/Bootgly/commands/templates/projects/AGENTS.md");
      file_put_contents("{$kit}/bootgly", "<?php file_put_contents(__DIR__ . '/called', implode(' ', array_slice(\$argv, 1)));\n");
      [$document] = $Capture(static fn (): array => $Refresh($Bind($kit, $templates)));
      yield assert(
         assertion: ($document['agents'] ?? null) === 'refreshed'
            && is_file("{$kit}/called") === true && file_get_contents("{$kit}/called") === 'kit boot --agents',
         description: 'moving to a release with the templates runs its launcher\'s `kit boot --agents`, got: ' . json_encode($document)
      );
      // @ A launcher that writes a lot never stalls the move (its output is discarded)
      file_put_contents("{$kit}/bootgly", "<?php fwrite(STDERR, str_repeat('x', 300000)); fwrite(STDOUT, str_repeat('y', 300000));\n");
      [$document] = $Capture(static fn (): array => $Refresh($Bind($kit, $templates)));
      yield assert(
         assertion: ($document['agents'] ?? null) === 'refreshed',
         description: 'a launcher flooding stdout and stderr still completes, got: ' . json_encode($document)
      );
      // @ A launcher that fails is reported, never hidden
      file_put_contents("{$kit}/bootgly", "<?php exit(1);\n");
      [$document] = $Capture(static fn (): array => $Refresh($Bind($kit, $templates)));
      yield assert(
         assertion: ($document['agents'] ?? null) === 'failed',
         description: 'a re-lay that fails is reported as failed, got: ' . json_encode($document)
      );
      // @ A kit never booted is left for its first `projects create` — even
      //   with templates and a launcher that would leave a trace
      $fresh = "{$base}/fresh";
      mkdir("{$fresh}/Bootgly/Bootgly/commands/templates/projects", 0775, true);
      copy("{$rules}/AGENTS.md", "{$fresh}/Bootgly/Bootgly/commands/templates/projects/AGENTS.md");
      file_put_contents("{$fresh}/bootgly", "<?php file_put_contents(__DIR__ . '/called', 'called');\n");
      [$document] = $Capture(static fn (): array => $Refresh($Bind($fresh, $templates)));
      yield assert(
         assertion: isset($document['agents']) === false && file_exists("{$fresh}/called") === false,
         description: 'a kit without a registry is not touched by the refresh, got: ' . json_encode($document)
      );

      // @ Nothing the refresh meets escapes it (root reads and writes everything:
      //   skipped there): an unreadable subtree leaves its place whole with the
      //   rest, and a place that cannot be emptied is reported, the stamp kept
      if (function_exists('posix_geteuid') === false || posix_geteuid() !== 0) {
         $locked = "{$base}/locked";
         mkdir("{$locked}/Bootgly", 0775, true);
         mkdir("{$locked}/projects/.agents/rules/sub", 0775, true);
         mkdir("{$locked}/projects/.agents/skills/bootgly-alpha/references/private", 0775, true);
         file_put_contents("{$locked}/projects/.agents/skills/bootgly-alpha/SKILL.md", $Skill('bootgly-alpha', 'Alpha.'));
         file_put_contents("{$locked}/projects/Bootgly.projects.php", "<?php\n\nreturn [];\n");
         file_put_contents("{$locked}/projects/AGENTS.md", "{$stamp}\n");
         chmod("{$locked}/projects/.agents/rules/sub", 0000);
         chmod("{$locked}/projects/.agents/skills/bootgly-alpha/references/private", 0000);
         try {
            [$document, $said] = $Capture(static fn (): array => $Refresh($Bind($locked, $old)));
         }
         finally {
            foreach ([
               "{$locked}/projects/.agents/rules/sub",
               "{$locked}/projects/.agents/skills/bootgly-alpha/references/private",
               ...(array) glob("{$locked}/projects/.bootgly.*/rules/sub"),
               ...(array) glob("{$locked}/projects/.bootgly.*/skill-*/references/private"),
            ] as $path) {
               @chmod((string) $path, 0775);
            }
         }
         $kept = array_map(static fn ($path): string => (string) preg_replace('#^.*/\.bootgly\.\d+\.[0-9a-f]{12}/#', '', (string) $path), [
            ...(array) glob("{$locked}/projects/.bootgly.*/*"),
            ...(array) glob("{$locked}/projects/.bootgly.*/*/*"),
            ...(array) glob("{$locked}/projects/.bootgly.*/*/*/*"),
         ]);
         sort($kept);
         yield assert(
            assertion: ($document['agents'] ?? null) === 'removed' && file_exists("{$locked}/projects/AGENTS.md") === false
               && file_exists("{$locked}/projects/.agents") === false
               && $kept === ['rules', 'rules/sub', 'skill-bootgly-alpha', 'skill-bootgly-alpha/references', 'skill-bootgly-alpha/references/private']
               && str_contains($said, 'Left projects/.bootgly.'),
            description: 'unreadable subtrees leave with the rules and skills they are in — no remnant under a shipped name, only the unreadable is left and it is named, got: '
               . json_encode([$document, $kept, $said])
         );

         $stuck = "{$base}/stuck";
         mkdir("{$stuck}/Bootgly", 0775, true);
         mkdir("{$stuck}/projects/.agents/rules", 0775, true);
         file_put_contents("{$stuck}/projects/Bootgly.projects.php", "<?php\n\nreturn [];\n");
         file_put_contents("{$stuck}/projects/AGENTS.md", "{$stamp}\n");
         chmod("{$stuck}/projects/.agents", 0555);
         try {
            [$document] = $Capture(static fn (): array => $Refresh($Bind($stuck, $old)));
         }
         finally {
            chmod("{$stuck}/projects/.agents", 0775);
         }
         yield assert(
            assertion: ($document['agents'] ?? null) === 'failed' && is_file("{$stuck}/projects/AGENTS.md") === true
               && is_dir("{$stuck}/projects/.agents/rules") === true,
            description: 'rules the refresh cannot move away are reported as failed — never thrown into the move — and the stamped entry point stays, got: ' . json_encode($document)
         );

         // @ All or nothing: a stamped skill that cannot leave (its shelf is
         //   read-only), or a shelf that cannot be listed — what moved goes
         //   back, the whole stamped set stays, and the way out is said
         foreach (['read-only' => 0555, 'unlistable' => 0000] as $label => $mode) {
            $half = "{$base}/half-{$label}";
            mkdir("{$half}/Bootgly", 0775, true);
            mkdir("{$half}/projects/.agents/rules", 0775, true);
            mkdir("{$half}/projects/.agents/skills/bootgly-alpha", 0775, true);
            file_put_contents("{$half}/projects/.agents/rules/Alpha.md", "alpha\n");
            file_put_contents("{$half}/projects/.agents/skills/bootgly-alpha/SKILL.md", $Skill('bootgly-alpha', 'Alpha.'));
            file_put_contents("{$half}/projects/Bootgly.projects.php", "<?php\n\nreturn [];\n");
            file_put_contents("{$half}/projects/AGENTS.md", "{$stamp}\n");
            chmod("{$half}/projects/.agents/skills", $mode);
            try {
               [$document, $said] = $Capture(static fn (): array => $Refresh($Bind($half, $old)));
            }
            finally {
               chmod("{$half}/projects/.agents/skills", 0775);
            }
            yield assert(
               assertion: ($document['agents'] ?? null) === 'failed' && is_file("{$half}/projects/AGENTS.md") === true
                  && file_get_contents("{$half}/projects/.agents/rules/Alpha.md") === "alpha\n"
                  && is_file("{$half}/projects/.agents/skills/bootgly-alpha/SKILL.md") === true
                  && glob("{$half}/projects/.bootgly.*") === [] && str_contains($said, 'by hand'),
               description: "a {$label} skills shelf fails the removal whole: the rules come back, the stamp and the skill stay, the way out is said, got: "
                  . json_encode([$document, $said])
            );
         }
      }

      // # A fresh kit's first `projects create` lays the rules too (the
      //   installer and the Docker wizard go through it)
      $created = Temporaries::reserve('kit-agent-rules-create');
      file_put_contents(
         "{$created}/bootgly",
         "<?php\n"
            . "define('BOOTGLY_WORKING_BASE', __DIR__);\n"
            . "define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);\n"
            . "(include '" . BOOTGLY_ROOT_DIR . "autoboot.php') || exit(1);\n"
      );
      $environment = getenv();
      $environment['AI_AGENT'] = '1';
      $process = proc_open(
         [PHP_BINARY, '-d', 'opcache.jit=0', "{$created}/bootgly", 'projects', 'create', 'Hello', '--yes', '--platform=none', '--interfaces=CLI', '--no-git'],
         [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
         $pipes,
         $created,
         $environment
      );
      $status = is_resource($process) === true ? proc_close($process) : -1;
      yield assert(
         assertion: $status === 0 && is_file("{$created}/projects/Hello/Hello.Project.php") === true
            && str_starts_with((string) @file_get_contents("{$created}/projects/AGENTS.md"), KitCommand::STAMP)
            && is_dir("{$created}/projects/.agents/rules") === true,
         description: '`projects create` on a fresh kit lays projects/AGENTS.md and .agents/rules/, got status ' . $status
      );

      // # A platform set up on a prepared kit (`--platform=`) brings its build skill
      file_put_contents("{$created}/.gitmodules", "[submodule \"Web\"]\n\tpath = Web\n");
      mkdir("{$created}/Web/Web/templates/projects/.agents/skills/bootgly-build-web", 0775, true);
      file_put_contents("{$created}/Web/autoboot.php", "<?php\n");
      file_put_contents("{$created}/Web/Web/templates/projects/.agents/skills/bootgly-build-web/SKILL.md", $Skill('bootgly-build-web', 'Web.'));
      $process = proc_open(
         [PHP_BINARY, '-d', 'opcache.jit=0', "{$created}/bootgly", 'projects', 'create', 'Again', '--yes', '--platform=web', '--interfaces=CLI', '--no-git'],
         [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
         $pipes,
         $created,
         $environment
      );
      $status = is_resource($process) === true ? proc_close($process) : -1;
      yield assert(
         assertion: $status === 0 && is_file("{$created}/projects/Again/Again.Project.php") === true
            && is_file("{$created}/projects/.agents/skills/bootgly-build-web/SKILL.md") === true
            && is_link("{$created}/projects/.claude/skills/bootgly-build-web") === true,
         description: '`projects create --platform=web` on a prepared kit lays that platform\'s build skill, got status ' . $status
      );

      // # A real move calls it: a fixture kit whose releases predate the rules
      $fixture = (require __DIR__ . '/fixtures/kit_fixture.php')("{$base}/lineage");
      $moved = $fixture['clone']('moved', 'refs/tags/v1.0.0');
      file_put_contents("{$moved}/projects/Bootgly.projects.php", "<?php\n\nreturn [];\n");
      copy("{$rules}/AGENTS.md", "{$moved}/projects/AGENTS.md");
      mkdir("{$moved}/projects/.agents/rules", 0775, true);
      copy("{$rules}/.agents/rules/Alpha.md", "{$moved}/projects/.agents/rules/Alpha.md");
      $Mover = new class ($moved, $fixture['canon']) extends KitCommand {
         public function __construct (string $kit, string $repository)
         {
            parent::__construct();
            $this->kit = $kit;
            $this->repository = $repository;
         }

         // ! The registry and the pid files belong to the process's own kit, not the fixture's
         protected function scan (): array
         {
            return [];
         }
      };
      [$result, $output] = $Capture(static fn (): bool => $Mover->run(['upgrade'], ['json' => true, 'yes' => true]));
      $document = json_decode($output, true);
      yield assert(
         assertion: $result === true && ($document['status'] ?? null) === 'moved' && ($document['agents'] ?? null) === 'removed'
            && file_exists("{$moved}/projects/AGENTS.md") === false && file_exists("{$moved}/projects/.agents") === false
            && is_file("{$moved}/projects/App/notes.txt") === true,
         description: '`kit upgrade` to a release without the rules removes them and nothing else of projects/, got: ' . json_encode($document)
      );
   }
);
