# Production PostgreSQL access and local refresh

Use Tailscale for SSH to the Hetzner/Dokku host, run `pg_dump -Fc` inside
its PostgreSQL container, write the compressed archive on the host, and rsync
it to the Mac. Restore it using `pg_restore` (not `pg-load`).

This keeps the database connection near the server, uses its matching client,
and allows interrupted downloads to resume. Dumping directly from the Mac
would also work with a reachable database and compatible client, but makes the
entire dump depend on the network connection. For these databases, prefer the
server-side archive. The Dokku **application image** is not the place to dump:
the database container already has the right tools. No public database port or
PostgreSQL exposure to the tailnet is required.

## Verified setup (2026-10-10)

Tailscale was recently installed (reported by the operator). These details were
checked from this Mac; this is an inventory, not a record of the install commands:

| Component | Observed configuration |
| --- | --- |
| Mac | `tacs-macbook-pro`, Tailscale `100.87.239.127`, online |
| Hetzner host | Tailscale `fsn1`, `100.122.226.25`, online; OS hostname `fsn1-survos` |
| SSH | `fsn1-root` selects root and normally resolves `fsn1.survos.com` |
| Production database container | Docker `survos_pg`, `timescale/timescaledb-ha:pg18.4-ts2.29.1-all` |
| Local database container | Podman `survos_postgres`, same image, port `5434` to container `5432` |
| Tools | Production `pg_dump` and local `pg_restore` both 18.4 |
| Production sizes | Mediary approximately 7282 MB; Lingua 1608 MB |
| Existing local sizes | Mediary approximately 2989 MB; Lingua 11 MB |
| Database extensions | Mediary: `plpgsql`; Lingua: `plpgsql`, `postgres_fdw` |

`bin/live-ssh` uses ordinary SSH over the Tailscale IP, with the existing
`fsn1-root` identity settings and `fsn1.survos.com` host-key identity. This path
was verified successfully. It does not enable or assume Tailscale SSH, change
ACLs, install software, or change `~/.ssh/config`.

```bash
tailscale status
bin/live-ssh fsn1-root hostname
bin/live-ssh fsn1-root docker exec survos_pg pg_dump --version
```

The helper disables PTY allocation, including for binary-safe transport. It uses
batch authentication and fails rather than prompting. Host key verification
remains enabled. Optional overrides: `LIVE_TAILSCALE_HOST`,
`LIVE_HOST_KEY_ALIAS`, and the sync script's `PROD_SSH`.

## Refresh procedure

The helper in this repository supports both apps. Run it here for Lingua too;
it does not require copying it into the Lingua repository.

1. Inspect both plans. No arguments defaults to a Mediary plan; it no longer
   immediately replaces the database.

   ```bash
   bin/backup-live.sh plan mediary
   bin/backup-live.sh plan lingua
   ```

2. Create production snapshots, one at a time. Credentials are read from each
   Dokku app's `DATABASE_URL` on the host. Doctrine query options are removed;
   `host.docker.internal` becomes `localhost` inside the database container.
   Dumps exclude rows in `public.messenger_messages` and
   `public.processed_messages` but preserve those tables.

   ```bash
   bin/backup-live.sh dump mediary
   bin/backup-live.sh dump lingua
   ```

   Archives live at `/root/backups/<app>_live.dump`. A lock prevents two writers
   for the same archive, and a failed dump does not replace the previous one.
   A killed remote process can leave a lock directory: verify no dump is still
   running before removing that specific lock. Each app has its own consistent
   snapshot; the pair is not an atomic cross-database snapshot.

3. Fetch each archive. **Retry `fetch`, not `dump`, after an interrupted transfer.**
   The remote SHA-256 is checked before and after transfer, and against the
   downloaded file. Downloads use a partial file; verified archives go into
   ignored `var/backups/`, with restrictive permissions.

   ```bash
   bin/backup-live.sh fetch mediary
   bin/backup-live.sh fetch lingua
   ```

4. Stop local web requests, schedulers, and workers using these databases.
   Confirm each app's local `DATABASE_URL` points to `127.0.0.1:5434` with the
   intended database, not SQLite or production. Disable outbound email,
   callbacks, AI jobs and other external writes before using production data.
   Database snapshots do not copy S3 objects, search indexes, or other services.
   Review Lingua's foreign server/user mappings before querying foreign tables.

5. Preserve the existing local databases before replacing them. Use unique
   names for rollback archives; for example on this Mac:

   ```bash
   mkdir -p var/backups
   (umask 077; podman exec survos_postgres pg_dump -U postgres -Fc mediary > var/backups/mediary-before-refresh.dump)
   (umask 077; podman exec survos_postgres pg_dump -U postgres -Fc lingua > var/backups/lingua-before-refresh.dump)
   ```

   Check both commands succeed and inspect the archives with `pg_restore --list`
   after copying them into the local container. Do not commit or share dumps;
   they contain live application data. Files in a dev3 worktree disappear when
   the task is completed: set `HOST_DUMP` to private persistent storage if needed.

6. Replace the local databases explicitly. This drops the selected database,
   disconnects its sessions, creates it, restores it, and runs `ANALYZE`.
   Archive checks and a local runtime check happen before dropping anything.

   ```bash
   bin/backup-live.sh restore mediary --replace-local
   bin/backup-live.sh restore lingua --replace-local
   ```

   This is not an atomic restore: a restore failure leaves a partial local
   database and returns failure. Keep consumers stopped, fix the reported
   issue, and retry or restore the rollback archive. The live databases are
   only read. No migrations run automatically.

7. Check schema/migration compatibility from each application's environment
   (`doctrine:schema:validate`, `doctrine:migrations:up-to-date`), compare key
   table counts with the snapshot expectations, and smoke-test locally before
   restarting consumers. Avoid treating later live row counts as an exact match
   to an earlier snapshot.

## Configuration and limits

Supported environment overrides: `PROD_SSH`, `PROD_PG_CONTAINER`, `PROD_APP`
(default app when omitted), `PG_CONTAINER`, `LOCAL_DB`, `LOCAL_USER`, `JOBS`
(default 4), `REMOTE_DUMP_PATH`, `HOST_DUMP`, and `CTR=docker|podman`.
Use the same overrides for every stage. Connection identifiers are deliberately
restricted. Remote archive paths must be absolute and contain no spaces.

The restore runtime must use a local Docker Unix socket or a local Podman
socket/VM. Podman connection environment overrides are refused. This checks
client configuration, not the identity of arbitrary socket proxies. Verify the
plan and runtime yourself. Keep the source and destination on PostgreSQL 18
with matching extensions; `pg_restore --list` alone is not a full restore test.

The old `REMOTE_DUMP=0` and `PROD_DATABASE_URL` modes are refused explicitly.
Direct SQL access can be added later with a read-only role and a loopback SSH
tunnel after inspecting the server's actual published ports. It is not needed
for refreshes. No server firewall, database grants, or tailnet policy changes
have been made by these tools.

## References

- [PostgreSQL pg_dump](https://www.postgresql.org/docs/18/app-pgdump.html)
- [PostgreSQL pg_restore](https://www.postgresql.org/docs/18/app-pgrestore.html)
- [Ordinary SSH over Tailscale versus Tailscale SSH](https://tailscale.com/docs/reference/ssh-over-tailscale)
