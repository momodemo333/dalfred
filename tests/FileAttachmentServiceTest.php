<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Chat\ContentBlocks\AttachmentMetaContent;
use Dalfred\Service\FileAttachmentService;
use Dalfred\Service\IngestedFile;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
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

function makeIngestedText(string $name = 'note.txt'): IngestedFile {
    $tmp = tempnam(sys_get_temp_dir(), 'fas_') . '.txt';
    file_put_contents($tmp, "Some text content");
    return new IngestedFile($tmp, basename($tmp), $name, 'text/plain', strlen("Some text content"));
}

function makeIngestedImage(string $name = 'pic.png'): IngestedFile {
    $tmp = tempnam(sys_get_temp_dir(), 'fas_') . '.png';
    $img = imagecreatetruecolor(50, 50);
    imagepng($img, $tmp);
    imagedestroy($img);
    return new IngestedFile($tmp, basename($tmp), $name, 'image/png', filesize($tmp));
}

$svc = new FileAttachmentService();

echo "=== buildContentBlocks: text only ===\n";
$ing = [makeIngestedText('note.txt')];
$blocks = $svc->buildContentBlocks($ing, 'Analyse this');
assertEquals(3, count($blocks), 'three blocks total (message + text + meta)');
assertTrue($blocks[0] instanceof TextContent, 'first block is the user message TextContent');
assertEquals('Analyse this', $blocks[0]->getContent(), 'first block carries the user message');
assertTrue($blocks[1] instanceof TextContent, 'second block is the file TextContent');
assertTrue($blocks[2] instanceof AttachmentMetaContent, 'third block is the meta');
unlink($ing[0]->absolutePath);

echo "\n=== buildContentBlocks: image only ===\n";
$ing = [makeIngestedImage('shot.png')];
$blocks = $svc->buildContentBlocks($ing, 'Describe');
assertEquals(3, count($blocks), 'three blocks total (message + image + meta)');
assertTrue($blocks[1] instanceof ImageContent, 'second block is the ImageContent');
assertTrue($blocks[2] instanceof AttachmentMetaContent, 'third block is the meta');
assertEquals('image', $blocks[2]->kind, 'meta kind = image');
unlink($ing[0]->absolutePath);

echo "\n=== buildContentBlocks: empty message text is allowed ===\n";
$ing = [makeIngestedText('only.txt')];
$blocks = $svc->buildContentBlocks($ing, '');
assertEquals(2, count($blocks), 'two blocks (no user TextContent for empty message)');
assertTrue($blocks[0] instanceof TextContent, 'first is the file content');
assertTrue($blocks[1] instanceof AttachmentMetaContent, 'second is the meta');
unlink($ing[0]->absolutePath);

echo "\n=== validateUploadedFile (without filesystem move): MIME mismatch ===\n";
$tmp = tempnam(sys_get_temp_dir(), 'fas_');
file_put_contents($tmp, 'Just plain text masquerading as png');
$fake = [
    'name'     => 'fake.png',
    'type'     => 'image/png',
    'tmp_name' => $tmp,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($tmp),
];
$err = $svc->validateUploadedFile($fake, 'anthropic', 'claude-opus-4-7');
assertTrue($err !== null, 'rejects MIME-extension mismatch');
unlink($tmp);

echo "\n=== validateUploadedFile: oversized ===\n";
$tmp = tempnam(sys_get_temp_dir(), 'fas_');
file_put_contents($tmp, str_repeat('A', 11 * 1024 * 1024));
$fake = [
    'name'     => 'big.txt',
    'type'     => 'text/plain',
    'tmp_name' => $tmp,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($tmp),
];
$err = $svc->validateUploadedFile($fake, 'anthropic', 'claude-opus-4-7');
assertTrue($err !== null && str_contains($err, 'volumineux'), 'rejects file > 10 MB');
unlink($tmp);

echo "\n=== validateUploadedFile: image rejected for text-only provider ===\n";
$tmp = tempnam(sys_get_temp_dir(), 'fas_') . '.png';
$img = imagecreatetruecolor(50, 50);
imagepng($img, $tmp);
imagedestroy($img);
$fake = [
    'name'     => 'pic.png',
    'type'     => 'image/png',
    'tmp_name' => $tmp,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($tmp),
];
$err = $svc->validateUploadedFile($fake, 'mistral', 'mistral-tiny');
assertTrue($err !== null, 'rejects image for non-vision provider');
unlink($tmp);

echo "\n=== sanitizeFilename ===\n";
assertEquals('foo_bar.png', $svc->sanitizeFilename('foo bar.png'), 'spaces become underscores');
assertEquals('passwd', $svc->sanitizeFilename('../../../etc/passwd'), 'path traversal stripped');
$long = str_repeat('a', 100) . '.png';
$out = $svc->sanitizeFilename($long);
assertTrue(strlen($out) <= 50, 'long names truncated (got ' . strlen($out) . ')');
assertTrue(str_ends_with($out, '.png'), 'extension preserved');

function makeIngestedPdfFixture(string $name = 'invoice.pdf', string $fixture = 'simple.pdf'): IngestedFile {
    $abs = __DIR__ . '/fixtures/' . $fixture;
    return new IngestedFile(
        absolutePath: $abs,
        relativePath: 'thread_test/' . $name,
        originalName: $name,
        mimeType:     'application/pdf',
        sizeBytes:    filesize($abs),
    );
}

echo "\n--- PDF: validateUploadedFile accepts application/pdf ---\n";

$svc = new FileAttachmentService();

$tmpPdf = tempnam(sys_get_temp_dir(), 'dalfredpdf');
copy(__DIR__ . '/fixtures/simple.pdf', $tmpPdf);

$upload = [
    'name'     => 'invoice.pdf',
    'type'     => 'application/pdf',
    'tmp_name' => $tmpPdf,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($tmpPdf),
];
assertEquals(
    null,
    $svc->validateUploadedFile($upload, 'anthropic', 'claude-sonnet-4-20250514'),
    'PDF accepted on Anthropic'
);
assertEquals(
    null,
    $svc->validateUploadedFile($upload, 'mistral', 'pixtral-large-latest'),
    'PDF accepted on Mistral (Smalot fallback path)'
);

unlink($tmpPdf);

echo "\n--- PDF: anti-spoofing (.pdf with text mime → reject) ---\n";

$tmpFakePdf = tempnam(sys_get_temp_dir(), 'dalfredfake');
file_put_contents($tmpFakePdf, "This is plain text, not a PDF.\n");
$upload = [
    'name'     => 'fake.pdf',
    'type'     => 'application/pdf',
    'tmp_name' => $tmpFakePdf,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($tmpFakePdf),
];
$err = $svc->validateUploadedFile($upload, 'anthropic', 'claude-sonnet-4-20250514');
assertTrue(
    $err !== null && str_contains($err, 'ne correspond pas à l\'extension'),
    'fake .pdf containing text is rejected by anti-spoofing'
);
unlink($tmpFakePdf);

echo "\n--- PDF: anti-spoofing (.txt with PDF mime → reject) ---\n";

$tmpRealPdfWrongName = tempnam(sys_get_temp_dir(), 'dalfredwn');
copy(__DIR__ . '/fixtures/simple.pdf', $tmpRealPdfWrongName);
$upload = [
    'name'     => 'note.txt',
    'type'     => 'application/pdf',
    'tmp_name' => $tmpRealPdfWrongName,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($tmpRealPdfWrongName),
];
$err = $svc->validateUploadedFile($upload, 'anthropic', 'claude-sonnet-4-20250514');
assertTrue(
    $err !== null && str_contains($err, 'ne correspond pas à l\'extension'),
    'PDF named .txt is rejected by anti-spoofing'
);
unlink($tmpRealPdfWrongName);

echo "\n--- PDF: buildContentBlocks routes to native processor on Anthropic ---\n";

$file   = makeIngestedPdfFixture('invoice.pdf');
$blocks = $svc->buildContentBlocks([$file], 'Read this', 'anthropic', 'claude-sonnet-4-20250514');

assertEquals(3, count($blocks), 'native path produces 3 blocks (text, file, meta)');
assertTrue(
    $blocks[0] instanceof TextContent,
    'first block is the user TextContent'
);
assertTrue(
    $blocks[1] instanceof \NeuronAI\Chat\Messages\ContentBlocks\FileContent,
    'second block is FileContent (native PDF)'
);
assertTrue(
    $blocks[2] instanceof AttachmentMetaContent,
    'third block is AttachmentMetaContent'
);
assertEquals('text', $blocks[2]->kind, 'meta kind is text (PDF chip uses file-text icon)');

echo "\n--- PDF: buildContentBlocks routes to Smalot processor on Mistral ---\n";

$file   = makeIngestedPdfFixture('invoice.pdf');
$blocks = $svc->buildContentBlocks([$file], 'Read this', 'mistral', 'pixtral-large-latest');

assertEquals(3, count($blocks), 'fallback path produces 3 blocks (text, text, meta)');
assertTrue($blocks[0] instanceof TextContent, 'first block is the user TextContent');
assertTrue(
    $blocks[1] instanceof TextContent,
    'second block is TextContent (Smalot fallback)'
);
assertTrue(
    str_contains($blocks[1]->content, '--- PDF joint : invoice.pdf'),
    'second block contains the PDF frame'
);
assertTrue($blocks[2] instanceof AttachmentMetaContent, 'third block is AttachmentMetaContent');

echo "\n--- CSV: validateUploadedFile accepts a real CSV ---\n";

// finfo detects comma-separated CSV as text/csv, semicolon as text/plain;
// both must be accepted. Test the text/csv path (the one that used to be rejected).
$tmpCsv = tempnam(sys_get_temp_dir(), 'dalfredcsv');
file_put_contents($tmpCsv, "nom,prenom,age\nDupont,Jean,42\nMartin,Marie,35\n");
$upload = [
    'name'     => 'clients.csv',
    'type'     => 'text/csv',
    'tmp_name' => $tmpCsv,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($tmpCsv),
];
assertEquals(
    null,
    $svc->validateUploadedFile($upload, 'anthropic', 'claude-sonnet-4-20250514'),
    'CSV accepted (text/csv mime)'
);
// Even on a text-only provider (no vision), CSV must pass — it is plain text.
assertEquals(
    null,
    $svc->validateUploadedFile($upload, 'mistral', 'mistral-tiny'),
    'CSV accepted on text-only provider'
);
unlink($tmpCsv);

echo "\n--- CSV: buildContentBlocks routes to TextFileProcessor ---\n";

$tmpCsv = tempnam(sys_get_temp_dir(), 'dalfredcsv');
file_put_contents($tmpCsv, "nom,prenom,age\nDupont,Jean,42\n");
$file = new IngestedFile(
    absolutePath: $tmpCsv,
    relativePath: 'thread_test/clients.csv',
    originalName: 'clients.csv',
    mimeType:     'text/csv',
    sizeBytes:    filesize($tmpCsv),
);
$blocks = $svc->buildContentBlocks([$file], 'Analyse ce fichier', 'anthropic', 'claude-sonnet-4-20250514');
assertEquals(3, count($blocks), 'CSV path produces 3 blocks (text, text, meta)');
assertTrue($blocks[1] instanceof TextContent, 'CSV content block is TextContent');
assertTrue(str_contains($blocks[1]->content, 'Dupont,Jean,42'), 'CSV rows present verbatim');
assertTrue($blocks[2] instanceof AttachmentMetaContent, 'third block is AttachmentMetaContent');
assertEquals('text', $blocks[2]->kind, 'CSV meta kind is text');
unlink($tmpCsv);

echo "\n";
if ($failures > 0) { echo "FAILED: $failures assertion(s)\n"; exit(1); }
echo "ALL TESTS PASSED\n";
