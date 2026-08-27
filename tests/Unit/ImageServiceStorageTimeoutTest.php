<?php

namespace Tests\Unit;

use App\Services\ImageService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * S3 디스크 구성에 타임아웃·재시도가 실제로 실리는지 고정한다.
 *
 * 타임아웃이 없으면 스토리지 지연 하나가 요청을 무한정 붙잡는다. 실제로
 * 검증 서버에서 연결 실패가 13초·20초씩 이어진 사례가 있었다 — AWS SDK
 * 기본 재시도(3회)가 타임아웃 없이 누적된 결과였다.
 */
class ImageServiceStorageTimeoutTest extends TestCase
{
    private function minioDiskConfig(string $bucket = 'orderhow'): array
    {
        $service = new ImageService;

        $method = new ReflectionMethod($service, 'minioDiskConfig');
        $method->setAccessible(true);

        return $method->invoke($service, $bucket);
    }

    public function test_it_sets_a_connect_and_total_timeout_by_default(): void
    {
        $config = $this->minioDiskConfig();

        $this->assertSame(2.0, $config['http']['connect_timeout']);
        $this->assertSame(10.0, $config['http']['timeout']);
    }

    public function test_it_limits_retries_by_default(): void
    {
        $config = $this->minioDiskConfig();

        $this->assertSame(1, $config['retries']);
    }

    public function test_the_timeout_and_retry_values_follow_config_overrides(): void
    {
        config()->set('filesystems.disks.minio.http', ['connect_timeout' => 5.0, 'timeout' => 30.0]);
        config()->set('filesystems.disks.minio.retries', 3);

        $config = $this->minioDiskConfig();

        $this->assertSame(5.0, $config['http']['connect_timeout']);
        $this->assertSame(30.0, $config['http']['timeout']);
        $this->assertSame(3, $config['retries']);
    }

    /**
     * 버킷은 URL 세그먼트에서 오지 폴백 설정에서 오지 않는다. 타임아웃 설정을
     * 추가하며 이 동작을 실수로 바꾸지 않았는지 함께 고정한다.
     */
    public function test_the_bucket_argument_overrides_the_fallback_config(): void
    {
        config()->set('filesystems.disks.minio.bucket', 'fallback-bucket');

        $config = $this->minioDiskConfig('from-url');

        $this->assertSame('from-url', $config['bucket']);
    }
}
