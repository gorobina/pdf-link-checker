<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';

date_default_timezone_set('Europe/Zaporozhye');

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        $error = error_get_last();
        throw new RuntimeException(
            'Failed to create directory: ' . $path .
            ($error ? ' | PHP error: ' . $error['message'] : '')
        );
    }
}

function createJobId(): string
{
    return 'job_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
}

function createJobDir(string $jobsBaseDir, string $jobId): string
{
    $year = date('Y');
    $month = date('m');

    ensureDirectory($jobsBaseDir);
    ensureDirectory($jobsBaseDir . '/' . $year);
    ensureDirectory($jobsBaseDir . '/' . $year . '/' . $month);

    $jobDir = rtrim($jobsBaseDir, '/') . '/' . $year . '/' . $month . '/' . $jobId;
    ensureDirectory($jobDir);

    return $jobDir;
}

function validatePdfUrl(string $url, array $allowedSchemes): array
{
    $url = trim($url);

    if ($url === '') {
        return [false, 'PDF URL is empty.'];
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return [false, 'Invalid PDF URL format.'];
    }

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, $allowedSchemes, true)) {
        return [false, 'Only http/https URLs are allowed.'];
    }

    return [true, null];
}

function saveUploadedPdf(array $file, string $destinationPath, int $maxSize): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [false, 'File upload failed.'];
    }

    if (($file['size'] ?? 0) <= 0) {
        return [false, 'Uploaded file is empty.'];
    }

    if (($file['size'] ?? 0) > $maxSize) {
        return [false, 'Uploaded file exceeds maximum allowed size.'];
    }

    $originalName = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return [false, 'Only PDF files are allowed.'];
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return [false, 'Uploaded file is not valid.'];
    }

    if (!move_uploaded_file($tmpName, $destinationPath)) {
        return [false, 'Failed to move uploaded PDF file.'];
    }

    return [true, null];
}

function buildInputPayload(
    string $jobId,
    string $jobDir,
    string $sourceType,
    string $sourceValue,
    ?string $originalName,
    array $config,
    ?string $inputPdfPath
): array {
    return [
        'job_id' => $jobId,
        'created_at' => date(DATE_ATOM),
        'source' => [
            'type' => $sourceType,
            'value' => $sourceValue,
            'original_name' => $originalName,
        ],
        'options' => [
            'timeout' => $config['limits']['timeout'],
            'max_links' => $config['limits']['max_links'],
            'follow_redirects' => $config['limits']['follow_redirects'],
            'http_method' => $config['limits']['http_method'],
        ],
        'paths' => [
            'job_dir' => $jobDir,
            'input_pdf' => $inputPdfPath,
            'result_json' => $jobDir . '/result.json',
            'stdout_log' => $jobDir . '/stdout.log',
            'stderr_log' => $jobDir . '/stderr.log',
        ],
    ];
}

function runPythonJob(array $config, string $inputJsonPath, string $jobDir): array
{
    $pythonBin = $config['paths']['python_bin'];
    $pythonScript = $config['paths']['python_script'];
    $stdoutLog = $jobDir . '/stdout.log';
    $stderrLog = $jobDir . '/stderr.log';

    $command = sprintf(
        '%s %s --input-json %s > %s 2> %s',
        escapeshellarg($pythonBin),
        escapeshellarg($pythonScript),
        escapeshellarg($inputJsonPath),
        escapeshellarg($stdoutLog),
        escapeshellarg($stderrLog)
    );

    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);

    return [
        'command' => $command,
        'exit_code' => $exitCode,
        'stdout_log' => $stdoutLog,
        'stderr_log' => $stderrLog,
    ];
}

function readJsonFile(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        return null;
    }

    $data = json_decode($content, true);
    return is_array($data) ? $data : null;
}

function readLogTail(string $path, int $maxLen = 4000): string
{
    if (!is_file($path)) {
        return '';
    }

    $content = (string)file_get_contents($path);
    if ($content === '') {
        return '';
    }

    return mb_substr($content, -$maxLen);
}

function getStatusClass(?int $statusCode, ?string $error): string
{
    if ($error !== null) {
        return 'status-error';
    }

    if ($statusCode === null) {
        return 'status-unknown';
    }

    if ($statusCode >= 200 && $statusCode < 300) {
        return 'status-2xx';
    }

    if ($statusCode >= 300 && $statusCode < 400) {
        return 'status-3xx';
    }

    if ($statusCode >= 400 && $statusCode < 500) {
        return 'status-4xx';
    }

    if ($statusCode >= 500) {
        return 'status-5xx';
    }

    return 'status-unknown';
}

$errorMessage = null;
$successMessage = null;
$result = null;
$jobInfo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdfUrl = trim((string)($_POST['pdf_url'] ?? ''));
        $hasUpload = isset($_FILES['pdf_file']) && ($_FILES['pdf_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($pdfUrl === '' && !$hasUpload) {
            throw new RuntimeException('Please provide a PDF URL or upload a PDF file.');
        }

        if ($pdfUrl !== '' && $hasUpload) {
            throw new RuntimeException('Please use only one source: either PDF URL or PDF upload.');
        }

        $jobId = createJobId();
        $jobDir = createJobDir($config['paths']['jobs_dir'], $jobId);

        $sourceType = '';
        $sourceValue = '';
        $originalName = null;
        $inputPdfPath = null;

        if ($pdfUrl !== '') {
            [$isValidUrl, $urlError] = validatePdfUrl($pdfUrl, $config['security']['allowed_url_schemes']);
            if (!$isValidUrl) {
                throw new RuntimeException((string)$urlError);
            }

            $sourceType = 'url';
            $sourceValue = $pdfUrl;
        } else {
            $sourceType = 'upload';
            $originalName = (string)($_FILES['pdf_file']['name'] ?? 'uploaded.pdf');
            $sourceValue = $originalName;
            $inputPdfPath = $jobDir . '/source.pdf';

            [$uploadOk, $uploadError] = saveUploadedPdf(
                $_FILES['pdf_file'],
                $inputPdfPath,
                $config['limits']['max_pdf_size_bytes']
            );

            if (!$uploadOk) {
                throw new RuntimeException((string)$uploadError);
            }
        }

        $inputPayload = buildInputPayload(
            $jobId,
            $jobDir,
            $sourceType,
            $sourceValue,
            $originalName,
            $config,
            $inputPdfPath
        );

        $inputJsonPath = $jobDir . '/input.json';
        file_put_contents(
            $inputJsonPath,
            json_encode($inputPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $jobInfo = runPythonJob($config, $inputJsonPath, $jobDir);

        $resultJsonPath = $jobDir . '/result.json';
        $result = readJsonFile($resultJsonPath);

        if ($result === null) {
            $stderr = readLogTail($jobDir . '/stderr.log');
            $stdout = readLogTail($jobDir . '/stdout.log');

            throw new RuntimeException(
                "Python did not produce result.json.\n\nSTDERR:\n{$stderr}\n\nSTDOUT:\n{$stdout}"
            );
        }

        $successMessage = 'Job completed successfully.';
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= h($config['app_name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #0d1117;
            color: #e6edf3;
        }
        .container {
            max-width: 1180px;
            margin: 0 auto;
            padding: 24px;
        }
        .card {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        h1, h2, h3 {
            margin-top: 0;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }
        input[type="text"],
        input[type="url"],
        input[type="file"],
        textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #30363d;
            background: #0d1117;
            color: #e6edf3;
            margin-bottom: 14px;
        }
        button {
            background: #238636;
            border: 0;
            border-radius: 6px;
            color: #fff;
            padding: 10px 18px;
            cursor: pointer;
            font-weight: bold;
        }
        button:hover {
            background: #2ea043;
        }
        .note {
            color: #8b949e;
            font-size: 14px;
        }
        .error {
            background: #2d0d0d;
            border: 1px solid #f85149;
            color: #ffb3b3;
            padding: 14px;
            border-radius: 8px;
            white-space: pre-wrap;
        }
        .success {
            background: #0f2419;
            border: 1px solid #238636;
            color: #9be9a8;
            padding: 14px;
            border-radius: 8px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }
        .summary-item {
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 8px;
            padding: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        th, td {
            border-bottom: 1px solid #30363d;
            text-align: left;
            padding: 10px 8px;
            vertical-align: top;
        }
        th {
            color: #8b949e;
        }
        a {
            color: #58a6ff;
            word-break: break-word;
        }
        .status-pill {
            display: inline-block;
            min-width: 72px;
            text-align: center;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 13px;
            font-weight: bold;
        }
        .status-2xx { background: #16351f; color: #7ee787; }
        .status-3xx { background: #2f2a12; color: #e3b341; }
        .status-4xx { background: #3a1a1a; color: #ff7b72; }
        .status-5xx { background: #4b1d1d; color: #ffa198; }
        .status-error,
        .status-unknown { background: #21262d; color: #c9d1d9; }
        pre {
            white-space: pre-wrap;
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 8px;
            padding: 12px;
            overflow-x: auto;
        }
        .two-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        @media (max-width: 900px) {
            .two-columns {
                grid-template-columns: 1fr;
            }
        }
		.summary-item-wide {
			grid-column: span 3;
		}

		.summary-value-url {
			word-break: break-word;
			overflow-wrap: anywhere;
		}
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <h1><?= h($config['app_name']) ?></h1>
        <p class="note">
            Submit a PDF URL or upload a PDF file. The PHP UI creates an isolated job folder,
            calls Python CLI, then renders the parsed results from <code>result.json</code>.
        </p>

        <?php if ($errorMessage !== null): ?>
            <div class="error"><?= h($errorMessage) ?></div>
        <?php endif; ?>

        <?php if ($successMessage !== null): ?>
            <div class="success"><?= h($successMessage) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <label for="pdf_url">PDF URL</label>
            <input type="url" id="pdf_url" name="pdf_url" placeholder="https://example.com/file.pdf">

            <p class="note">OR</p>

            <label for="pdf_file">Upload PDF</label>
            <input type="file" id="pdf_file" name="pdf_file" accept="application/pdf,.pdf">

            <button type="submit">Run checker</button>
        </form>
    </div>

    <?php if ($result !== null): ?>
        <div class="card">
            <h2>Run Summary</h2>

            <div class="summary-grid">
                <div class="summary-item">
                    <strong>Job ID</strong><br>
                    <?= h($result['job_id'] ?? '') ?>
                </div>
				<div class="summary-item summary-item-wide">
					<strong>Source</strong><br>
					<?= h($result['source']['type'] ?? '') ?>:<br>
					<span class="summary-value-url"><?= h($result['source']['value'] ?? '') ?></span>
				</div>
                <div class="summary-item">
                    <strong>Total Links</strong><br>
                    <?= h((string)($result['summary']['total_links'] ?? '0')) ?>
                </div>
                <div class="summary-item">
                    <strong>Unique Links</strong><br>
                    <?= h((string)($result['summary']['unique_links'] ?? '0')) ?>
                </div>
                <div class="summary-item">
                    <strong>Pages</strong><br>
                    <?= h((string)($result['pdf']['pages'] ?? '0')) ?>
                </div>
                <div class="summary-item">
                    <strong>Processed At</strong><br>
                    <?= h($result['processed_at'] ?? '') ?>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Status Groups</h2>
            <div class="summary-grid">
                <?php foreach (($result['summary']['status_groups'] ?? []) as $group => $count): ?>
                    <div class="summary-item">
                        <strong><?= h((string)$group) ?></strong><br>
                        <?= h((string)$count) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!empty($result['jira_report']['text'])): ?>
            <div class="card">
                <h2>Jira-friendly Broken Link Report</h2>
                <pre><?= h((string)$result['jira_report']['text']) ?></pre>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Links</h2>
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>Page</th>
                    <th>URL</th>
                    <th>Status</th>
                    <th>Final URL</th>
                    <th>Error</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (($result['links'] ?? []) as $index => $link): ?>
                    <?php
                    $statusCode = isset($link['status_code']) ? (int)$link['status_code'] : null;
                    $error = $link['error'] ?? null;
                    $statusClass = getStatusClass($statusCode, $error);
                    $statusLabel = $error !== null ? 'ERROR' : ($statusCode !== null ? (string)$statusCode : 'N/A');
                    ?>
                    <tr>
                        <td><?= h((string)($index + 1)) ?></td>
                        <td><?= h((string)($link['page'] ?? '')) ?></td>
                        <td>
                            <?php if (!empty($link['url'])): ?>
                                <a href="<?= h((string)$link['url']) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= h((string)$link['url']) ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-pill <?= h($statusClass) ?>">
                                <?= h($statusLabel) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($link['final_url'])): ?>
                                <a href="<?= h((string)$link['final_url']) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= h((string)$link['final_url']) ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td><?= h((string)($link['error'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($jobInfo !== null): ?>
            <div class="card">
                <h2>Technical Info</h2>
                <div class="two-columns">
                    <div>
                        <h3>Execution</h3>
                        <pre><?= h(print_r($jobInfo, true)) ?></pre>
                    </div>
                    <div>
                        <h3>Errors</h3>
                        <pre><?= h(print_r($result['errors'] ?? [], true)) ?></pre>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
/*echo '<pre>';
print_r($config);
echo '</pre>';
exit;*/
?>
</body>
</html>