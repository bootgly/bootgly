#!/bin/sh
# ----------------------------------------------------------------------------
# Bootgly Docker entrypoint
#
# A bare `docker run -it bootgly/bootgly.kit:<version>` opens the canonical
# project installer (Wizard) on the first interactive run — explicit commands
# (`docker run bootgly/bootgly.kit:<version> test`, `... project X start`)
# always bypass. The image must be named WITH a tag: `latest` is published only
# on a stable release, so an untagged `bootgly/bootgly.kit` does not resolve
# while Bootgly is in pre-release. No channel is hard-coded here on purpose —
# `rc` and `beta` exist only while a pre-release of that channel is the newest
# one, so a baked-in `:rc` can outlive the tag it names.
# The marker lives in projects/, so mounting it as a volume scopes the
# "first run" to the volume; without a volume every fresh container is a
# first run.
# ----------------------------------------------------------------------------
set -e

MARKER=/bootgly/projects/.initialized

# ! A storage volume the platform mounted empty and root-owned gets, on a root
#   start, the layout the kit image creates at build time — an image layer never
#   reaches a mount. What the demoted workers write goes to the kit's runtime
#   identity (`bootgly`): logs/ and sessions/ (0700). The storage root, pids/
#   and cache/ stay root's: root-run code reads what cache/ holds. An image
#   without that account has no such layout to mirror: nothing is prepared, and
#   the server creates what it needs for the identity its project configures.
#   Only a directory created here changes owner — never an existing one, never
#   recursively, never through a link (the runtime identity owns what is inside
#   logs/ and sessions/). A link where a directory is expected stops the start.
#   Skipped under `--user`, and for a storage root that is not root's or that
#   its group or others can write — those the framework judges on its own.
#   BOOTGLY_STORAGE_DIR moves the directory: the framework validates it first.
prepare () {
   # ? A storage directory named by the environment is the framework's to judge
   #   first: its refusal is the one message, and nothing is created for it
   if [ -n "${BOOTGLY_STORAGE_DIR+set}" ]; then
      bootgly --version >/dev/null || return 1
   fi

   # ? Root only, and only where the kit's runtime identity exists
   [ "$(id -u)" = '0' ] || return 0
   id -u bootgly >/dev/null 2>&1 || return 0

   storage="${BOOTGLY_STORAGE_DIR:-/bootgly/storage}"
   # ? Absolute and canonical, with one trailing separator accepted. A boot
   #   that passed over anything else did not take the variable (a launcher
   #   pre-defined the constant): there is no directory here to prepare
   case "$storage" in
      /*) ;;
      *) return 0 ;;
   esac
   storage="${storage%/}"
   case "$storage/" in
      /|*//*|*/./*|*/../*) return 0 ;;
   esac

   # ? Never through a link, never over anything but a directory root owns alone
   if [ -L "$storage" ]; then
      echo "bootgly: storage is not prepared: symbolic link: $storage" >&2
      return 1
   fi
   [ -d "$storage" ] || return 0
   state="$(stat -c '%u %A' "$storage")" || {
      echo "bootgly: storage is not prepared: cannot be inspected: $storage" >&2
      return 1
   }
   case "$state" in
      '0 '?????w*|'0 '????????w*) return 0 ;;
      '0 '*) ;;
      *) return 0 ;;
   esac

   # ? A link or a file where a directory is expected stops the start, before
   #   anything is created
   layout='cache locks logs pids queues schedule sessions temp tests'
   for name in $layout; do
      path="$storage/$name"

      if [ -L "$path" ]; then
         echo "bootgly: storage is not prepared: symbolic link: $path" >&2
         return 1
      fi
      if [ -e "$path" ] && [ ! -d "$path" ]; then
         echo "bootgly: storage is not prepared: not a directory: $path" >&2
         return 1
      fi
   done

   # @@ Create what is missing
   for name in $layout; do
      path="$storage/$name"

      [ -d "$path" ] && continue

      case "$name" in
         logs|sessions)
            # @ What the demoted workers write is made under a name nothing
            #   reads, handed over, then moved into place: a start interrupted
            #   halfway never leaves a logs/ or sessions/ that is still root's
            case "$name" in
               sessions) mode=0700 ;;
               *) mode=0755 ;;
            esac
            staged="$storage/.$name.prepare"
            rmdir "$staged" 2>/dev/null || true
            mkdir -m "$mode" "$staged" || {
               echo "bootgly: storage is not prepared: cannot create: $path" >&2
               return 1
            }
            chown -h bootgly:bootgly "$staged" || {
               rmdir "$staged" 2>/dev/null || true
               echo "bootgly: storage is not prepared: cannot give to bootgly: $path" >&2
               return 1
            }
            mv -T "$staged" "$path" || {
               rmdir "$staged" 2>/dev/null || true
               echo "bootgly: storage is not prepared: cannot create: $path" >&2
               return 1
            }
            ;;
         *)
            mkdir -m 0755 "$path" || {
               echo "bootgly: storage is not prepared: cannot create: $path" >&2
               return 1
            }
            ;;
      esac
   done
}
prepare || exit 1

# ? `docker-default` is the image CMD — the bare `docker run` path only
if [ "${1:-}" = 'docker-default' ]; then
   if [ ! -e "$MARKER" ]; then
      # @ First interactive run — canonical installer (wizard)
      if [ -t 0 ]; then
         bootgly projects create
         touch "$MARKER"
         # ! The marker belongs to whoever mounted projects/, like the rest
         chown -h --reference=/bootgly/projects "$MARKER" 2>/dev/null || true

         exit 0
      fi

      echo "First run: use \`docker run -it bootgly/bootgly.kit:<version>\` (name a tag) to open the project installer."
   fi

   set -- help
fi

# @ The agent rules and skills in projects/ follow the image's framework and
#   platforms: a host-mounted projects/ never sees `kit upgrade`, so every start
#   of a prepared kit re-lays them when they drifted (`kit boot --agents` touches
#   only Bootgly's own, stamped files and its .claude/skills links)
if [ -f /bootgly/projects/Bootgly.projects.php ]; then
   bootgly kit boot --agents >/dev/null 2>&1 \
      || echo 'bootgly: the agent rules in projects/ were not refreshed — `bootgly kit boot --agents` says why.' >&2
fi

# : Any explicit command goes straight to the framework CLI
exec bootgly "$@"
