<!-- 

step 1 trigger code block sample (from pgsc to mediary using media client from media-bundle , targets API dispatch endpoint)

$response = $this->mediaClientService->dispatchProcess(new ProcessPayload(
                    self::MEDIARY_ROOT,
                    [
                        $audioUrl
                    ],
                    mediaCallbackUrl: $this->urlGenerator->generate('mediary_audio_callback', ['code' => $code, '_locale' => 'es'], UrlGeneratorInterface::ABSOLUTE_URL)
                ));

step 2 trigger code block sample (in mediary , the receiving end point)

$envelope = $this->messageBus->dispatch(new AsyncTransitionMessage(
                $media->getCode(),
                Media::class,
                IMediaWorkflow::TRANSITION_DOWNLOAD,
                workflow: MediaWorkflow::WORKFLOW_NAME,
                context: [
                    'wait' => $payload->wait,
                    'liip' => $payload->filters,
                    'mediaCallbackUrl' => $payload->mediaCallbackUrl,
                    'thumbCallbackUrl' => $payload->thumbCallbackUrl,
                ]
            ), [
                //new TransportNamesStamp($payload->wait ? 'sync' : 'async')
                //force sync for testing
                new TransportNamesStamp('async')
            ]);

step 2.1 trigger code block sample (after the transition DOWNLOAD finished and the download is completed)

#[AsCompletedListener(self::WORKFLOW_NAME, IMediaWorkflow::TRANSITION_DOWNLOAD)]
    public function onDownloadCompleted(CompletedEvent $event): void
    {
        $media = $this->getMedia($event);

        $statusCode = $media->getStatusCode();
        $mimeType = (string) $media->getMimeType();

        //if the status code is not 200, then mark as TRANSITION_DOWNLOAD_FAILED
        if ($statusCode !== 200) {
            $this->mediaWorkflow->apply($media, IMediaWorkflow::TRANSITION_DOWNLOAD_FAILED);
            ...

            ....

intermediate step here is to ddo a file processing fro some corrections using processTempFile

private function processTempFile(string $tempFile, Media $media): array
    {
        // @todo: check mimetype and size
        $mimeType = mime_content_type($tempFile);
        $oldExt = $media->getExt();
        
        //let s correct file extension based on mime type -> extract $ext from $mimeType
        $correctExt = match ($mimeType) {

        ....
        ....
        ....
        };


step 2.2 sync

if($context['mediaCallbackUrl']) {
            //log event
            $this->logger->info("AMINE Dispatching webhook for media {$media->getCode()} to {$context['mediaCallbackUrl']}");
            $this->apiService->dispatchWebhook($context['mediaCallbackUrl'], $media);
        }

step 2.2 trigger code block sample async

foreach ($response as $mediaData) {
            $envelope = $this->messageBus->dispatch(new SendWebhookMessage($payload->mediaCallbackUrl, $mediaData));
        }

 -->


# PGSC to mediary Media Processing Workflow

This document outlines the media processing workflow between PGSC (client) and mediary (server) systems, including asynchronous message handling and webhook callbacks.

## Overview

The workflow involves:
1. **Client Request**: PGSC initiates media processing via mediary client
2. **Server Processing**: mediary receives and processes media asynchronously
3. **File Processing**: Media files are downloaded, validated, and corrected
4. **Callback Notification**: Results are sent back via webhooks

## Workflow Steps

### Step 1: Initial Request from PGSC

The PGSC system initiates media processing by sending a request to the mediary API using the mediary client service.

**Key Components:**
- Uses `ProcessPayload` to encapsulate request data
- Includes audio URL and callback URL for notifications
- Generates absolute callback URL with locale support

```php
$response = $this->mediaClientService->dispatchProcess(new ProcessPayload(
    self::MEDIARY_ROOT,
    [
        $audioUrl
    ],
    mediaCallbackUrl: $this->urlGenerator->generate('mediary_audio_callback', ['code' => $code, '_locale' => 'es'], UrlGeneratorInterface::ABSOLUTE_URL)
));
```

### Step 2: mediary Message Bus Dispatch

Upon receiving the request, mediary dispatches an asynchronous transition message to handle media download.

**Key Components:**
- Utilizes Symfony Messenger for async processing
- Implements workflow state transitions for media objects
- Supports both sync and async transport modes
- Includes context data for callbacks and processing options

```php
$envelope = $this->messageBus->dispatch(new AsyncTransitionMessage(
    $media->getCode(),
    Media::class,
    IMediaWorkflow::TRANSITION_DOWNLOAD,
    workflow: MediaWorkflow::WORKFLOW_NAME,
    context: [
        'wait' => $payload->wait,
        'liip' => $payload->filters,
        'mediaCallbackUrl' => $payload->mediaCallbackUrl,
        'thumbCallbackUrl' => $payload->thumbCallbackUrl,
    ]
), [
    //new TransportNamesStamp($payload->wait ? 'sync' : 'async')
    //force sync for testing
    new TransportNamesStamp('async')
]);
```

### Step 2.1: Download Completion Handler

A completion listener monitors the download transition and handles post-download processing.

**Key Components:**
- Event-driven architecture using Symfony Workflow
- Status code validation (expects HTTP 200)
- Error handling for failed downloads
- Triggers file processing for successful downloads

```php
#[AsCompletedListener(self::WORKFLOW_NAME, IMediaWorkflow::TRANSITION_DOWNLOAD)]
public function onDownloadCompleted(CompletedEvent $event): void
{
    $media = $this->getMedia($event);

    $statusCode = $media->getStatusCode();
    $mimeType = (string) $media->getMimeType();

    //if the status code is not 200, then mark as TRANSITION_DOWNLOAD_FAILED
    if ($statusCode !== 200) {
        $this->mediaWorkflow->apply($media, IMediaWorkflow::TRANSITION_DOWNLOAD_FAILED);
        // ... error handling code ...
    }
    
    // ... continue with file processing ...
}
```

### Intermediate Step: File Processing

The `processTempFile` method handles file validation and correction.

**Purpose:**
- Validates downloaded file integrity
- Corrects file extensions based on MIME type detection
- Ensures file consistency before further processing

**Features:**
- MIME type detection using `mime_content_type()`
- Extension correction via pattern matching
- File size and type validation (planned)

```php
private function processTempFile(string $tempFile, Media $media): array
{
    // @todo: check mimetype and size
    $mimeType = mime_content_type($tempFile);
    $oldExt = $media->getExt();
    
    //let's correct file extension based on mime type -> extract $ext from $mimeType
    $correctExt = match ($mimeType) {
        // ... mime type mapping ...
    };
    
    // ... additional processing logic ...
}
```

### Step 2.2: Callback Notifications

After successful processing, mediary notifies the client via webhook callbacks.

#### Synchronous Callback
- Direct webhook dispatch for immediate notifications
- Includes comprehensive logging for tracking
- Sends media object data to callback URL

```php
if($context['mediaCallbackUrl']) {
    //log event
    $this->logger->info("AMINE Dispatching webhook for media {$media->getCode()} to {$context['mediaCallbackUrl']}");
    $this->apiService->dispatchWebhook($context['mediaCallbackUrl'], $media);
}
```

#### Asynchronous Callback
- Uses message bus for non-blocking webhook delivery
- Processes multiple media items in batch
- Ensures reliable delivery through message queuing

```php
foreach ($response as $mediaData) {
    $envelope = $this->messageBus->dispatch(new SendWebhookMessage($payload->mediaCallbackUrl, $mediaData));
}
```


## Error Handling

- Download failures trigger specific workflow transitions
- Status code validation prevents processing of failed downloads
- Comprehensive logging for debugging and monitoring
- Graceful degradation for webhook delivery failures

## Configuration

The workflow supports various configuration options:
- **Transport Mode**: Sync vs async processing
- **Callback URLs**: Media and thumbnail notification endpoints
- **Processing Filters**: Liip filters for image processing
- **Locale Support**: Multi-language callback URL generation

## Technical Stack

- **Framework**: Symfony with Messenger component
- **Workflow**: Symfony Workflow for state management
- **Async Processing**: Message queues with transport stamps
- **File Handling**: PHP native functions for MIME detection
- **Logging**: PSR-3 compatible logging interface
- **URL Generation**: Symfony Router for callback URLs