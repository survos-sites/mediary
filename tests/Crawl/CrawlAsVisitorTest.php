<?php

namespace App\Tests\Crawl;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;
use Survos\CrawlerBundle\Tests\BaseVisitLinksTest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CrawlAsVisitorTest extends BaseVisitLinksTest
{
	#[TestDox('/$method $url ($route)')]
	#[TestWith(['', '/media/search', 200])]
	#[TestWith(['', '/api/docs', 200])]
	#[TestWith(['', '/api', 200])]
	#[TestWith(['', '/api/files', 200])]
	#[TestWith(['', '/api/media', 200])]
	#[TestWith(['', '/api/storages', 200])]
	// /api/thumbs is gone: thumb caching happens in imgproxy, not here, so the Thumb
	// API resource was dropped. Do not re-add without a route to back it.
	#[TestWith(['', '/js/routing', 200])]
	#[TestWith(['', '/crawler/crawlerdata', 200])]
	#[TestWith(['', '/meili/meiliAdmin/docs', 500])]
	#[TestWith(['', '/meili/meiliAdmin/riccox', 500])]
	#[TestWith(['', '/workflow/', 200])]
	#[TestWith(['', '/handle_image_resize', 200])]
	#[TestWith(['', '/handle_media', 200])]
	#[TestWith(['', '/ui/account_setup', 200])]
	#[TestWith(['', '/ui/dispatch_process', 200])]
	#[TestWith(['', '/home', 200])]
	#[TestWith(['', '/test', 200])]
	#[TestWith(['', '/webhook-test', 200])]
	#[TestWith(['', '/app/media', 200])]
	#[TestWith(['', '/app/thumbs', 200])]
	#[TestWith(['', '/status', 200])]
	#[TestWith(['', '/', 200])]
	#[TestWith(['', '/test-webhook', 200])]
	#[TestWith(['', '/verify/email', 200])]
	#[TestWith(['', '/login', 200])]
	#[TestWith(['', '/webhook', 200])]
	#[TestWith(['', '/workflow/workflow/MediaWorkflow', 200])]
	#[TestWith(['', '/workflow/workflow/ThumbWorkflow', 200])]
	#[TestWith(['', '/workflow/workflow/FileWorkflow', 200])]
	#[TestWith(['', '/app/thumbs?code=user_001', 200])]
	#[TestWith(['', '/app/thumbs?code=user_001&size=small', 200])]
	#[TestWith(['', '/app/thumbs?code=user_001&size=medium', 200])]
	#[TestWith(['', '/workflow/workflow/MediaWorkflow?states=%22new%22', 200])]
	public function testRoute(string $username, string $url, string|int|null $expected): void
	{
		parent::loginAsUserAndVisit($username, $url, (int)$expected);
	}
}
