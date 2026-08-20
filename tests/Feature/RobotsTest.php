<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 크롤링 전면 차단을 고정한다.
 *
 * f7ec395 에서 차단으로 바꿨지만 테스트가 없었다. public/robots.txt 는 웹서버가
 * 직접 서빙해 Laravel 라우터를 타지 않으므로 HTTP 요청으로는 검증할 수 없고,
 * 파일 내용을 직접 읽는다.
 *
 * 이 파일은 Laravel 스켈레톤에도 있어서, 업그레이드나 재생성 과정에서 전체
 * 허용(빈 Disallow) 으로 조용히 되돌아갈 수 있다. 그 회귀를 막는 것이 목적이다.
 */
class RobotsTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function directives(): array
    {
        $path = public_path('robots.txt');

        $this->assertFileExists($path);

        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];

        return array_values(array_filter(
            array_map('trim', $lines),
            fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));
    }

    public function test_it_blocks_every_crawler_from_the_whole_site(): void
    {
        $directives = $this->directives();

        $this->assertContains('User-agent: *', $directives);
        $this->assertContains('Disallow: /', $directives);
    }

    /**
     * 값이 빈 Disallow 는 로봇 배제 표준에서 "전체 허용" 을 뜻한다.
     * 스켈레톤 기본값이 정확히 그것이라 명시적으로 막아 둔다.
     */
    public function test_it_carries_no_empty_disallow_directive(): void
    {
        $this->assertNotContains('Disallow:', $this->directives());
    }
}
