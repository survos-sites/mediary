<?php

declare(strict_types=1);

namespace App\Ai\Comparison;

use App\Ai\AssetAiExecutor;
use App\Entity\Asset;
use App\Event\MediaIterationEvent;
use Survos\AiWorkflowBundle\Task\BatchableTaskInterface;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;

#[AsEventListener]
final class CompareMediaListener
{
    public function __construct(
        private readonly TaskRegistry $tasks,
        private readonly AssetAiExecutor $executor,
        private readonly ModelComparisonClient $client,
        private readonly HttpClientInterface $http,
        private readonly ImgproxyUrlBuilder $imageUrls,
    ) {
    }

    public function __invoke(MediaIterationEvent $event): void
    {
        if (!($event->context['compare'] ?? false)) {
            return;
        }
        $subject = $event->entity instanceof Asset ? $this->executor->subjectFor($event->entity) : $event->entity;
        if (!$subject instanceof WorkflowSubjectInterface) {
            throw new \InvalidArgumentException('Comparison requires an Asset or WorkflowSubjectInterface entity.');
        }
        $task = $this->tasks->get($event->context['task']);
        if (!$task instanceof BatchableTaskInterface || !$task->supports($subject)) {
            throw new \InvalidArgumentException('Comparison requires a supported task exposing a batch request.');
        }
        $request = $task->batchRequest($subject);
        if ($request['endpoint'] !== '/v1/chat/completions') {
            throw new \InvalidArgumentException('Comparison currently supports chat-completions tasks only.');
        }
        $body = $request['body'];
        $images = [];
        $fetchStart = hrtime(true);
        foreach ($body['messages'] as &$message) {
            if (!is_array($message['content'])) {
                continue;
            }
            foreach ($message['content'] as &$part) {
                if (($part['type'] ?? '') !== 'image_url') {
                    continue;
                }
                $url = $part['image_url']['url'];
                if (($crop = $event->context['crop'] ?? null) !== null) {
                    if (!$event->entity instanceof Asset || !preg_match('/^\d+,\d+,[1-9]\d*,[1-9]\d*$/D', $crop)) {
                        throw new \InvalidArgumentException('A crop requires an Asset and x,y,width,height pixel coordinates.');
                    }
                    [$x, $y, $width, $height] = array_map('intval', explode(',', $crop));
                    $url = $this->imageUrls->buildUrl(
                        $event->entity->archiveUrl ?? $event->entity->originalUrl,
                        sprintf('c:%d:%d:nowe:%d:%d/rs:fit:512:512:0/q:80/f:webp', $width, $height, $x, $y),
                    );
                }
                if (!preg_match('#^https?://#i', $url)) {
                    throw new \InvalidArgumentException('Comparison image must have an HTTP(S) URL.');
                }
                $bytes = $this->http->request('GET', $url, ['timeout' => 30, 'max_duration' => 30, 'on_progress' => static function (int $downloaded): void {
                    if ($downloaded > 20 * 1024 * 1024) {
                        throw new \RuntimeException('Comparison image exceeds 20 MiB.');
                    }
                }])->getContent();
                $info = @getimagesizefromstring($bytes);
                if ($info === false || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    throw new \UnexpectedValueException('Comparison needs a JPEG, PNG or WebP image.');
                }
                $images[] = ['sha256' => hash('sha256', $bytes), 'width' => $info[0], 'height' => $info[1], 'mime' => $info['mime'], 'bytes' => strlen($bytes)];
                $part['image_url']['url'] = 'data:'.$info['mime'].';base64,'.base64_encode($bytes);
            }
            unset($part);
        }
        unset($message);
        if ($images === []) {
            throw new \InvalidArgumentException('Task did not attach an image.');
        }
        $fetchMs = round((hrtime(true) - $fetchStart) / 1_000_000, 2);
        // Keep the prompt for reproducibility without embedding megabytes of image data.
        $messages = $body['messages'];
        foreach ($messages as &$message) {
            if (is_array($message['content'])) {
                $message['content'] = array_values(array_filter($message['content'], static fn (array $part): bool => $part['type'] === 'text'));
            }
        }
        unset($message);
        foreach ($event->context['models'] as $model) {
            $event->results[$model] = $this->client->compare($model, $body) + [
                'task' => $event->context['task'],
                'images' => $images,
                'crop' => $event->context['crop'] ?? null,
                'image_fetch_ms' => $fetchMs,
                'messages' => $messages,
            ];
        }
    }
}
