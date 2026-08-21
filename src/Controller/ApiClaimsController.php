<?php

declare(strict_types=1);

namespace App\Controller;

use Survos\ClaimsBundle\Service\ClaimReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read API for the central claims store — the server side of
 * {@see \Survos\ClaimsBundle\Service\ApiClaimReader}.
 *
 * Why this exists: every reader app used to hold a Postgres DSN pointing at the claims database
 * purely to DISPLAY claims it never writes. That meant each app needed network reachability to the
 * database, a readonly role, and a credential to rotate — and any one of them could open a
 * connection to the shared store. Here mediary, which already owns the claims schema, is the only
 * process with database access, and consumers hold a URL and a token.
 *
 * Endpoints mirror ClaimReaderInterface exactly, so the two transports are interchangeable:
 *   GET /api/claim-store/subjects?ids[]=…&scope=…&manifested=1
 *   GET /api/claim-store/scope?scope=…
 *   GET /api/claim-store/runs?scope=…
 *   GET /api/claim-store/count?id=…&scope=…
 *
 * Responses are {"rows": [...]} / {"count": n} — an envelope, not a bare array, so fields can be
 * added later (paging, generated-at) without breaking clients that already parse it.
 */
final class ApiClaimsController extends AbstractController
{
    /**
     * Subjects per request. Claims are keyed by media id and callers batch a page of rows at a
     * time; without a ceiling one caller can ask for the whole table through a URL. Rejected
     * rather than silently truncated — a short read that looks successful is how "some claims are
     * missing" bugs start.
     */
    private const MAX_IDS = 500;

    public function __construct(
        private readonly ClaimReader $reader,
        #[Autowire('%env(default::CLAIMS_API_TOKEN)%')]
        private readonly ?string $apiToken = null,
    ) {
    }

    #[Route('/api/claim-store/subjects', name: 'api_claims_subjects', methods: ['GET'])]
    public function subjects(
        Request $request,
        #[MapQueryParameter] ?array $ids = null,
        #[MapQueryParameter] ?string $scope = null,
        #[MapQueryParameter] ?bool $manifested = null,
    ): JsonResponse {
        if ($denied = $this->denyUnlessAuthorized($request)) {
            return $denied;
        }

        $ids = $this->normalizeIds($ids);
        if ([] === $ids) {
            return $this->json(['rows' => []]);
        }
        if (\count($ids) > self::MAX_IDS) {
            return $this->json([
                'error' => sprintf('Too many ids: %d (max %d)', \count($ids), self::MAX_IDS),
            ], 400);
        }

        $rows = $manifested
            ? $this->reader->manifestedForSubjects($ids, $scope)
            : $this->reader->forSubjects($ids, $scope);

        return $this->json(['rows' => $rows]);
    }

    #[Route('/api/claim-store/scope', name: 'api_claims_scope', methods: ['GET'])]
    public function scope(Request $request, #[MapQueryParameter] string $scope): JsonResponse
    {
        if ($denied = $this->denyUnlessAuthorized($request)) {
            return $denied;
        }

        return $this->json(['rows' => $this->reader->forScope($scope)]);
    }

    #[Route('/api/claim-store/runs', name: 'api_claims_runs', methods: ['GET'])]
    public function runs(Request $request, #[MapQueryParameter] string $scope): JsonResponse
    {
        if ($denied = $this->denyUnlessAuthorized($request)) {
            return $denied;
        }

        return $this->json(['rows' => $this->reader->runsForScope($scope)]);
    }

    #[Route('/api/claim-store/count', name: 'api_claims_count', methods: ['GET'])]
    public function count(
        Request $request,
        #[MapQueryParameter] string $id,
        #[MapQueryParameter] ?string $scope = null,
    ): JsonResponse {
        if ($denied = $this->denyUnlessAuthorized($request)) {
            return $denied;
        }

        return $this->json(['count' => $this->reader->countForSubject($id, $scope)]);
    }

    /**
     * Bearer check. Claims can hold unpublished AI output, so this is not a public read.
     *
     * A missing/empty CLAIMS_API_TOKEN refuses every request rather than waving them through:
     * an unconfigured deployment should fail closed and be obvious, not quietly serve the
     * claims table to anyone who finds the URL.
     */
    private function denyUnlessAuthorized(Request $request): ?JsonResponse
    {
        if (null === $this->apiToken || '' === $this->apiToken) {
            return $this->json(['error' => 'Claims API is not configured (CLAIMS_API_TOKEN unset).'], 503);
        }

        $header = (string) $request->headers->get('Authorization', '');
        $presented = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';

        if (!hash_equals($this->apiToken, $presented)) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        return null;
    }

    /**
     * Accepts ids[]=a&ids[]=b and ids=a,b — the comma form keeps hand-testing with curl bearable,
     * and both collapse to the same list.
     *
     * @return list<string>
     */
    private function normalizeIds(?array $ids): array
    {
        $out = [];
        foreach ($ids ?? [] as $id) {
            foreach (explode(',', (string) $id) as $part) {
                $part = trim($part);
                if ('' !== $part) {
                    $out[] = $part;
                }
            }
        }

        return array_values(array_unique($out));
    }
}
