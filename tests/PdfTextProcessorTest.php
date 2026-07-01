<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Service\IngestedFile;
use Dalfred\Service\PdfTextProcessor;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;

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

echo "=== PdfTextProcessorTest ===\n";

$p = new PdfTextProcessor();

// canProcess
assertTrue($p->canProcess('application/pdf'), 'canProcess(application/pdf) is true');
assertEquals(false, $p->canProcess('text/plain'), 'canProcess(text/plain) is false');

// Happy path: simple.pdf
$file   = makeIngestedPdf('invoice.pdf', 'simple.pdf');
$blocks = $p->process($file);

assertEquals(1, count($blocks), 'process() returns exactly one block');
assertTrue($blocks[0] instanceof TextContent, 'block is a TextContent');

$content = $blocks[0]->content;
assertTrue(
    str_contains($content, '--- PDF joint : invoice.pdf (1 page) ---'),
    'opening frame mentions PDF, name and singular page count'
);
assertTrue(
    str_contains($content, 'Dalfred PDF fixture line one'),
    'extracted text from the fixture is present'
);
assertTrue(
    str_contains($content, '--- Fin du PDF ---'),
    'closing frame is present'
);

// Encrypted: throws RuntimeException with a user-facing message
$encrypted = makeIngestedPdf('locked.pdf', 'encrypted.pdf');
$threw = false;
$message = '';
try {
    $p->process($encrypted);
} catch (\RuntimeException $e) {
    $threw = true;
    $message = $e->getMessage();
}
assertTrue($threw, 'encrypted PDF throws RuntimeException');
assertTrue(
    str_contains($message, 'locked.pdf') && str_contains($message, 'chiffré'),
    'error message mentions filename and "chiffré"'
);

// getMaxSize is FileAttachmentService::MAX_FILE_SIZE (10 MB)
assertEquals(10 * 1024 * 1024, $p->getMaxSize(), 'getMaxSize() returns 10 MB');

echo "\n";
echo $failures === 0 ? "ALL TESTS PASSED\n" : "$failures FAILURE(S)\n";
exit($failures > 0 ? 1 : 0);
