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

# ? `docker-default` is the image CMD — the bare `docker run` path only
if [ "${1:-}" = 'docker-default' ]; then
   if [ ! -e "$MARKER" ]; then
      # @ First interactive run — canonical installer (wizard)
      if [ -t 0 ]; then
         bootgly projects create
         touch "$MARKER"

         exit 0
      fi

      echo "First run: use \`docker run -it bootgly/bootgly.kit:<version>\` (name a tag) to open the project installer."
   fi

   set -- help
fi

# : Any explicit command goes straight to the framework CLI
exec bootgly "$@"
