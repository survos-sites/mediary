<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Shared access to PDF Tools for document metadata and page OCR. */
final class PdfToolsClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::PDFTOOLS_BASE_URI)%')] private readonly ?string $baseUri = null,
        #[Autowire('%env(default::PDFTOOLS_TOKEN)%')] private readonly ?string $token = null,
    ) {}

    public function register(string $url): array
    {
        $file = $this->request('POST', '/v1/files', ['url' => $url]);
        if (!isset($file['id'], $file['sha256'], $file['pages'], $file['bytes'])
            || !is_int($file['pages']) || $file['pages'] < 1 || !is_int($file['bytes']) || $file['bytes'] < 1) {
            throw new \UnexpectedValueException('PDF Tools returned invalid document metadata.');
        }
        return $file;
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->baseUri === null || trim($this->baseUri) === '') {
            throw new \LogicException('PDFTOOLS_BASE_URI must be configured for PDF OCR.');
        }
        $options = ['timeout' => 300];
        if ($this->token !== null && $this->token !== '') {
            $options['auth_bearer'] = $this->token;
        }
        if ($body !== null) {
            $options['json'] = $body;
        }

        // Propagate HTTP failures so Messenger retries instead of acknowledging lost OCR.
        return $this->httpClient->request($method, rtrim($this->baseUri, '/') . $path, $options)->toArray();
    }
}
