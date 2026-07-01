<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Service\IngestedFile;
use Dalfred\Service\ImageFileProcessor;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;

$failures = 0;

function assertTrue(bool $cond, string $msg): void {
    global $failures;
    if (!$cond) { echo "  FAIL  $msg\n"; $failures++; } else { echo "  OK    $msg\n"; }
}

function makeImage(int $width, int $height, string $name = 'pic.png', string $mime = 'image/png'): IngestedFile {
    $tmp = tempnam(sys_get_temp_dir(), 'dalfredimg_') . '.png';
    $img = imagecreatetruecolor($width, $height);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 100, 50));
    imagepng($img, $tmp);
    imagedestroy($img);
    return new IngestedFile($tmp, basename($tmp), $name, $mime, filesize($tmp));
}

$proc = new ImageFileProcessor();

echo "=== canProcess ===\n";
assertTrue($proc->canProcess('image/png'), 'accepts image/png');
assertTrue($proc->canProcess('image/jpeg'), 'accepts image/jpeg');
assertTrue($proc->canProcess('image/gif'), 'accepts image/gif');
assertTrue($proc->canProcess('image/webp'), 'accepts image/webp');
assertTrue(!$proc->canProcess('text/plain'), 'rejects text/plain');
assertTrue(!$proc->canProcess('application/pdf'), 'rejects application/pdf');

echo "\n=== process small image ===\n";
$file = makeImage(100, 100);
$blocks = $proc->process($file);
assertTrue(count($blocks) === 1, 'returns one block');
assertTrue($blocks[0] instanceof ImageContent, 'block is ImageContent');
$ic = $blocks[0];
assertTrue($ic->sourceType === SourceType::BASE64, 'sourceType is BASE64');
assertTrue($ic->mediaType === 'image/png', 'mediaType is image/png');
$decoded = base64_decode($ic->getContent(), true);
assertTrue($decoded !== false && strlen($decoded) > 100, 'content is valid base64');
unlink($file->absolutePath);

echo "\n=== process resizes huge image ===\n";
$file = makeImage(4000, 3000);
$blocks = $proc->process($file);
$decoded = base64_decode($blocks[0]->getContent(), true);
$tmp = tempnam(sys_get_temp_dir(), 'check_') . '.png';
file_put_contents($tmp, $decoded);
$info = getimagesize($tmp);
assertTrue(max($info[0], $info[1]) <= 2048, 'longest edge clamped to 2048 px (got ' . max($info[0], $info[1]) . ')');
unlink($tmp);
unlink($file->absolutePath);

echo "\n=== getMaxSize ===\n";
assertTrue($proc->getMaxSize() === 10 * 1024 * 1024, 'max size is 10 MB');

echo "\n";
if ($failures > 0) { echo "FAILED: $failures assertion(s)\n"; exit(1); }
echo "ALL TESTS PASSED\n";
