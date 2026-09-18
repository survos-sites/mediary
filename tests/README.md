# Running Mediary tests

Run `php bin/phpunit`. The crawler suite exercises the PostgreSQL full-text search implementation and needs a local PostgreSQL role with permission to create temporary databases.

By default it uses the server credentials from `DATABASE_URL`. Set `TEST_POSTGRES_URL` to use a separate test server. The suite connects to the server's `postgres` maintenance database, creates a randomly named `mediary_crawl_*` database, checks its name before building each test schema, and drops it after the suite. It never builds schemas in the configured application database.

Claims and dataset registries use in-memory SQLite. Storage uses the existing in-memory Flysystem adapters. The crawler stubs message dispatch so asset fixtures and AI task URLs cannot enqueue work on live queues. Task checks still verify the requested task is persisted in the asset's queue.

The 34 unit tests can run independently without PostgreSQL:

```sh
php bin/phpunit tests/Ai tests/Messenger tests/Service tests/EventListener
```
