<?php

declare(strict_types=1);

/**
 * Phase 0 기준선 측정 — 병목이 PHP 인지 NAS 인지 가른다.
 *
 * 4경로를 각각 반복 측정하고 차이로 비용을 분해한다.
 *
 *   A: MinIO 직접 GET          NAS 순수 읽기        (MINIO_DIRECT_BASE 설정 시)
 *   B: 앱 show 라우트          원본 패스스루
 *   C: 앱 resize 히트          preview 존재
 *   D: 앱 resize 미스          리사이즈 + (main 이면) bcrypt 202ms   (--miss 지정 시)
 *
 *   B - A = PHP/FPM 오버헤드
 *   C - B = preview 히트 자체 비용 (ETag 전체 바이트 md5 포함)
 *   D - C = 리사이즈 + bcrypt
 *
 * 사용법:
 *   php bench-baseline.php --config=bench.env
 *   php bench-baseline.php --config=bench.env --runs=30 --miss
 *   php bench-baseline.php --config=bench.env --csv=samples.csv
 *
 * 주의: --miss 는 실제로 preview 오브젝트를 생성한다. 종료 시 정리 명령을 출력하므로
 *       운영 버킷에 실행했다면 반드시 수행할 것.
 */
const WARMUP = 3;

$opts = parseArgs($argv);
$cfg = [];

if (isset($opts['config'])) {
    $cfg = loadEnvFile((string) $opts['config']);
}

foreach (['APP_BASE', 'MINIO_DIRECT_BASE', 'BUCKET', 'SAMPLE_PATH', 'HIT_SIZE', 'MISS_WIDTH_BASE', 'AUTH'] as $key) {
    $lower = str_replace('_', '-', strtolower($key));
    if (isset($opts[$lower])) {
        $cfg[$key] = (string) $opts[$lower];
    }
}

$appBase = rtrim((string) ($cfg['APP_BASE'] ?? ''), '/');
$directBase = rtrim((string) ($cfg['MINIO_DIRECT_BASE'] ?? ''), '/');
$bucket = trim((string) ($cfg['BUCKET'] ?? ''), '/');
$samplePath = trim((string) ($cfg['SAMPLE_PATH'] ?? ''), '/');
$hitSize = (string) ($cfg['HIT_SIZE'] ?? '300x300');
$missBase = (int) ($cfg['MISS_WIDTH_BASE'] ?? 1900);
$auth = (string) ($cfg['AUTH'] ?? '');

$runs = max(1, (int) ($opts['runs'] ?? 20));
$doMiss = isset($opts['miss']);
$csvOut = isset($opts['csv']) ? (string) $opts['csv'] : null;

if ($appBase === '' || $bucket === '' || $samplePath === '') {
    fwrite(STDERR, "APP_BASE / BUCKET / SAMPLE_PATH 는 필수다. --config 또는 --app-base 등으로 지정할 것.\n");
    fwrite(STDERR, "예시 설정은 bench.env.example 참고.\n");
    exit(1);
}

printf("대상   : %s\n", $appBase);
printf("버킷   : %s\n", $bucket);
printf("샘플   : %s\n", $samplePath);
printf("반복   : %d회 (워밍업 %d회 제외, keepalive 재사용 → TLS 핸드셰이크 비용 제외)\n\n", $runs, WARMUP);

$paths = [];

if ($directBase !== '') {
    $paths['A'] = [
        'label' => 'A  MinIO 직접 (NAS 순수 읽기)',
        'urls' => fn (int $i): string => $directBase.'/'.encodePath($samplePath),
    ];
}

$paths['B'] = [
    'label' => 'B  앱 show (원본 패스스루)',
    'urls' => fn (int $i): string => $appBase.'/'.rawurlencode($bucket).'/'.encodePath($samplePath),
];

$paths['C'] = [
    'label' => sprintf('C  앱 resize 히트 (%s)', $hitSize),
    'urls' => fn (int $i): string => $appBase.'/'.rawurlencode($bucket).'/'.rawurlencode($hitSize).'/'.encodePath($samplePath),
];

if ($doMiss) {
    $paths['D'] = [
        'label' => sprintf('D  앱 resize 미스 (%dx0 ~ %dx0)', $missBase, $missBase + $runs - 1),
        'urls' => fn (int $i): string => $appBase.'/'.rawurlencode($bucket).'/'.($missBase + $i).'x0/'.encodePath($samplePath),
    ];
}

$results = [];
$samples = [];

foreach ($paths as $key => $path) {
    // C 는 반드시 워밍 후 측정한다. 워밍 없이 재면 미스 비용이 섞인다.
    // D 는 매 반복이 미스여야 하므로 워밍하지 않는다.
    $measured = measure($path['urls'], $runs, $auth, $key !== 'D' ? WARMUP : 0);
    $results[$key] = summarize($measured) + ['label' => $path['label']];

    foreach ($measured as $n => $m) {
        $samples[] = [$key, $n, round($m['ms'], 2), round($m['ttfb'], 2), $m['bytes'], $m['status']];
    }
}

printf("%s %9s %9s %9s %9s %11s  %s\n", padRight('경로', 38), 'p50(ms)', 'p95(ms)', 'min', 'max', 'bytes', 'status');
printf("%s\n", str_repeat('-', 104));

foreach ($results as $r) {
    printf(
        "%s %9.1f %9.1f %9.1f %9.1f %11s  %s\n",
        padRight($r['label'], 38),
        $r['p50'],
        $r['p95'],
        $r['min'],
        $r['max'],
        number_format($r['bytes']),
        formatStatuses($r['statuses'])
    );
}

echo "\n분해 (p50 기준)\n";
echo str_repeat('-', 104), "\n";

if (isset($results['A'], $results['B'])) {
    $delta = $results['B']['p50'] - $results['A']['p50'];
    printf("  B - A   PHP/FPM 오버헤드            %+9.1f ms\n", $delta);
    if ($delta < 5) {
        echo "          → NAS 가 천장이다. Octane·vips 보다 리버스 프록시 캐시·PHP 우회를 우선할 것.\n";
    }
}

if (isset($results['B'], $results['C'])) {
    printf("  C - B   preview 히트 자체 비용      %+9.1f ms\n", $results['C']['p50'] - $results['B']['p50']);
}

if (isset($results['C'], $results['D'])) {
    $delta = $results['D']['p50'] - $results['C']['p50'];
    printf("  D - C   리사이즈 + bcrypt           %+9.1f ms\n", $delta);
    if ($delta > 180) {
        echo "          → 200ms 부근이면 ImageCacheService 의 bcrypt 가 포함된 것이다 (dev 에서 제거됨).\n";
    }
}

if ($csvOut !== null) {
    $fh = fopen($csvOut, 'w');
    fputcsv($fh, ['path', 'run', 'total_ms', 'ttfb_ms', 'bytes', 'status'], ',', '"', '\\');
    foreach ($samples as $row) {
        fputcsv($fh, $row, ',', '"', '\\');
    }
    fclose($fh);
    printf("\n원시 샘플: %s (%d행)\n", $csvOut, count($samples));
}

if ($doMiss) {
    echo "\n생성된 preview 정리 (ALIAS 를 실제 mc alias 로 바꿀 것):\n";
    printf(
        "  for w in \$(seq %d %d); do mc rm --recursive --force ALIAS/%s/previews/\${w}x0/; done\n",
        $missBase,
        $missBase + $runs - 1,
        $bucket
    );
}

/**
 * @param  callable(int): string  $urlFor
 * @return list<array{ms: float, ttfb: float, bytes: int, status: int}>
 */
function measure(callable $urlFor, int $runs, string $auth, int $warmup): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: image/webp,image/*,*/*'],
    ]);

    if ($auth !== '') {
        curl_setopt($ch, CURLOPT_USERPWD, $auth);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    }

    for ($i = 0; $i < $warmup; $i++) {
        curl_setopt($ch, CURLOPT_URL, $urlFor(0));
        curl_exec($ch);
    }

    $out = [];

    for ($i = 0; $i < $runs; $i++) {
        curl_setopt($ch, CURLOPT_URL, $urlFor($i));
        $body = curl_exec($ch);

        $out[] = [
            'ms' => curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000,
            'ttfb' => curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000,
            'bytes' => $body === false ? 0 : strlen($body),
            'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        ];
    }

    curl_close($ch);

    return $out;
}

/**
 * @param  list<array{ms: float, ttfb: float, bytes: int, status: int}>  $samples
 * @return array{p50: float, p95: float, min: float, max: float, bytes: int, statuses: array<int, int>}
 */
function summarize(array $samples): array
{
    $times = array_column($samples, 'ms');
    sort($times);

    $statuses = [];
    foreach ($samples as $s) {
        $statuses[$s['status']] = ($statuses[$s['status']] ?? 0) + 1;
    }

    $bytes = array_column($samples, 'bytes');

    return [
        'p50' => percentile($times, 0.50),
        'p95' => percentile($times, 0.95),
        'min' => $times[0],
        'max' => $times[count($times) - 1],
        'bytes' => (int) round(array_sum($bytes) / max(1, count($bytes))),
        'statuses' => $statuses,
    ];
}

/**
 * @param  list<float>  $sorted
 */
function percentile(array $sorted, float $p): float
{
    $n = count($sorted);
    $idx = (int) ceil($p * $n) - 1;

    return $sorted[max(0, min($n - 1, $idx))];
}

/**
 * @param  array<int, int>  $statuses
 */
function formatStatuses(array $statuses): string
{
    $parts = [];
    foreach ($statuses as $code => $count) {
        $parts[] = "{$code}×{$count}";
    }

    return implode(' ', $parts);
}

/**
 * 한글이 섞인 라벨을 표시 폭 기준으로 정렬한다. printf 의 %-Ns 는 바이트 기준이라 어긋난다.
 */
function padRight(string $s, int $width): string
{
    return $s.str_repeat(' ', max(1, $width - mb_strwidth($s)));
}

/**
 * 경로의 각 세그먼트를 인코딩한다. 슬래시는 보존한다.
 */
function encodePath(string $path): string
{
    return implode('/', array_map(rawurlencode(...), explode('/', $path)));
}

/**
 * @return array<string, string>
 */
function loadEnvFile(string $file): array
{
    if (! is_readable($file)) {
        fwrite(STDERR, "설정 파일을 읽을 수 없다: {$file}\n");
        exit(1);
    }

    $out = [];

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $out[trim($k)] = trim(trim($v), "\"'");
    }

    return $out;
}

/**
 * @param  list<string>  $argv
 * @return array<string, string|bool>
 */
function parseArgs(array $argv): array
{
    $out = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (! str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $out[$k] = $v;
        } else {
            $out[$arg] = true;
        }
    }

    return $out;
}
