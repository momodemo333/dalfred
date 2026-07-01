<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Service\IngestedFile;
use Dalfred\Service\TextFileProcessor;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;

$failures = 0;

function assertTrue(bool $cond, string $msg): void {
    global $failures;
    if (!$cond) { echo "  FAIL  $msg\n"; $failures++; } else { echo "  OK    $msg\n"; }
}

function assertContains(string $needle, string $haystack, string $msg): void {
    global $failures;
    if (!str_contains($haystack, $needle)) {
        echo "  FAIL  $msg\n    expected to find: $needle\n    in (first 200): " . substr($haystack, 0, 200) . "\n";
        $failures++;
    } else { echo "  OK    $msg\n"; }
}

// Helper: create a temp file with given content and return an IngestedFile pointing to it.
function makeIngested(string $content, string $name = 'note.txt', string $mime = 'text/plain'): IngestedFile {
    $tmp = tempnam(sys_get_temp_dir(), 'dalfredtest_');
    file_put_contents($tmp, $content);
    return new IngestedFile($tmp, basename($tmp), $name, $mime, strlen($content));
}

$proc = new TextFileProcessor();

echo "=== canProcess ===\n";
assertTrue($proc->canProcess('text/plain'), 'accepts text/plain');
assertTrue($proc->canProcess('text/markdown'), 'accepts text/markdown');
assertTrue($proc->canProcess('text/x-log'), 'accepts text/x-log');
assertTrue($proc->canProcess('text/csv'), 'accepts text/csv');
assertTrue($proc->canProcess('application/csv'), 'accepts application/csv');
assertTrue(!$proc->canProcess('image/png'), 'rejects image/png');
assertTrue(!$proc->canProcess('application/pdf'), 'rejects application/pdf');

echo "\n=== process small file ===\n";
$file = makeIngested("Hello\nWorld", 'greeting.txt');
$blocks = $proc->process($file);
assertTrue(count($blocks) === 1, 'returns one block');
assertTrue($blocks[0] instanceof TextContent, 'block is TextContent');
$txt = $blocks[0]->getContent();
assertContains('--- Fichier joint : greeting.txt ---', $txt, 'opening marker present');
assertContains('Hello', $txt, 'original content present');
assertContains('--- Fin du fichier ---', $txt, 'closing marker present');
unlink($file->absolutePath);

echo "\n=== process truncates beyond MAX_TEXT_SIZE ===\n";
$bigContent = str_repeat('A', 250 * 1024); // 250 KB > 200 KB limit
$file = makeIngested($bigContent, 'big.log');
$blocks = $proc->process($file);
$txt = $blocks[0]->getContent();
assertContains('fichier tronqué', $txt, 'truncation suffix present');
assertTrue(strlen($txt) < 250 * 1024, 'output shorter than original input');
unlink($file->absolutePath);

echo "\n=== process a CSV file (treated as text) ===\n";
$csv = "nom,prenom,age\nDupont,Jean,42\nMartin,Marie,35\n";
$file = makeIngested($csv, 'clients.csv', 'text/csv');
$blocks = $proc->process($file);
$txt = $blocks[0]->getContent();
assertContains('--- Fichier joint : clients.csv ---', $txt, 'CSV opening marker present');
assertContains('Dupont,Jean,42', $txt, 'CSV rows present verbatim');
unlink($file->absolutePath);

echo "\n=== process handles ISO-8859-1 input ===\n";
$iso = mb_convert_encoding("Café à crème", 'ISO-8859-1', 'UTF-8');
$file = makeIngested($iso, 'menu.txt');
$blocks = $proc->process($file);
$txt = $blocks[0]->getContent();
assertContains('Café à crème', $txt, 'ISO content converted to UTF-8');
unlink($file->absolutePath);

echo "\n=== getMaxSize ===\n";
assertTrue($proc->getMaxSize() >= 50 * 1024, 'max size at least 50 KB');

echo "\n";
if ($failures > 0) { echo "FAILED: $failures assertion(s)\n"; exit(1); }
echo "ALL TESTS PASSED\n";
