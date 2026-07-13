#!/bin/sh
# ----------------------------------------------------------------------------
# Bootgly Docker entrypoint
#
# A bare `docker run -it bootgly/bootgly` opens the canonical project
# installer (Wizard) on the first interactive run — explicit commands
# (`docker run bootgly/bootgly test`, `... project X start`) always bypass.
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
         bootgly project create
         touch "$MARKER"

         exit 0
      fi

      echo "First run: use \`docker run -it bootgly/bootgly\` to open the project installer."
   fi

   set -- help
fi

# : Any explicit command goes straight to the framework CLI
exec bootgly "$@"
