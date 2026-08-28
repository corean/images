<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;

/**
 * 스토리지 접근 실패 원인 진단 — 서버에서 단독 실행한다.
 *
 * ImageService::getStorageDisk() 가 모든 예외를 NotFoundHttpException(404) 으로
 * 변환하므로 HTTP 응답만으로는 원인을 알 수 없다. 이 스크립트는 같은 설정으로
 * 디스크를 만들어 원래 예외와 AWS 오류 코드를 그대로 노출한다.
 *
 * 사용법 — 사이트 루트(artisan 이 있는 디렉터리)에서:
 *   php diag-storage.php orderhow cropped-images/44783-UvIbIZqg.jpg
 *
 * 부팅 중 발생하는 E_DEPRECATED / E_WARNING 도 함께 집계한다.
 * FrankenPHP worker 모드에서는 요청 시작 시의 warning 이 워커를 종료시킬 수 있다.
 */
$standalone = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;

$startupDiagnostics = [];

if ($standalone) {
    $base = getcwd();

    if (! is_file($base.'/vendor/autoload.php') || ! is_file($base.'/bootstrap/app.php')) {
        fwrite(STDERR, "사이트 루트(artisan 이 있는 디렉터리)에서 실행할 것.\n");
        fwrite(STDERR, "현재 위치: {$base}\n");
        exit(1);
    }

    ini_set('display_errors', '0');

    set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0) use (&$startupDiagnostics): bool {
        $label = match (true) {
            (bool) ($no & (E_DEPRECATED | E_USER_DEPRECATED)) => 'DEPRECATED',
            (bool) ($no & (E_WARNING | E_USER_WARNING)) => 'WARNING',
            (bool) ($no & (E_NOTICE | E_USER_NOTICE)) => 'NOTICE',
            default => 'ERR('.$no.')',
        };

        $startupDiagnostics[] = sprintf('%-10s %s:%d — %s', $label, basename($file), $line, $msg);

        return true;
    }, E_ALL);

    require $base.'/vendor/autoload.php';

    /** @var Application $app */
    $app = require $base.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    restore_error_handler();

    $bucket = $argv[1] ?? 'orderhow';
    $key = $argv[2] ?? 'cropped-images/44783-UvIbIZqg.jpg';
} else {
    $bucket = $bucket ?? 'orderhow';
    $key = $key ?? 'cropped-images/44783-UvIbIZqg.jpg';
}

$line = static fn (string $t = '') => print $t."\n";
$ms = static fn (float $start): string => sprintf('%.1fms', (microtime(true) - $start) * 1000);

$line('==== 환경 ====');
$line('  PHP              : '.PHP_VERSION.'   memory_limit='.ini_get('memory_limit'));
$line('  APP_ENV          : '.config('app.env').'   debug='.var_export(config('app.debug'), true));
$line('  image_driver     : '.config('app.image_driver').'   vips='.(extension_loaded('vips') ? 'O' : 'X').' gd='.(extension_loaded('gd') ? 'O' : 'X'));
$line('  octane.server    : '.config('octane.server'));
$line('  DB_CONNECTION    : '.config('database.default'));

if ($startupDiagnostics !== []) {
    $line();
    $line('==== 부팅 중 발생한 진단 메시지 '.count($startupDiagnostics).'건 ====');
    $line('  FrankenPHP worker 모드에서 요청 시작 시의 warning 은 워커를 종료시킬 수 있다.');
    $line('  Octane 전환 전에 제거해야 한다.');
    foreach (array_unique($startupDiagnostics) as $d) {
        $line('  '.$d);
    }
}

$minio = config('filesystems.disks.minio');
$line();
$line('==== minio 디스크 설정 ====');
$line('  endpoint         : '.($minio['endpoint'] ?: '(비어 있음!)'));
$line('  url              : '.($minio['url'] ?: '(없음)'));
$line('  region           : '.($minio['region'] ?: '(없음)'));
$line('  bucket(폴백)     : '.($minio['bucket'] ?: '(없음)'));
$line('  path_style       : '.var_export($minio['use_path_style_endpoint'] ?? null, true));
$line('  throw            : '.var_export($minio['throw'] ?? null, true));
$line('  root             : '.var_export($minio['root'] ?? null, true).'   ← 비어 있지 않으면 모든 키에 접두어가 붙어 전부 404 가 된다');
$line('  key              : '.(empty($minio['key']) ? '(비어 있음!)' : substr((string) $minio['key'], 0, 4).'… '.strlen((string) $minio['key']).'자'));
$line('  secret           : '.(empty($minio['secret']) ? '(비어 있음!)' : '설정됨 '.strlen((string) $minio['secret']).'자'));

$endpoint = (string) ($minio['endpoint'] ?? '');

if ($endpoint !== '') {
    $parts = parse_url($endpoint);
    $host = $parts['host'] ?? '';
    $scheme = $parts['scheme'] ?? 'https';
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

    $line();
    $line('==== 엔드포인트 도달성 ====');

    $start = microtime(true);
    $ip = gethostbyname($host);
    $line('  DNS              : '.$host.' → '.($ip === $host ? '해석 실패' : $ip).'   ('.$ms($start).')');

    $start = microtime(true);
    $target = $scheme === 'https' ? "ssl://{$host}" : $host;
    $sock = @fsockopen($target, (int) $port, $errno, $errstr, 5);

    if ($sock) {
        $line('  TCP/TLS          : 연결 성공 '.$host.':'.$port.'   ('.$ms($start).')');
        fclose($sock);
    } else {
        $line('  TCP/TLS          : 실패 ('.$errno.') '.$errstr.'   ('.$ms($start).')');
        $line('                     ← 여기서 실패하면 컨테이너의 네트워크 경로 문제다. 앱 설정이 아니다.');
    }
}

$line();
$line('==== 디스크 생성 (ImageService 와 동일 설정) ====');

$config = [
    'driver' => 's3',
    'key' => $minio['key'] ?? null,
    'secret' => $minio['secret'] ?? null,
    'region' => $minio['region'] ?? null,
    'bucket' => $bucket,
    'url' => $minio['url'] ?? null,
    'endpoint' => $minio['endpoint'] ?? null,
    'use_path_style_endpoint' => $minio['use_path_style_endpoint'] ?? false,
    'throw' => $minio['throw'] ?? false,
    'root' => $minio['root'] ?? '',
];

try {
    $start = microtime(true);
    $disk = Storage::build($config);
    $line('  생성 성공        : '.$ms($start));
} catch (Throwable $e) {
    $line('  생성 실패        : '.get_class($e).' / '.$e->getMessage());
    exit(1);
}

/** ImageService::generatePreviewPath() 재구현. */
$previewPath = static function (string $path, int $w, int $h, bool $crop = false): string {
    $hash = md5($path);

    return 'previews/'."{$w}x{$h}".($crop ? '!' : '').'/'.substr($hash, 0, 2).'/'.$hash.'.webp';
};

$targets = [
    '원본' => $key,
    'preview 0x240' => $previewPath($key, 0, 240),
    'preview 240x240' => $previewPath($key, 240, 240),
];

$line();
$line('==== 키별 접근 결과 (버킷: '.$bucket.') ====');

foreach ($targets as $label => $target) {
    $line();
    $line('  ── '.$label.'  →  '.$target);

    $start = microtime(true);
    try {
        $line('     exists()      : '.var_export($disk->exists($target), true).'   ('.$ms($start).')');
    } catch (Throwable $e) {
        $line('     exists() 예외 : '.get_class($e).' / '.$e->getMessage().'   ('.$ms($start).')');
    }

    $start = microtime(true);
    try {
        $data = $disk->get($target);
        $line('     get()         : '.($data === null ? 'null 반환 (throw=false)' : number_format(strlen($data)).' bytes').'   ('.$ms($start).')');
    } catch (Throwable $e) {
        $line('     get() 예외    : '.$ms($start));
        $depth = 0;
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            $line('       ['.$depth++.'] '.get_class($cur).': '.trim($cur->getMessage()));
            if (method_exists($cur, 'getAwsErrorCode')) {
                $line('            AWS 오류코드 : '.var_export($cur->getAwsErrorCode(), true));
                $line('            HTTP 상태    : '.var_export($cur->getStatusCode(), true));
            }
        }
        $line('       ↑ NoSuchKey=오브젝트없음 / AccessDenied=권한 / NoSuchBucket=버킷없음');
        $line('         cURL·connect 관련=네트워크. 이 구분이 404 뒤에 가려져 있던 정보다.');
    }
}

$line();
$line('==== S3Client 직접 호출 (가장 원시적인 오류) ====');

try {
    $client = method_exists($disk, 'getClient') ? $disk->getClient() : null;

    if ($client === null) {
        $line('  getClient() 미지원 — 위 예외 체인으로 판단할 것');
    } else {
        $start = microtime(true);
        try {
            $res = $client->getObject(['Bucket' => $bucket, 'Key' => $key]);
            $line('  getObject 성공   : '.number_format((int) $res['ContentLength']).' bytes   ('.$ms($start).')');
            $line('  Content-Type     : '.($res['ContentType'] ?? '(없음)'));
        } catch (Throwable $e) {
            $line('  getObject 실패   : '.get_class($e).'   ('.$ms($start).')');
            if (method_exists($e, 'getAwsErrorCode')) {
                $line('    AWS 오류코드   : '.var_export($e->getAwsErrorCode(), true));
                $line('    HTTP 상태      : '.var_export($e->getStatusCode(), true));
            }
            $line('    메시지         : '.trim($e->getMessage()));
        }
    }
} catch (Throwable $e) {
    $line('  클라이언트 접근 실패: '.get_class($e).' / '.$e->getMessage());
}

$line();
$line('==== 쓰기 권한 확인 (preview 저장이 가능한가) ====');
$probe = 'previews/.diag-probe-'.substr(md5($key), 0, 8);

try {
    $start = microtime(true);
    $disk->put($probe, 'diag');
    $line('  put()            : 성공   ('.$ms($start).')');
    $disk->delete($probe);
    $line('  delete()         : 성공');
} catch (Throwable $e) {
    $line('  put() 실패       : '.get_class($e).' / '.trim($e->getMessage()));
    if (method_exists($e, 'getAwsErrorCode')) {
        $line('    AWS 오류코드   : '.var_export($e->getAwsErrorCode(), true));
    }
    $line('  ← 쓰기가 막히면 preview 가 매번 재생성되어 항상 콜드 경로를 탄다.');
}

$line();
