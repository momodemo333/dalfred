<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use Dalfred\Service\CommandResolver;

final class CommandResolverTest extends TestCase
{
    private CommandResolver $resolver;

    protected function setUp(): void
    {
        // The DB is unused for parse(); we pass a stub. Tests for resolve()
        // and listAvailable() are integration-style and skipped in CI here.
        $this->resolver = new CommandResolver(new class extends \stdClass {});
    }

    public function test_parse_returns_null_for_plain_message(): void
    {
        $this->assertNull($this->resolver->parse('hello world'));
    }

    public function test_parse_returns_null_when_slash_is_not_at_start(): void
    {
        $this->assertNull($this->resolver->parse('  /foo'));
        $this->assertNull($this->resolver->parse('hi /foo'));
    }

    public function test_parse_returns_null_for_lone_slash(): void
    {
        $this->assertNull($this->resolver->parse('/'));
    }

    public function test_parse_returns_null_for_uppercase_name(): void
    {
        $this->assertNull($this->resolver->parse('/FOO'));
    }

    public function test_parse_extracts_name_without_args(): void
    {
        $result = $this->resolver->parse('/foo');
        $this->assertSame(['name' => 'foo', 'args' => ''], $result);
    }

    public function test_parse_extracts_name_with_args(): void
    {
        $result = $this->resolver->parse('/foo bar baz');
        $this->assertSame(['name' => 'foo', 'args' => 'bar baz'], $result);
    }

    public function test_parse_accepts_hyphens_and_digits_in_name(): void
    {
        $result = $this->resolver->parse('/factures-2026 Dupont');
        $this->assertSame(['name' => 'factures-2026', 'args' => 'Dupont'], $result);
    }

    public function test_parse_stops_name_at_first_underscore(): void
    {
        // Underscore is NOT in the allowed name charset, so it ends the name.
        $result = $this->resolver->parse('/foo_bar baz');
        $this->assertSame(['name' => 'foo', 'args' => '_bar baz'], $result);
    }

    public function test_parse_strips_leading_whitespace_in_args(): void
    {
        $result = $this->resolver->parse('/foo    extra spaces');
        $this->assertSame(['name' => 'foo', 'args' => 'extra spaces'], $result);
    }
}
