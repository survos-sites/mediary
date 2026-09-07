#!/usr/bin/env bash
#
# backup-live.sh — load a copy of the LIVE mediary database into the local
# shared postgres (survos_postgres :5434) so we can test migrations against
# real data.
#
#   bin/backup-live.sh                 # dump on prod + rsync down (default, resumable)
#   REMOTE_DUMP=0 bin/backup-live.sh   # stream pg_dump across the WAN instead
#
# Dumps prod with `pg_dump -Fc` (custom format), drops & recreates the LOCAL
# `mediary` database, and restores into it. STOPS after the restore — review
# and run migrations yourself afterwards.
#
# Transfer: by default step 1 dumps on the prod host and rsyncs the file down
# (rsync -P resumes a partial transfer; just re-run) — robust for large tables
# like asset (~1.8 GB). Set REMOTE_DUMP=0 to stream the dump across the WAN
# instead, but that COPY stream can stall on a flaky link with no way to resume.
# See the config block below for PROD_SSH / PROD_PG_CONTAINER overrides.
#
# The prod connection string is read on the PROD host from dokku's own config at
# dump time, so no password lives in this file, in .env.local, or in your shell
# history. PROD_DATABASE_URL still overrides it if you need a different database.
#
# Runtime: works with either docker or podman locally — detected, not assumed.
# The Mac has the docker BINARY but no daemon, which is why every `docker exec`
# in the original version failed there.
#
# Updated 2026-09-06: PROD_SSH was `dokku_root` (not a real ssh alias),
# REMOTE_PGDUMP pointed at /usr/lib/postgresql/18/bin/pg_dump (fsn1 has no native
# postgres install), REMOTE_DUMP_PATH at /mnt/volume-1 (does not exist), and the
# credential lookup grepped .env.local for an IP that is no longer the DB host.
# Each of those was fatal on its own; the script could not have run as written.
# The transient queue tables (messenger_messages, processed_messages) are
# excluded — they're requeued after the upgrade.
#
# On indexes: there's no need to drop them first. `pg_restore` from a -Fc
# archive loads table DATA first, then builds indexes/constraints afterward
# (one bulk build each, not row-by-row). `-j` parallelizes those builds and
# maintenance_work_mem speeds them up.

set -uo pipefail
cd "$(dirname "$0")/.."   # project root

# Local container runtime. The Mac runs podman and has NO docker daemon: the `docker`
# binary exists on PATH but every call dies with "failed to connect to the docker API at
# unix:///var/run/docker.sock". This script used `docker` throughout and could therefore
# never have run here. Auto-detect instead of hardcoding either one, so the same script
# works on the lemur (docker) and the Mac (podman).
CTR="${CTR:-}"
if [[ -z "${CTR}" ]]; then
  if docker info >/dev/null 2>&1; then CTR=docker; elif command -v podman >/dev/null 2>&1; then CTR=podman; else
    echo "ERROR: neither a working docker daemon nor podman found locally." >&2; exit 1; fi
fi

CONTAINER="${PG_CONTAINER:-survos_postgres}"   # shared local postgres (ships pg18 client tools)
LOCAL_DB="${LOCAL_DB:-mediary}"
LOCAL_USER="${LOCAL_USER:-postgres}"
JOBS="${JOBS:-4}"
DUMP="/tmp/${LOCAL_DB}_live.dump"   # path inside ${CONTAINER} that pg_restore reads

# --- transfer mode -----------------------------------------------------------
# Default (REMOTE_DUMP=1): run pg_dump ON the prod host and rsync the file down
# (resumable). Strongly preferred for large tables (e.g. asset ~1.8 GB): rsync -P
# picks up where it left off, whereas a streamed COPY stalls on a flaky link with
# no resume. Set REMOTE_DUMP=0 to stream across the WAN instead. Requires SSH.
#   REMOTE_DUMP=0 bin/backup-live.sh   # opt out, stream instead
# The DB has its own IP (in ${PROD_URL}); we SSH to the dokku app host, which
# reaches the DB over the fast datacenter network, dump there, then rsync down.
REMOTE_DUMP="${REMOTE_DUMP:-1}"
# `dokku_root` is not a real alias on this Mac — `ssh -G dokku_root` resolves to the literal
# host "dokku_root", so every run failed at the first ssh. The authorized root alias is
# fsn1-root (root@fsn1.survos.com); plain `fsn1` is the unprivileged dokku user.
PROD_SSH="${PROD_SSH:-fsn1-root}"
# /mnt/volume-1 does not exist on fsn1 (checked: only / , 150G with ~69G free), so the old
# default put the dump on a path that isn't there.
REMOTE_DUMP_PATH="${REMOTE_DUMP_PATH:-/root/backups/${LOCAL_DB}_live.dump}"
HOST_DUMP="${HOST_DUMP:-/tmp/${LOCAL_DB}_live.dump}"                # local staging path for rsync
# There is NO native pg_dump on fsn1 (/usr/lib/postgresql does not exist; pg_dump is not on
# PATH) — production Postgres runs in a container. Dump from inside it, which also guarantees
# the client matches the server (both 18.4) rather than hoping a host package does.
PROD_PG_CONTAINER="${PROD_PG_CONTAINER:-survos_pg}"
PROD_APP="${PROD_APP:-mediary}"

# --- prod connection ----------------------------------------------------------
# The old default grepped .env.local for the literal IP 178.156.199.185. That line is gone,
# and the address is stale anyway: mediary's DATABASE_URL is now
# host.docker.internal:5432/mediary, i.e. Postgres on the fsn1 host itself. With no match the
# script exited "no prod connection string" before doing anything.
#
# Rather than reinstate a password in a local file, the credentials are now read ON the prod
# host from dokku's own config at dump time (see step 1a) and never leave it. Two rewrites are
# needed there: host.docker.internal means "the host" only from inside an app container, so it
# becomes localhost inside the DB container; and the ?serverVersion=…&charset=… query string is
# Doctrine's, not libpq's, so it must be stripped or pg_dump rejects the parameters.
#
# PROD_DATABASE_URL still overrides, for dumping something other than the dokku app's own DB.
PROD_URL="${PROD_DATABASE_URL:-}"

echo "==> 1/3  Dump LIVE mediary  ($(date +%T))  — custom format; messenger queue data excluded, asset INCLUDED"

# Shared exclude flags (word-split intentionally; no spaces within a token).
EXCLUDES="--exclude-table-data=public.messenger_messages --exclude-table-data=public.processed_messages"

if [[ "${REMOTE_DUMP}" == "1" ]]; then
  echo "    mode: dump on prod (${PROD_SSH}) then rsync down — resumable; best for the large asset table."
  echo "    1a) pg_dump inside ${PROD_PG_CONTAINER} on ${PROD_SSH} -> ${REMOTE_DUMP_PATH}"
  # Single-quoted heredoc: expanded on the PROD host, so the URL is resolved there and the
  # password never reaches this machine, this script, or the terminal. -Fc writes binary to
  # stdout, redirected straight to a host file — no space used inside the container.
  if ! ssh "${PROD_SSH}" "REMOTE_DUMP_PATH='${REMOTE_DUMP_PATH}' PROD_PG_CONTAINER='${PROD_PG_CONTAINER}' PROD_APP='${PROD_APP}' PROD_URL='${PROD_URL}' EXCLUDES='${EXCLUDES}' bash -s" <<'REMOTE'
set -euo pipefail
if [[ -z "${PROD_URL}" ]]; then
  PROD_URL=$(dokku config:get "${PROD_APP}" DATABASE_URL)
fi
# Doctrine DSN -> libpq DSN, from inside the database container.
PROD_URL=${PROD_URL/host.docker.internal/localhost}
PROD_URL=${PROD_URL%%\?*}
if [[ -z "${PROD_URL}" ]]; then echo "no DATABASE_URL for ${PROD_APP}" >&2; exit 1; fi
mkdir -p "$(dirname "${REMOTE_DUMP_PATH}")"
docker exec -i "${PROD_PG_CONTAINER}" pg_dump -Fc --no-owner --no-privileges ${EXCLUDES} "${PROD_URL}" > "${REMOTE_DUMP_PATH}"
ls -lh "${REMOTE_DUMP_PATH}"
REMOTE
  then
    echo "ERROR: remote pg_dump failed. LOCAL ${LOCAL_DB} left untouched." >&2
    exit 1
  fi
  echo "    1b) rsync ${PROD_SSH}:${REMOTE_DUMP_PATH} -> ${HOST_DUMP}   (-P resumes if interrupted; just re-run)"
  rsync -P "${PROD_SSH}:${REMOTE_DUMP_PATH}" "${HOST_DUMP}" || {
    echo "ERROR: rsync failed. Re-run to resume — the partial ${HOST_DUMP} is kept." >&2; exit 1; }
  echo "    1c) ${CTR} cp ${HOST_DUMP} -> ${CONTAINER}:${DUMP}"
  "${CTR}" cp "${HOST_DUMP}" "${CONTAINER}:${DUMP}" || {
    echo "ERROR: ${CTR} cp into ${CONTAINER} failed." >&2; exit 1; }
else
  echo "         mode: stream pg_dump COPY across the WAN — fine for small tables, but the"
  echo "         ~1.8 GB asset table can stall on a flaky link (no resume). Prefer REMOTE_DUMP=1."
  echo "         watch from another shell: ${CTR} exec ${CONTAINER} ls -lh ${DUMP}"
  time "${CTR}" exec "${CONTAINER}" pg_dump -Fc --no-owner --no-privileges \
    ${EXCLUDES} \
    "${PROD_URL}" -f "${DUMP}"
  dump_rc=$?
  if [[ ${dump_rc} -ne 0 ]]; then
    echo "ERROR: pg_dump failed (rc=${dump_rc}). LOCAL ${LOCAL_DB} left untouched." >&2
    exit 1
  fi
fi
"${CTR}" exec "${CONTAINER}" ls -lh "${DUMP}"

echo "==> 2/3  Recreate LOCAL ${LOCAL_DB}  ($(date +%T))  — isolated; other project DBs untouched"
"${CTR}" exec "${CONTAINER}" psql -U "${LOCAL_USER}" -v ON_ERROR_STOP=1 \
  -c "DROP DATABASE IF EXISTS ${LOCAL_DB} WITH (FORCE);" \
  -c "CREATE DATABASE ${LOCAL_DB} OWNER ${LOCAL_USER};" \
  -c "ALTER DATABASE ${LOCAL_DB} SET maintenance_work_mem='1GB';" || {
    echo "ERROR: could not recreate ${LOCAL_DB}." >&2; exit 1; }

echo "==> 3/3  Restore  ($(date +%T))  — data first, then indexes (parallel -j ${JOBS}); -v shows each object"
time "${CTR}" exec "${CONTAINER}" pg_restore --no-owner --no-privileges \
  -j "${JOBS}" -v -U "${LOCAL_USER}" -d "${LOCAL_DB}" "${DUMP}"
echo "    pg_restore exit $? (non-zero is usually harmless warnings — extensions/comments)"

echo
echo "==> Local ${LOCAL_DB} size:  $("${CTR}" exec "${CONTAINER}" psql -U "${LOCAL_USER}" -d "${LOCAL_DB}" -tAc "SELECT pg_size_pretty(pg_database_size('${LOCAL_DB}'))")"
echo
echo "STOPPED after restore (no migrations run). When ready to test migrations:"
echo "  1) comment out the sqlite DATABASE_URL in .env.local so postgres:5434 is used"
echo "  2) php bin/console doctrine:migrations:up-to-date"
echo "  3) php bin/console doctrine:migrations:migrate --dry-run"
