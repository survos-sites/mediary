
# Ideas:

* EasyOCR (very slow)
* ONNX: runtime for OCR, optimized for CPU/GPU, PaddleOCR (Chinese/Korean),
* Azure Document Intelligence (Slow and expensive)
* GropID
* Digital Humanities
* https://medium.com/@robi.tomar72/deepseek-ocr-just-did-the-impossible-and-the-entire-ai-world-is-shook-4020afb28956

# mediary -- the Survos Media Server

Now uses Asset and Variant instead of Media and Thumbs

However, this wasn't finished, and we haven't integrated jolicode's media bundle or ImgProxy, both worth considering.

This application is based on the LiipImagineBundle, but instead of dynamically creating images on the fly, it creates them asynchronously and sends a callback to the client when finished.   It uses [flysystem](https://github.com/thephpleague/flysystem-bundle) so the storage is flexible.  The main purpose is to NOT freeze the system if a thumbnail has not been generated, but also have a central repository for image analysis tools.

There are some tools for working directly with the server, but most of the time images are loaded from a client application, like museado, via survos/media-bundle (`bin/console media:sync`).  Each client has its own "key", which is used for authentication as the root of the source images (on S3) and resized images stored locally in the media cache.

![Database Diagram](assets/docs/database.svg)

## JSON-RPC

curl -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0", "method":"tools/list"}' -x 127.0.0.1:7080 https://sais.wip/tools | jq

curl -H 'Content-Type: application/json' \
   -d '{"jsonrpc":"2.0","method":"tools/create_account","id": "unique", "params":{"root": "rpc", "estimated":100}}' -x 127.0.0.1:7080 https://sais.wip/tools | jq

curl -H 'Content-Type: application/json' \
-d '{"jsonrpc":"2.0","method":"tools/list","id": "unique", "params":{"root": "rpc", "estimated":100}}'  https://127.0.0.1:8018/mcp | jq


curl -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0", "method":"tools/list"}' https://127.0.0.1:8018/mcp | jq

curl -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0", "method":"tools/sum_numbers"}' https://127.0.0.1:8018/mcp | jq


## Developers

survos/media-bundle defines the _structures_ used by both the client and the server —
`BatchPayloadDto` is the `media:sync` wire contract, so producer and consumer cannot drift.

Note: see https://medium.com/@laurentmn/optimizing-image-handling-in-symfony-with-liipimaginebundle-pro-tips-use-cases-7f55819deb80 for configuring thumbnails to be on S3

```bash
git clone git@github.com:survos/mono.git
git clone <mediary> && cd mediary
composer install
../mono/link .
```

Each client, aka museado, pgsc, dummy, voxitour, etc. is registered as a User with a code (for eventual security).  The code is also the root path on the storage.

## Adding more JSON RPC MCP tools / endpoints can be found here [JSONRPC.md](doc/JSONRPC.md)

!Because the source and resized images are put into buckets, the system needs to know the approximate (within an order of magnitude) number of images. 
Larger image sizes will use longer hashes in the directory names.  The files will be evenly distributed within the buckets.

The `download` workflow consists of these steps: 

* Download the original URL
* Upload the file to our S3 long-term storage
* Dispatch resize messages to resize the original image

![Media Workflow](assets/images/MediaWorkflow.svg)


![ThumbWorkflow](assets/images/ThumbWorkflow.svg)

To begin this process, the client calls an API endpoint with one or more URLs.  (@todo: client endpoint for uploading a file)

Now the client can upload urls to the server.  

[File Workflow](doc/FileWorkflow.md)
[Media Workflow](doc/MediaWorkflow.md)

```php
// survos/media-bundle, Survos\MediaBundle\Service\MediaBatchDispatcher
$result = $this->mediaBatchDispatcher->dispatch($client, $urls, [
    'context'      => $contextMap,   // per-URL hints, keyed by url
    'callback_url' => $this->urlGenerator->generate('app_webhook'),
]);
```

This call _queues_ the images to be downloaded and resized, and then calls the webhook upon completion (partially working).

## Workflow

## Database
![Database Diagram](./assets/images/db.svg)

## Recap

* Client (e.g museado, dt-demo) registers with mediary and gets an API key and code
* Via the client bundle, the client pushes urls to mediary, which are queued for downloading and image creation.  A status list is returned, with codes for the URLs.
* mediary downloads the image to a cache directory.
* Then uploads the image to an archive (default.storage) and local storage.  The temp file can then be deleted
* The thumbnail workflow creates the resized images from local storage (faster than remove). 
* @todo:for each url calls a webhook so the client application can update the database and start using the images.
* The client can also poll mediary for a status, or request a single image on demand.  This is mostly for debugging, as if it's overused the server can become overwhelmed.
* When resized images are finished, the localstorage file can be deleted.  It will have to be re-downloaded if more filters are added.

Applications are required to maintain a thumbnail status, which the image server gives to them in a callback. If the filter exists then the image can be called.

Also tests bad-bot, key-value.  

## Probe API (polling fallback)

If callbacks fail (e.g. local dev webhook endpoint is down), you can poll mediary directly.

Single asset probe (recommended for debugging one image):

```bash
curl -s "https://mediary.wip/fetch/media/<asset_id>" | jq
```

Batch probe by ids:

```bash
curl -s "https://mediary.wip/fetch/media/by-ids?id=<asset_id_1>,<asset_id_2>" | jq

curl -s -X POST "https://mediary.wip/fetch/media/by-ids" \
  -H 'Content-Type: application/json' \
  -d '{"ids": ["<asset_id_1>", "<asset_id_2>"]}' | jq
```

Probe response includes:

* top-level asset fields (`id`, `source`, `marking`, `meta`)
* `thumbs` and full `variants`
* `context` (where OCR/AI enrichment is stored)
* `children` (derived assets such as page/OCR children)
* convenience mirrors `ocr` and `ai` from `context` when present


## Notes

```bash
# every transport is doctrine:// today (see config/packages/messenger.yaml), so
# the queue is a table -- there is no rabbitmq to purge.
bin/console dbal:run-sql "delete from messenger_messages where queue_name='failed'"
bin/console dbal:run-sql "delete from messenger_messages"
```

Each "collection" has its own API key.  If the collection expects to have more than 1 million images, it will use a 8^3 high-level directory structure, otherwise 8^2, which will allow a complete fetch of the file metadata with just 64 API calls, as opposed to 512 calls.  


Instead, it sends back a "server busy" status code, and submit the image to the processing queue to be generated.

By not allowing a runtime configuration, we simplify the urls, the original request is has /resolve, the actual image does not.

The application can't call image_filter directly, since that checks the cache to create the link (/resolve or not).  Then the application needs a survos/image-bundle that helps with the configuration.


The application, which does NOT cache the images, needs to store this in a database.  To request thumbnails, it's 

'd4/a1/whatever'|image_server('medium')
'https://pictures.com/abc.jpg'|image_server'
'photos/def.jpg'|image_server'

should return https://image-server.survos.com/media/cache/medium/d4/a1/whatever.jpg

We won't know if this exists, though, until we've received the callback.  So before putting that on a web page, the app needs to async request the image

https://image-server.survos.com/request/small?url=pictures.com/abc.jpg&callback=myapp/callback/images-resizer-finished

NOW the cached image exists

The image bundle can get the list of available filters, or configure only certain ones, etc.



images are served from the imageserver

```bash
dokku storage:mount mediary /mnt/volume-1/project-data/mediary/public:/app/public
chown -R 32767:32767 /mnt/volume-1/project-data/mediary
```

> **Stale below/above this line.** Much of this README describes the pre-mediary
> design (LiipImagineBundle thumbnails, a `/handle_media` callback endpoint, a
> `/ui/account_setup` registration route, `sais:queue`). None of those routes or
> commands exist any more — `debug:router` and `bin/console list` are the truth.
> Left in place rather than renamed, so nobody mistakes it for current API docs.

## @todo

https://medium.com/devsphere/integrating-php-with-opencv-for-image-recognition-c83a04329da6

https://www.howtogeek.com/ditched-google-photos-built-my-own-photo-server/?utm_medium=newsletter&utm_campaign=HTG-202503260500&utm_source=HTG-NL&user=dGFjbWFuQGdtYWlsLmNvbQ&lctg=ae42097783e49dc35a3c998f3504d7e2f78093f481889e028e25c7ba46d1b098

## resetting the database

```bash
rm var/data.db -f && bin/console d:sch:update --force  

## Playing around

https://discuss.pixls.us/t/stag-an-open-source-tool-for-automatic-image-tagging/48369
```
