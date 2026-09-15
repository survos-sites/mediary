<?php

declare(strict_types=1);

namespace App\Ai;

use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\TaskClaimMapper;
use Survos\AiWorkflowBundle\Task\TaskInterface;
use Survos\AiWorkflowBundle\Task\TaskNameTrait;
use Survos\AiWorkflowBundle\Task\TaskResult;
use Survos\ClaimsBundle\Entity\Claim;
use Survos\ClaimsBundle\Service\RunMeta;
use Survos\DataContracts\Vocabulary\MediaSyncKeys;
use Survos\DataContracts\Workflow\ContextSubjectInterface;
use Survos\DataContracts\Workflow\ImageSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use App\Service\PdfToolsClient;

/** One PDF task, using PDF Tools' cached, independently retryable page operations. */
#[AsTask('Extract PDF text, using free Tesseract only for pages without text.', self::class, produces: [Claim::PRED_OCR_TEXT, 'ai:ocrPage'])]
final class PdfOcrTask implements TaskInterface
{
    use TaskNameTrait;

    public const string TASK = 'ocr_pdf';

    public function __construct(
        private readonly PdfToolsClient $pdfTools,
        private readonly TaskClaimMapper $claimMapper,
    ) {}

    public function supports(WorkflowSubjectInterface $subject): bool
    {
        return $subject instanceof ImageSubjectInterface
            && strtolower(pathinfo((string) parse_url((string) $subject->getWorkflowImageUrl(), PHP_URL_PATH), PATHINFO_EXTENSION)) === 'pdf';
    }

    public function getMeta(): array
    {
        return ['platform' => 'pdf-tools', 'model' => 'pymupdf+tesseract'];
    }

    public function run(WorkflowSubjectInterface $subject): TaskResult
    {
        if (!$this->supports($subject)) {
            throw new \InvalidArgumentException('PDF OCR requires a PDF source URL.');
        }
        $context = $subject instanceof ContextSubjectInterface ? $subject->getWorkflowContext() : [];
        // OCR language is an explicit recognition hint, never inferred from catalog locale.
        $language = $context[MediaSyncKeys::OCR_LANGUAGE] ?? null;
        $file = $this->pdfTools->register($subject->getWorkflowImageUrl());
        $pages = [];
        for ($page = 1; $page <= $file['pages']; ++$page) {
            $path = '/v1/files/' . rawurlencode($file['id']) . '/pages/' . $page;
            $native = $this->pdfTools->request('GET', $path . '/text');
            if (!isset($native['text']) || !is_string($native['text'])) {
                throw new \UnexpectedValueException('PDF Tools returned invalid page text.');
            }
            $text = trim($native['text']);
            $source = 'embedded';
            $geometry = [];
            if ($text === '') {
                if (!is_string($language) || $language === '') {
                    throw new \InvalidArgumentException('Set ocr_language explicitly for scanned PDF pages.');
                }
                $result = $this->pdfTools->request('POST', $path . '/ocr', ['language' => $language, 'layout' => false]);
                if (!isset($result['text'], $result['blocks'], $result['width'], $result['height']) || !is_string($result['text']) || !is_array($result['blocks'])) {
                    throw new \UnexpectedValueException('PDF Tools returned invalid OCR output.');
                }
                $text = trim($result['text']);
                $source = 'tesseract';
                $geometry = ['blocks' => $result['blocks'], 'dimensions' => ['width' => $result['width'], 'height' => $result['height']]];
            } else {
                $geometry['words'] = $this->pdfTools->request('GET', $path . '/words');
            }
            // Existing ai:ocrPage contract uses zero-based index; PDF Tools uses one-based pages.
            $pages[] = ['index' => $page - 1, 'markdown' => $text, 'textSource' => $source, 'sourceSha256' => $file['sha256']] + $geometry;
        }
        $data = ['text' => implode("\n\n", array_column($pages, 'markdown')), 'pages' => $pages, 'sourceSha256' => $file['sha256'], 'model' => 'pymupdf+tesseract'];

        return new TaskResult(claims: $this->claimMapper->map($data, Claim::PRED_OCR_TEXT), meta: new RunMeta(model: $data['model'], response: $data));
    }

}
