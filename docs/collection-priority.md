# Native RabbitMQ collection priority

`POST /{client}/batch` accepts optional top-level `collectionSize` (positive integer)
and `priority` (`high`, `normal`, `bulk`). Use the whole source collection size,
not this request's URL count. Explicit priority overrides the size policy:

| Collection size | Priority | AMQP priority |
| --- | --- | --- |
| 1–100 | high | 3 |
| 101–10,000 or unknown | normal | 2 |
| Over 10,000 | bulk | 1 |

Harvest sends DatasetInfo's source collection count on each media submission.
Scheduling is independent of sync execution. The directive is stored in
Asset.context.processing_priority before flush and initial workflow kickoff.
AssetPriorityMiddleware attaches jwage's AmqpStamp to each outgoing asset
transition, retaining its existing per-stage transport and any other AMQP attributes.
Shared assets may be promoted but never demoted by another registration.
Promotion affects future dispatches; already queued messages are not reordered
by changing the asset's context alone. Legacy unstamped messages have priority 0.

## Queue migration and workers

The asset stage queues declare `x-max-priority: 3`. RabbitMQ classic queues cannot
change this argument in place: stop publishers/consumers and drain or explicitly
purge and delete the old queues, then recreate using messenger:setup-transports.
Never delete queues from another app's vhost. No high/normal/bulk transports or
additional priority process types are needed. Keep stage-specific workers running,
including `asset.info` between archive and later processing.

Prefetch is one on ordinary asset stages and ten for batched AI. AI handlers flush
partial batches when idle; this trades batching throughput for responsiveness.
Priority affects waiting deliveries, never interrupts a running job, and cannot
reorder work already prefetched. Strict priority can starve bulk traffic under a
continuous higher-priority load; monitor oldest message age and tune capacity.

Four focused tests cover policy boundaries, validation, promotion, AMQP priority,
unchanged transition routes, and preservation of synchronous/received messages.
Broker verification and environment-specific migration must also be completed
before treating rollout as finished.
