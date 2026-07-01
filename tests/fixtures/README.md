# PDF test fixtures

- `simple.pdf` — 1 page, contains the literal lines `Dalfred PDF fixture line one` and `Second line of text`. Generated programmatically. Used for happy-path tests.
- `encrypted.pdf` — `simple.pdf` re-encrypted with `qpdf --encrypt secret-user secret-owner 256`. Used to verify the "PDF chiffré" error path in `PdfTextProcessor`.

## Regenerate

`simple.pdf` is a hand-crafted minimal PDF 1.4 with two text lines. The full generator is preserved as a fenced PHP block here so future maintainers don't need to dig through git history.

Run inside the docker dev container (paths are absolute):

```bash
docker exec dolibarr-php-1 php -r '
$pdf = "%PDF-1.4\n";
$objects = [
    "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
    "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
    "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
];
$stream = "BT\n/F1 12 Tf\n72 720 Td\n(Dalfred PDF fixture line one) Tj\n0 -16 Td\n(Second line of text) Tj\nET\n";
$objects[] = "4 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream\nendobj\n";
$objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
$offsets = [0]; $body = "";
foreach ($objects as $obj) { $offsets[] = strlen($pdf) + strlen($body); $body .= $obj; }
$pdf .= $body;
$xrefOffset = strlen($pdf);
$pdf .= "xref\n0 " . (count($objects)+1) . "\n0000000000 65535 f \n";
foreach (array_slice($offsets, 1) as $off) { $pdf .= sprintf("%010d 00000 n \n", $off); }
$pdf .= "trailer\n<< /Size " . (count($objects)+1) . " /Root 1 0 R >>\nstartxref\n$xrefOffset\n%%EOF\n";
file_put_contents("/var/www/html/custom/dalfred/tests/fixtures/simple.pdf", $pdf);
'

docker exec dolibarr-php-1 bash -c "cd /var/www/html/custom/dalfred/tests/fixtures && qpdf --encrypt secret-user secret-owner 256 -- simple.pdf encrypted.pdf"
```

If `qpdf` is missing in the container: `docker exec -u 0 dolibarr-php-1 apt-get install -y qpdf`.
