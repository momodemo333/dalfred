<?php

// psr/http-message 1.0 MessageInterface, untyped, as still shipped by older
// libraries that other Dolibarr modules bundle. Fixture for
// tests/runtime_isolation_test.php, loaded before the scoped package so that
// nothing of ours may end up implementing a foreign interface. (guzzlehttp/psr7
// 2.x happens to tolerate this one: untyped parameters, covariant returns.)

namespace Psr\Http\Message;

interface MessageInterface
{
    public function getProtocolVersion();
    public function withProtocolVersion($version);
    public function getHeaders();
    public function hasHeader($name);
    public function getHeader($name);
    public function getHeaderLine($name);
    public function withHeader($name, $value);
    public function withAddedHeader($name, $value);
    public function withoutHeader($name);
    public function getBody();
    public function withBody(StreamInterface $body);
}
