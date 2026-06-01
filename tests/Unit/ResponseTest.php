<?php

declare(strict_types=1);

namespace Perfexcrm\Postieri\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Perfexcrm\Postieri\Api\Http\Response;

/**
 * Tests for the Response envelope.
 *
 * Note: Response::emit() writes to CodeIgniter's output class in production.
 * In tests we use Response::setEmitter() to capture the response value
 * object without needing a real CI_Controller.
 */
final class ResponseTest extends TestCase
{
    /** @var Response|null */
    private ?Response $captured = null;

    protected function setUp(): void
    {
        $this->captured = null;
        Response::setEmitter(function (Response $r): void {
            $this->captured = $r;
        });
    }

    protected function tearDown(): void
    {
        Response::setEmitter(null);
    }

    public function testOkProduces200WithEnvelope(): void
    {
        Response::ok(['id' => 1, 'name' => 'Acme'], ['page' => 1]);

        $this->assertNotNull($this->captured);
        $this->assertSame(200, $this->captured->statusCode);
        $this->assertTrue($this->captured->body['status']);
        $this->assertSame(['id' => 1, 'name' => 'Acme'], $this->captured->body['data']);
        $this->assertSame(['page' => 1], $this->captured->body['meta']);
    }

    public function testCreatedProduces201(): void
    {
        Response::created(['id' => 42]);

        $this->assertNotNull($this->captured);
        $this->assertSame(201, $this->captured->statusCode);
        $this->assertTrue($this->captured->body['status']);
        $this->assertSame(42, $this->captured->body['data']['id']);
    }

    public function testNoContentProduces204(): void
    {
        Response::noContent();

        $this->assertNotNull($this->captured);
        $this->assertSame(204, $this->captured->statusCode);
        $this->assertNull($this->captured->body);
    }

    public function testErrorProducesErrorEnvelope(): void
    {
        Response::error(401, 'unauthorized', 'Missing token', ['hint' => 'Bearer auth required']);

        $this->assertNotNull($this->captured);
        $this->assertSame(401, $this->captured->statusCode);
        $this->assertFalse($this->captured->body['status']);
        $this->assertSame('unauthorized', $this->captured->body['error']['code']);
        $this->assertSame('Missing token', $this->captured->body['error']['message']);
        $this->assertSame(['hint' => 'Bearer auth required'], $this->captured->body['error']['details']);
    }

    public function testOkWithoutMetaOmitsMetaKey(): void
    {
        Response::ok(['a' => 1]);

        $this->assertNotNull($this->captured);
        $this->assertArrayNotHasKey('meta', $this->captured->body);
    }

    public function testErrorWithoutDetailsOmitsDetailsKey(): void
    {
        Response::error(404, 'not_found', 'No such resource');

        $this->assertNotNull($this->captured);
        $this->assertSame('not_found', $this->captured->body['error']['code']);
        $this->assertArrayNotHasKey('details', $this->captured->body['error']);
    }
}
