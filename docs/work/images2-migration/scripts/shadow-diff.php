<?php

declare(strict_types=1);

/**
 * Phase 2/3 동등성 검증 — 운영(A)과 images2(B) 의 응답을 URL 단위로 비교한다.
 *
 * 비교 항목: HTTP 상태, Content-Type, 이미지 실제 픽셀 크기, 본문 바이트, 주요 헤더.
 *
 * B 는 별도 스토리지를 쓰므로 바이트 완전 일치는 보장되지 않는다.
 * 판정 우선순위는 상태 → 픽셀 크기 → 바이트 순이며, 바이트 차이는 참고값이다.
 *
 * 사용법:
 *   php shadow-diff.php --a=https://images.example.com --b=https://images2.example.com --urls=urls.top.txt
 *   php shadow-diff.php ... --auth-b=user:pass --concurrency=8 --out=diff.csv
 *   php shadow-diff.php --b=https://images2.example.com --urls=urls.top.txt --warm-only
 *
 * --warm-only 는 B 만 1회씩 호출해 preview 를 생성한다 (Phase 5 부하 테스트 사전 워밍).
 */
$opts = parseArgs($argv);

$baseA = rtrim((string) ($opts['a'] ?? ''), '/');
$baseB = rtrim((string) ($opts['b'] ?? ''), '/');
$urlFile = (string) ($opts['urls'] ?? '');
$authA = (string) ($opts['auth-a'] ?? '');
$authB = (string) ($opts['auth-b'] ?? '');
$concurrency = max(1, (int) ($opts['concurrency'] ?? 8));
$tolerance = (float) ($opts['tolerance'] ?? 2.0);
$csvOut = isset($opts['out']) ? (string) $opts['out'] : null;
$warmOnly = isset($opts['warm-only']);

if ($urlFile === '' || ! is_readable($urlFile)) {
    fwrite(STDERR, "--urls=<파일> 이 필요하다 (URL 경로 한 줄에 하나).\n");
    exit(1);
}

if ($baseB === '' || (! $warmOnly && $baseA === '')) {
    fwrite(STDERR, $warmOnly ? "--b 가 필요하다.\n" : "--a 와 --b 가 모두 필요하다.\n");
    exit(1);
}

$urls = array_values(array_filter(array_map(
    static fn (string $l): string => trim($l),
    file($urlFile, FILE_IGNORE_NEW_LINES)
), static fn (string $l): bool => $l !== '' && ! str_starts_with($l, '#')));

if ($urls === []) {
    fwrite(STDERR, "URL 이 없다: {$urlFile}\n");
    exit(1);
}

printf("URL   : %d 건 (%s)\n", count($urls), $urlFile);
printf("동시성: %d\n", $concurrency);

if ($warmOnly) {
    printf("워밍  : %s\n\n", $baseB);
    $jobs = [];
    foreach ($urls as $i => $path) {
        $jobs[] = ['key' => "B:{$i}", 'url' => $baseB.normalizePath($path), 'auth' => $authB];
    }

    $res = runBatch($jobs, $concurrency);
    $statuses = [];
    $totalMs = 0.0;

    foreach ($res as $r) {
        $statuses[$r['status']] = ($statuses[$r['status']] ?? 0) + 1;
        $totalMs += $r['ms'];
    }

    ksort($statuses);
    echo "\n상태 분포: ", formatStatuses($statuses), "\n";
    printf("총 소요  : %.1fs (평균 %.1fms/건)\n", $totalMs / 1000, $totalMs / max(1, count($res)));
    printf("\n워밍 완료. preview 가 생성되었으므로 이제 부하 테스트를 시작할 수 있다.\n");
    exit(array_key_exists(200, $statuses) && $statuses[200] === count($urls) ? 0 : 1);
}

printf("A     : %s\n", $baseA);
printf("B     : %s\n\n", $baseB);

$jobs = [];
foreach ($urls as $i => $path) {
    $normalized = normalizePath($path);
    $jobs[] = ['key' => "A:{$i}", 'url' => $baseA.$normalized, 'auth' => $authA];
    $jobs[] = ['key' => "B:{$i}", 'url' => $baseB.$normalized, 'auth' => $authB];
}

$res = runBatch($jobs, $concurrency);

$verdicts = [];
$rows = [];
$cookieA = 0;
$cookieB = 0;
$etagB = 0;

foreach ($urls as $i => $path) {
    $a = $res["A:{$i}"] ?? null;
    $b = $res["B:{$i}"] ?? null;

    if ($a === null || $b === null) {
        record($verdicts, $rows, 'ERROR', $path, '응답 누락', $a, $b);

        continue;
    }

    $cookieA += isset($a['headers']['set-cookie']) ? 1 : 0;
    $cookieB += isset($b['headers']['set-cookie']) ? 1 : 0;
    $etagB += isset($b['headers']['etag']) ? 1 : 0;

    if ($a['status'] !== $b['status']) {
        record($verdicts, $rows, 'STATUS', $path, "A={$a['status']} B={$b['status']}", $a, $b);

        continue;
    }

    if ($a['status'] >= 400) {
        // 양쪽 모두 같은 오류 → 동등하다.
        record($verdicts, $rows, 'OK_ERR', $path, "양쪽 {$a['status']}", $a, $b);

        continue;
    }

    $ctA = strtok((string) ($a['headers']['content-type'] ?? ''), ';');
    $ctB = strtok((string) ($b['headers']['content-type'] ?? ''), ';');

    if ($ctA !== $ctB) {
        record($verdicts, $rows, 'CONTENT_TYPE', $path, "A={$ctA} B={$ctB}", $a, $b);

        continue;
    }

    $dimA = dimensions($a['body']);
    $dimB = dimensions($b['body']);

    if ($dimA === null || $dimB === null) {
        record(
            $verdicts,
            $rows,
            'BODY',
            $path,
            sprintf('이미지 파싱 실패 A=%s B=%s', $dimA === null ? 'fail' : 'ok', $dimB === null ? 'fail' : 'ok'),
            $a,
            $b
        );

        continue;
    }

    if ($dimA !== $dimB) {
        record(
            $verdicts,
            $rows,
            'DIMENSION',
            $path,
            sprintf('A=%dx%d B=%dx%d', $dimA[0], $dimA[1], $dimB[0], $dimB[1]),
            $a,
            $b
        );

        continue;
    }

    $lenA = strlen($a['body']);
    $lenB = strlen($b['body']);
    $diffPct = $lenA > 0 ? abs($lenA - $lenB) / $lenA * 100 : 0.0;

    if ($diffPct > $tolerance) {
        record(
            $verdicts,
            $rows,
            'BYTES',
            $path,
            sprintf('A=%d B=%d (%.1f%% 차이)', $lenA, $lenB, $diffPct),
            $a,
            $b
        );

        continue;
    }

    record($verdicts, $rows, 'OK', $path, sprintf('%dx%d', $dimA[0], $dimA[1]), $a, $b);
}

echo "\n판정\n", str_repeat('-', 96), "\n";

$order = ['OK', 'OK_ERR', 'BYTES', 'DIMENSION', 'CONTENT_TYPE', 'STATUS', 'BODY', 'ERROR'];
$total = count($urls);

foreach ($order as $v) {
    if (! isset($verdicts[$v])) {
        continue;
    }
    printf("  %-14s %6d  (%5.1f%%)  %s\n", $v, $verdicts[$v], $verdicts[$v] / $total * 100, verdictNote($v));
}

echo "\n헤더 회귀\n", str_repeat('-', 96), "\n";
printf("  Set-Cookie   A=%d건  B=%d건   %s\n", $cookieA, $cookieB, $cookieB === 0 ? '(B 에서 제거됨 — 의도된 변경)' : '(B 에 남아 있음 — image 미들웨어 그룹 확인 필요)');
printf("  ETag         B=%d건 / %d건\n", $etagB, $total);

$problems = array_values(array_filter($rows, static fn (array $r): bool => ! in_array($r[0], ['OK', 'OK_ERR'], true)));

if ($problems !== []) {
    echo "\n불일치 상위 20건\n", str_repeat('-', 96), "\n";
    foreach (array_slice($problems, 0, 20) as $r) {
        printf("  %-14s %-52s %s\n", $r[0], truncate($r[1], 52), $r[2]);
    }
}

if ($csvOut !== null) {
    $fh = fopen($csvOut, 'w');
    fputcsv($fh, ['verdict', 'url', 'detail', 'a_status', 'b_status', 'a_bytes', 'b_bytes', 'a_ms', 'b_ms'], ',', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($fh, $r, ',', '"', '\\');
    }
    fclose($fh);
    printf("\n전체 결과: %s (%d행)\n", $csvOut, count($rows));
}

exit($problems === [] ? 0 : 1);

/**
 * @param  array<string, int>  $verdicts
 * @param  list<list<mixed>>  $rows
 * @param  array{status: int, body: string, ms: float, headers: array<string, string>}|null  $a
 * @param  array{status: int, body: string, ms: float, headers: array<string, string>}|null  $b
 */
function record(array &$verdicts, array &$rows, string $verdict, string $url, string $detail, ?array $a, ?array $b): void
{
    $verdicts[$verdict] = ($verdicts[$verdict] ?? 0) + 1;
    $rows[] = [
        $verdict,
        $url,
        $detail,
        $a['status'] ?? '',
        $b['status'] ?? '',
        $a !== null ? strlen($a['body']) : '',
        $b !== null ? strlen($b['body']) : '',
        $a !== null ? round($a['ms'], 1) : '',
        $b !== null ? round($b['ms'], 1) : '',
    ];
}

function verdictNote(string $v): string
{
    return match ($v) {
        'OK' => '상태·크기·바이트 동등',
        'OK_ERR' => '양쪽 동일한 오류 응답 — 동등',
        'BYTES' => '픽셀 크기는 같으나 바이트 차이 (드라이버·원본 차이 가능)',
        'DIMENSION' => '결과 이미지 크기가 다르다 — 회귀 의심',
        'CONTENT_TYPE' => 'Content-Type 불일치 — 회귀 의심',
        'STATUS' => 'HTTP 상태 불일치 — 회귀 의심',
        'BODY' => '이미지로 파싱되지 않는 본문 — 회귀 의심',
        default => '요청 실패',
    };
}

/**
 * @return array{0: int, 1: int}|null
 */
function dimensions(string $body): ?array
{
    if ($body === '') {
        return null;
    }

    $info = @getimagesizefromstring($body);

    return $info === false ? null : [(int) $info[0], (int) $info[1]];
}

/**
 * 로그에서 뽑은 경로를 그대로 재사용한다. 이미 인코딩된 상태이므로 재인코딩하지 않는다.
 */
function normalizePath(string $path): string
{
    return str_starts_with($path, '/') ? $path : '/'.$path;
}

/**
 * @param  list<array{key: string, url: string, auth: string}>  $jobs
 * @return array<string, array{status: int, body: string, ms: float, headers: array<string, string>}>
 */
function runBatch(array $jobs, int $concurrency): array
{
    $mh = curl_multi_init();
    $queue = $jobs;
    $active = [];
    $headers = [];
    $results = [];
    $done = 0;
    $total = count($jobs);

    $addNext = static function () use (&$queue, &$active, &$headers, $mh): bool {
        if ($queue === []) {
            return false;
        }

        $job = array_shift($queue);
        $ch = curl_init();
        $id = spl_object_id($ch);
        $headers[$id] = [];

        curl_setopt_array($ch, [
            CURLOPT_URL => $job['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: image/webp,image/*,*/*'],
        ]);

        if ($job['auth'] !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $job['auth']);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($_, string $line) use (&$headers, $id): int {
            $len = strlen($line);
            $line = trim($line);

            if ($line !== '' && str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[$id][strtolower(trim($k))] = trim($v);
            }

            return $len;
        });

        curl_multi_add_handle($mh, $ch);
        $active[$id] = ['job' => $job, 'handle' => $ch];

        return true;
    };

    for ($i = 0; $i < $concurrency; $i++) {
        if (! $addNext()) {
            break;
        }
    }

    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.5);

        while (($info = curl_multi_info_read($mh)) !== false) {
            $ch = $info['handle'];
            $id = spl_object_id($ch);
            $job = $active[$id]['job'];

            $body = curl_multi_getcontent($ch);

            $results[$job['key']] = [
                'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'body' => is_string($body) ? $body : '',
                'ms' => curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000,
                'headers' => $headers[$id] ?? [],
            ];

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($active[$id], $headers[$id]);

            $done++;
            if ($done % 50 === 0 || $done === $total) {
                fprintf(STDERR, "\r  진행 %d/%d", $done, $total);
            }

            $addNext();
        }
    } while ($running > 0 || $active !== []);

    fwrite(STDERR, "\n");
    curl_multi_close($mh);

    return $results;
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

    return implode('  ', $parts);
}

function truncate(string $s, int $len): string
{
    return mb_strlen($s) <= $len ? $s : mb_substr($s, 0, $len - 1).'…';
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
