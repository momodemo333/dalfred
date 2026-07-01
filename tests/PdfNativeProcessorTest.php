<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Service\IngestedFile;
use Dalfred\Service\PdfNativeProcessor;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Enums\SourceType;

$failures = 0;
function assertTrue(bool $cond, string $msg): void {
    global $failures;
    if (!$cond) { echo "  FAIL  $msg\n"; $failures++; } else { echo "  OK    $msg\n"; }
}
function assertEquals($exp, $act, string $msg): void {
    global $failures;
    if ($exp !== $act) {
        echo "  FAIL  $msg\n    expected: " . var_export($exp, true) . "\n    actual:   " . var_export($act, true) . "\n";
        $failures++;
    } else { echo "  OK    $msg\n"; }
}

function makeIngestedPdf(string $name = 'simple.pdf', string $fixture = 'simple.pdf'): IngestedFile {
    $abs = __DIR__ . '/fixtures/' . $fixture;
    return new IngestedFile(
        absolutePath: $abs,
        relativePath: 'thread_test/' . $name,
        originalName: $name,
        mimeType:     'application/pdf',
        sizeBytes:    filesize($abs),
    );
}

echo "=== PdfNativeProcessorTest ===\n";

$p = new PdfNativeProcessor();

// canProcess
assertTrue($p->canProcess('application/pdf'), 'canProcess(application/pdf) is true');
assertEquals(false, $p->canProcess('text/plain'), 'canProcess(text/plain) is false');
assertEquals(false, $p->canProcess('image/png'), 'canProcess(image/png) is false');

// process
$file   = makeIngestedPdf('invoice.pdf', 'simple.pdf');
$blocks = $p->process($file);

assertEquals(1, count($blocks), 'process() returns exactly one block');
assertTrue($blocks[0] instanceof FileContent, 'block is a FileContent');
assertEquals(SourceType::BASE64, $blocks[0]->sourceType, 'sourceType is BASE64');
assertEquals('application/pdf', $blocks[0]->mediaType, 'mediaType is application/pdf');
assertEquals('invoice.pdf', $blocks[0]->filename, 'filename is the original name');

// content is base64 of the file bytes
$decoded = base64_decode($blocks[0]->getContent(), true);
assertEquals(file_get_contents($file->absolutePath), $decoded, 'content decoded equals file bytes');

// getMaxSize matches FileAttachmentService::MAX_FILE_SIZE
assertEquals(10 * 1024 * 1024, $p->getMaxSize(), 'getMaxSize() returns 10 MB');

echo "\n";
echo $failures === 0 ? "ALL TESTS PASSED\n" : "$failures FAILURE(S)\n";
exit($failures > 0 ? 1 : 0);
