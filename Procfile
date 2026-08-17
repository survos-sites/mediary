web: vendor/bin/heroku-php-nginx -C nginx.conf -F fpm_custom.conf public/
meili: php -d memory_limit=768M bin/console messenger:consume meili --time-limit=3600 --memory-limit=640M
info: php -d memory_limit=768M bin/console messenger:consume asset.info --time-limit=3600 --memory-limit=640M
archive: php -d memory_limit=768M bin/console messenger:consume asset.archive --time-limit=3600 --memory-limit=640M
ocr: php -d memory_limit=768M bin/console messenger:consume asset.local.ocr --time-limit=3600 --memory-limit=640M
iiif: php -d memory_limit=768M bin/console messenger:consume asset.iiif --time-limit=3600 --memory-limit=640M
analyze: php -d memory_limit=768M bin/console messenger:consume asset.analyze --time-limit=3600 --memory-limit=640M
download: php -d memory_limit=768M bin/console messenger:consume asset.download --time-limit=3600 --memory-limit=640M
delete: php -d memory_limit=768M bin/console messenger:consume asset.delete --time-limit=3600 --memory-limit=640M
scheduler: php -d memory_limit=768M bin/console messenger:consume scheduler_default --time-limit=3600 --memory-limit=640M
webhook: php bin/console messenger:consume webhook --time-limit=3600 --memory-limit=256M
