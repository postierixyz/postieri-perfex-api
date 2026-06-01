<?php

declare(strict_types=1);

namespace Perfexcrm\Postieri\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Perfexcrm\Postieri\Api\Http\Response;

/**
 * Tests for the Response value object.
 *
 * Uses the static ::setEmitter() seam to capture responses without
 * needing a CodeIgniter instance.
 */
final class ResponseTest extends TestCase
{
    private array $captured = [];

    protected function setUp(): void
    {
        $this->captured = [];
        Response::setEmitter(function (Response $r): void {
            $this->captured[] = [
                'status' => $r->statusCode,
                'body'   => $r->body,
            ];
        });
    }

    protected function tearDown(): void
    {
        Response::setEmitter(null);
    }

    public function testOkProduces200WithEnvelope(): void
    {
        Response::ok(['id' => 1]);
        $this->assertCount(1, $this->captured);
        $this->assertSame(200, $this->captured[0]['status']);
        $this->assertSame(['status' => true, 'data' => ['id' => 1]], $this->captured[0]['body']);
    }

    public function testCreatedProduces201(): void
    {
        Response::created(['id' => 42]);
        $this->assertSame(201, $this->captured[0]['status']);
        $this->assertSame(['status' => true, 'data' => ['id' => 42]], $this->captured[0]['body']);
    }

    public function testNoContentProduces204WithNullBody(): void
    {
        Response::noContent();
        $this->assertSame(204, $this->captured[0]['status']);
        $this->assertNull($this->captured[0]['body']);
    }

    public function testErrorProducesErrorEnvelope(): void
    {
        Response::error(422, 'validation_failed', 'Email is required', ['field' => 'email']);
        $this->assertSame(422, $this->captured[0]['status']);
        $this->assertFalse($this->captured[0]['body']['status']);
        $this->assertSame('validation_failed', $this->captured[0]['body']['error']['code']);
        $this->assertSame('Email is required', $this->captured[0]['body']['error']['message']);
        $this->assertSame(['field' => 'email'], $this->captured[0]['body']['error']['details']);
    }

    public function testErrorOmitsNullDetails(): void
    {
        Response::error(401, 'unauthorized', 'Missing token');
        $this->assertArrayNotHasKey('details', $this->captured[0]['body']['error']);
    }

    public function testOkWithMeta(): void
    {
        Response::ok([1, 2, 3], ['page' => 1, 'per_page' => 25, 'total' => 3]);
        $body = $this->captured[0]['body'];
        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 3], $body['meta']);
    }
}
