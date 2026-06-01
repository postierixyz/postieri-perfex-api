<?php

declare(strict_types=1);

namespace Perfexcrm\Postieri\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Perfexcrm\Postieri\Api\Http\Response;

/**
 * Tests for the Response envelope.
 *
 * Note: these tests mock CI_Controller to assert on set_status_header,
 * set_content_type and set_output calls. We use a small in-test recorder
 * to capture the calls.
 */
final class ResponseTest extends TestCase
{
    public function testOkProduces200WithEnvelope(): void
    {
        $ci = $this->makeCI();
        Response::ok($ci, ['id' => 1, 'name' => 'Acme'], ['page' => 1]);

        $this->assertSame(200, $ci->output->status);
        $this->assertStringContainsString('application/json', $ci->output->contentType);

        $body = json_decode($ci->output->body, true);
        $this->assertTrue($body['status']);
        $this->assertSame(['id' => 1, 'name' => 'Acme'], $body['data']);
        $this->assertSame(['page' => 1], (array) $body['meta']);
    }

    public function testCreatedProduces201(): void
    {
        $ci = $this->makeCI();
        Response::created($ci, ['id' => 42]);
        $this->assertSame(201, $ci->output->status);
        $body = json_decode($ci->output->body, true);
        $this->assertTrue($body['status']);
        $this->assertSame(42, $body['data']['id']);
    }

    public function testNoContentProduces204(): void
    {
        $ci = $this->makeCI();
        Response::noContent($ci);
        $this->assertSame(204, $ci->output->status);
    }

    public function testErrorProducesErrorEnvelope(): void
    {
        $ci = $this->makeCI();
        Response::error($ci, 401, 'unauthorized', 'Missing token', ['hint' => 'Bearer auth required']);

        $this->assertSame(401, $ci->output->status);
        $body = json_decode($ci->output->body, true);
        $this->assertFalse($body['status']);
        $this->assertSame('unauthorized', $body['error']['code']);
        $this->assertSame('Missing token', $body['error']['message']);
        $this->assertSame(['hint' => 'Bearer auth required'], (array) $body['error']['details']);
    }

    public function testUnescapedUnicode(): void
    {
        $ci = $this->makeCI();
        Response::ok($ci, ['name' => 'Kompania Shqiptare']);
        // The raw body should contain the unicode characters as-is, not \uXXXX escapes
        $this->assertStringContainsString('Kompania Shqiptare', $ci->output->body);
        $this->assertStringNotContainsString('\u', $ci->output->body);
    }

    /**
     * Build a minimal stand-in for CI_Controller that records output calls.
     */
    private function makeCI(): object
    {
        return new class {
            public object $output;

            public function __construct()
            {
                $this->output = new class {
                    public int $status = 200;
                    public string $contentType = '';
                    public string $body = '';

                    public function set_status_header(int $code): self
                    {
                        $this->status = $code;
                        return $this;
                    }
                    public function set_content_type(string $type, string $charset = 'utf-8'): self
                    {
                        $this->contentType = $type;
                        return $this;
                    }
                    public function set_output(string $body): self
                    {
                        $this->body = $body;
                        return $this;
                    }
                };
            }
        };
    }
}
