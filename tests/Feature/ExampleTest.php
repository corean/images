<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * 애플리케이션 기동 확인은 헬스체크 엔드포인트로 검증한다.
     */
    public function test_the_health_check_returns_a_successful_response(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }

    /**
     * 루트는 어느 인스턴스가 응답하는지 식별할 수 있도록 APP_URL 을 돌려준다.
     */
    public function test_the_root_path_returns_the_app_url(): void
    {
        config(['app.url' => 'https://images.example.test']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSeeText('https://images.example.test');
    }
}
