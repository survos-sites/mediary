<?php

declare(strict_types=1);

namespace App\Search;

/**
 * An Asset as ImageSearch indexes it: the single definition of which fields reach Elasticsearch.
 *
 * The index is strict, so anything not listed here is dropped by ImageSearch::toDocument().
 * `marking` is deliberately absent: most Asset flushes are workflow transitions that only move
 * the marking, and leaving it out lets Elasticsearch's detect_noop skip those writes entirely.
 */
final class AssetDocument
{
    private const array TEXT = ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]]];
    private const array PROSE = ['type' => 'text'];
    private const array KEYWORD = ['type' => 'keyword'];
    private const array STORED_ONLY = ['type' => 'keyword', 'index' => false, 'doc_values' => false];

    public const array MAPPINGS = [
        'id' => self::KEYWORD,
        'provider' => self::KEYWORD,
        'dataset' => self::KEYWORD,
        'mime' => self::KEYWORD,
        'ext' => self::KEYWORD,
        'type' => self::KEYWORD,
        'reuse' => self::KEYWORD,
        'mediaRecordId' => self::KEYWORD,

        // Source metadata
        'title' => self::TEXT,
        'description' => self::PROSE,
        'filename' => self::TEXT,
        'publisher' => self::TEXT,
        'subjects' => self::TEXT,
        'iiifLabel' => self::TEXT,

        // Local analysis
        'classification' => self::TEXT,
        'objectIdentifiers' => self::TEXT,
        'faceCount' => ['type' => 'integer'],
        'localOcrPrimaryType' => self::KEYWORD,

        // Claims (current AI output, denormalized onto Asset by ClaimSearchSync)
        'claimCaption' => self::TEXT,
        'claimProse' => self::PROSE,
        'claimSubjects' => self::TEXT,
        'claimType' => self::KEYWORD,

        // Older aiCompleted/mediaEnrichment projection from AssetNormalizer
        'aiTitle' => self::TEXT,
        'aiDescription' => self::PROSE,
        'aiSummary' => self::PROSE,
        'aiOcrText' => self::PROSE,
        'aiDocumentType' => self::KEYWORD,
        'aiKeywords' => self::TEXT,
        'aiPeople' => self::TEXT,
        'aiPlaces' => self::TEXT,
        'aiOrganisations' => self::TEXT,
        'aiSubjects' => self::TEXT,
        'aiTokensTotal' => ['type' => 'long'],

        'size' => ['type' => 'long'],
        'width' => ['type' => 'integer'],
        'height' => ['type' => 'integer'],
        'createdAt' => ['type' => 'date'],

        // Display only
        'thumb' => self::STORED_ONLY,
        'thumbHash' => self::STORED_ONLY,
        'originalUrl' => self::STORED_ONLY,
        'iiifManifest' => self::STORED_ONLY,
    ];

    /** Most specific first; OCR and prose last. */
    public const array SEARCH_FIELDS = [
        'claimCaption^3', 'aiTitle^3', 'title^2',
        'claimSubjects^2', 'aiKeywords^2', 'subjects^2', 'classification^2', 'objectIdentifiers',
        'aiPeople', 'aiPlaces', 'aiOrganisations', 'aiSubjects', 'publisher', 'iiifLabel', 'filename',
        'claimProse', 'aiDescription', 'aiSummary', 'description', 'aiOcrText',
    ];

    /** Facet property => label, in sidebar order. */
    public const array FACETS = [
        'claimType' => 'Type',
        'claimSubjects' => 'Subjects',
        'classification' => 'Detected',
        'provider' => 'Provider',
        'dataset' => 'Dataset',
        'mime' => 'MIME type',
        'aiDocumentType' => 'Document type',
        'localOcrPrimaryType' => 'OCR type',
    ];
}
