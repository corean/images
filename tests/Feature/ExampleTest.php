<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * 이 앱은 이미지 서빙 전용이라 루트 라우트가 없다.
     * 애플리케이션 기동 확인은 헬스체크 엔드포인트로 검증한다.
     */
    public function test_the_health_check_returns_a_successful_response(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }

    public function test_the_root_path_returns_not_found(): void
    {
        $response = $this->get('/');

        $response->assertStatus(404);
    }
}
