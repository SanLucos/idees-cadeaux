#!/bin/sh
# Production database backups (docker-compose.prod.yaml, service `backup`):
# one compressed pg_dump a day in /backups, those older than
# BACKUP_RETENTION_DAYS deleted (spec §5.13: an erased account leaves the
# backups within 30 days). Restore: docs/DEPLOIEMENT.md.
set -eu

RETENTION=${BACKUP_RETENTION_DAYS:-30}

until pg_isready -q; do sleep 2; done

while true; do
  file="/backups/db-$(date -u +%Y-%m-%d).dump"
  if [ ! -f "$file" ]; then
    # Written aside, then renamed: a half-written dump never looks complete.
    if pg_dump --format=custom --file="$file.partial"; then
      mv "$file.partial" "$file"
      echo "$(date -u +%FT%TZ) backup written: $file"
    else
      rm -f "$file.partial"
      echo "$(date -u +%FT%TZ) backup FAILED" >&2
    fi
  fi
  find /backups -name 'db-*.dump' -mtime +"$((RETENTION - 1))" -print -delete
  sleep 3600
done
