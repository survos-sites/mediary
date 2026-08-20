# Dokku's Procfile support overrides the Dockerfile's CMD per process type -- even
# under the dockerfile builder -- so a stale `web:` line silently keeps the old
# broken command. This is the FrankenPHP one; the workers carry over unchanged.
web: frankenphp run --config /etc/caddy/Caddyfile

# Every name after the colon must be a REAL transport. `download: ... asset.download` used to
# live here and there is no such receiver -- messenger exits 1 with "The receiver
# "asset.download" does not exist", so that process type crash-looped from the day it was added
# and its absence was invisible next to nine siblings that looked fine. Check against
# `bin/console messenger:consume --help` (it lists valid receivers) before adding a line.
#
# WARNING -- these need `dokku ps:set mediary restart-policy unless-stopped`.
# `messenger:consume --time-limit` exits 0 when the limit is reached, and docker's
# `on-failure` policy (the dokku default) does NOT restart a container that exited 0.
# Under on-failure every worker below dies for good one hour after deploy and never
# returns -- visible as `Status <worker> 1: missing` while web keeps running and the
# queues silently grow.
meili: php -d memory_limit=768M bin/console messenger:consume meili --time-limit=3600 --memory-limit=640M
info: php -d memory_limit=768M bin/console messenger:consume asset.info --time-limit=3600 --memory-limit=640M
archive: php -d memory_limit=768M bin/console messenger:consume asset.archive --time-limit=3600 --memory-limit=640M
ocr: php -d memory_limit=768M bin/console messenger:consume asset.local.ocr --time-limit=3600 --memory-limit=640M
iiif: php -d memory_limit=768M bin/console messenger:consume asset.iiif --time-limit=3600 --memory-limit=640M
analyze: php -d memory_limit=768M bin/console messenger:consume asset.analyze --time-limit=3600 --memory-limit=640M
# triage and ai were missing entirely, so nothing consumed them: an asset reached `archived`
# and stopped, because the transition out of it had no worker. Measured 2026-08-20 on
# production -- 452,331 assets parked at `archived`, 410,892 of them with an S3 object and no
# width, and exactly 6 at `complete` out of 652,747. It looked like a stalled pipeline; it was
# a missing process type.
triage: php -d memory_limit=768M bin/console messenger:consume asset.triage --time-limit=3600 --memory-limit=640M
ai: php -d memory_limit=768M bin/console messenger:consume asset.ai.task --time-limit=3600 --memory-limit=640M
delete: php -d memory_limit=768M bin/console messenger:consume asset.delete --time-limit=3600 --memory-limit=640M
scheduler: php -d memory_limit=768M bin/console messenger:consume scheduler_default --time-limit=3600 --memory-limit=640M
webhook: php bin/console messenger:consume webhook --time-limit=3600 --memory-limit=256M
