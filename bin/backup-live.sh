#!/usr/bin/env bash
# Production -> local only. See docs/database-sync.md.
set -euo pipefail
umask 077
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ACTION="${1:-plan}"
APP="${2:-${PROD_APP:-mediary}}"
case "$ACTION" in plan|dump|fetch|restore) ;; *) echo "Usage: $0 [plan|dump|fetch|restore] [mediary|lingua] [--replace-local]" >&2; exit 2;; esac
case "$APP" in mediary|lingua) ;; *) echo 'App must be mediary or lingua.' >&2; exit 2;; esac
[[ $# -le 3 ]] || { echo 'Too many arguments.' >&2; exit 2; }
if [[ $# -eq 3 && ( "$ACTION" != restore || "$3" != --replace-local ) ]]; then
  echo 'Only restore accepts --replace-local.' >&2; exit 2
fi
PROD_SSH="${PROD_SSH:-fsn1-root}"
PROD_PG_CONTAINER="${PROD_PG_CONTAINER:-survos_pg}"
CONTAINER="${PG_CONTAINER:-survos_postgres}"
LOCAL_DB="${LOCAL_DB:-$APP}"
LOCAL_USER="${LOCAL_USER:-postgres}"
JOBS="${JOBS:-4}"
REMOTE_DUMP_PATH="${REMOTE_DUMP_PATH:-/root/backups/${APP}_live.dump}"
HOST_DUMP="${HOST_DUMP:-${SCRIPT_DIR}/../var/backups/${APP}_live.dump}"
# Restrict interpolated identifiers and paths used by SSH, rsync and SQL.
for identifier in "$LOCAL_DB" "$LOCAL_USER" "$PROD_PG_CONTAINER" "$CONTAINER"; do
  [[ "$identifier" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]] || { echo "Invalid identifier: $identifier" >&2; exit 2; }
done
[[ "$LOCAL_DB" != postgres && "$LOCAL_DB" != template* ]] || { echo 'Refusing a system database.' >&2; exit 2; }
[[ "$REMOTE_DUMP_PATH" =~ ^/[a-zA-Z0-9_./-]+$ && "$PROD_SSH" =~ ^[a-zA-Z0-9_@.-]+$ && "$PROD_SSH" != -* && "$JOBS" =~ ^[1-9][0-9]*$ ]] || { echo 'Invalid path, host or job count.' >&2; exit 2; }
if [[ -n "${PROD_DATABASE_URL:-}" || "${REMOTE_DUMP:-1}" != 1 ]]; then
  echo 'Direct DSN/stream mode is retired. Credentials are resolved on production; use dump then fetch.' >&2; exit 2
fi
if [[ "$ACTION" == plan ]]; then
  printf 'Source: %s via Tailscale (%s), app %s, container %s\n' "$PROD_SSH" "${LIVE_TAILSCALE_HOST:-100.122.226.25}" "$APP" "$PROD_PG_CONTAINER"
  printf 'Archive: %s -> %s\nLocal target: %s / %s (owner %s)\n' "$REMOTE_DUMP_PATH" "$HOST_DUMP" "$CONTAINER" "$LOCAL_DB" "$LOCAL_USER"
  printf 'Run: %s dump %s\nThen: %s fetch %s\nAfter local backup and stopping workers: %s restore %s --replace-local\n' "$0" "$APP" "$0" "$APP" "$0" "$APP"
  exit 0
fi
if [[ "$ACTION" == dump ]]; then
  # Arguments are restricted above. No credentials cross SSH or enter the local shell.
  "$SCRIPT_DIR/live-ssh" "$PROD_SSH" "bash -s -- '$APP' '$PROD_PG_CONTAINER' '$REMOTE_DUMP_PATH'" <<'REMOTE'
set -euo pipefail
umask 077
app=$1; container=$2; archive=$3
mkdir -p "$(dirname "$archive")"
mkdir "$archive.lock" || { echo 'Archive busy; inspect lock before retrying.' >&2; exit 1; }
trap 'rm -f "$archive.partial"; rmdir "$archive.lock"' EXIT
url=$(dokku config:get "$app" DATABASE_URL)
[[ -n "$url" ]] || { echo "Missing DATABASE_URL for $app" >&2; exit 1; }
url=${url/host.docker.internal/localhost}
url=${url%%\?*}
# pg_dump runs beside the database; the archive occupies host storage.
docker exec "$container" pg_dump -Fc --no-owner --no-privileges \
  --exclude-table-data=public.messenger_messages \
  --exclude-table-data=public.processed_messages "$url" > "$archive.partial"
mv "$archive.partial" "$archive"
ls -lh "$archive"
REMOTE
  exit 0
fi
if [[ "$ACTION" == fetch ]]; then
  mkdir -p "$(dirname "$HOST_DUMP")"
  # Hash before and after transfer catches a concurrent replacement of the archive.
  remote_hash() { "$SCRIPT_DIR/live-ssh" "$PROD_SSH" "sha256sum '$REMOTE_DUMP_PATH'" | awk '{print $1}'; }
  before=$(remote_hash)
  [[ "$before" =~ ^[a-f0-9]{64}$ ]] || { echo 'Invalid remote checksum.' >&2; exit 1; }
  # Separate partial destination never replaces a previously verified local archive.
  printf -v transport 'bash %q' "$SCRIPT_DIR/live-ssh"
  rsync --partial --progress -e "$transport" "$PROD_SSH:$REMOTE_DUMP_PATH" "$HOST_DUMP.partial"
  after=$(remote_hash)
  actual=$(shasum -a 256 "$HOST_DUMP.partial" | awk '{print $1}')
  [[ "$before" == "$after" && "$after" == "$actual" ]] || { echo 'Archive changed or transfer corrupt. Run fetch again.' >&2; exit 1; }
  mv "$HOST_DUMP.partial" "$HOST_DUMP"
  printf '%s\n' "$actual" > "$HOST_DUMP.sha256"
  echo "Verified archive: $HOST_DUMP"
  exit 0
fi
[[ "${3:-}" == --replace-local ]] || { echo 'Restore replaces the local database. Inspect plan, back it up, then pass --replace-local.' >&2; exit 2; }
CTR="${CTR:-}"
if [[ -z "$CTR" ]]; then
  if docker info >/dev/null 2>&1; then CTR=docker
  elif podman info >/dev/null 2>&1; then CTR=podman
  else echo 'No working local Docker or Podman runtime.' >&2; exit 1; fi
fi
# Refuse configured remote runtimes: a container name alone does not prove locality.
if [[ "$CTR" == podman ]]; then
  [[ -z "${CONTAINER_HOST:-}${CONTAINER_CONNECTION:-}" ]] || { echo 'Unset Podman connection overrides before restore.' >&2; exit 1; }
  uri=$(podman system connection list --format '{{if .Default}}{{.URI}}{{end}}')
  [[ "$uri" == ssh://*@127.0.0.1:* || "$uri" == unix://* ]] || { echo 'Podman must use a local socket or local VM.' >&2; exit 1; }
elif [[ "$CTR" == docker ]]; then
  if [[ -n "${DOCKER_CONTEXT:-}" ]]; then
    endpoint=$(docker context inspect "$DOCKER_CONTEXT" --format '{{.Endpoints.docker.Host}}')
  else
    endpoint="${DOCKER_HOST:-$(docker context inspect --format '{{.Endpoints.docker.Host}}')}"
  fi
  [[ "$endpoint" == unix://* ]] || { echo 'Docker must use a local Unix socket.' >&2; exit 1; }
else echo 'CTR must be docker or podman.' >&2; exit 2; fi
[[ -s "$HOST_DUMP" && -s "$HOST_DUMP.sha256" ]] || { echo 'Run fetch first to obtain a verified archive.' >&2; exit 1; }
actual=$(shasum -a 256 "$HOST_DUMP" | awk '{print $1}')
[[ "$actual" == "$(cat "$HOST_DUMP.sha256")" ]] || { echo 'Local archive checksum mismatch.' >&2; exit 1; }
DUMP="/tmp/${LOCAL_DB}_live.dump"
"$CTR" cp "$HOST_DUMP" "$CONTAINER:$DUMP"
"$CTR" exec "$CONTAINER" pg_restore --list "$DUMP" >/dev/null
"$CTR" exec "$CONTAINER" psql -X -U "$LOCAL_USER" -d postgres -v ON_ERROR_STOP=1 -c 'SELECT version();'
echo "Replacing LOCAL $CONTAINER / $LOCAL_DB"
"$CTR" exec "$CONTAINER" psql -X -U "$LOCAL_USER" -d postgres -v ON_ERROR_STOP=1 \
  -c "DROP DATABASE IF EXISTS \"$LOCAL_DB\" WITH (FORCE);" \
  -c "CREATE DATABASE \"$LOCAL_DB\" OWNER \"$LOCAL_USER\";"
if ! "$CTR" exec "$CONTAINER" pg_restore --exit-on-error --no-owner --no-privileges \
  -j "$JOBS" -U "$LOCAL_USER" -d "$LOCAL_DB" "$DUMP"; then
  echo 'Restore FAILED: local database may be incomplete. Keep workers stopped; resolve the error and repeat restore.' >&2
  exit 1
fi
"$CTR" exec "$CONTAINER" psql -X -U "$LOCAL_USER" -d "$LOCAL_DB" -v ON_ERROR_STOP=1 \
  -c 'ANALYZE;' -c 'SELECT current_database(), pg_size_pretty(pg_database_size(current_database()));'
echo 'Restore complete. Queue rows excluded; no migrations run. Review app configuration before starting workers.'
