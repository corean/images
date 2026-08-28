# 테스트 커버리지 보강 계획

기준 문서: [research.md](research.md)

## 방침 결정 사항

- **ImageRateLimit 삭제 확정** — 2025-02-04 추가 직후 다음 날 비활성화, 이후 미사용. 필요 시 내장 `throttle` 미들웨어로 대체 가능
- 컨트롤러 잠재 버그 2건(500 응답, 빈 Content-Type)은 **이번 작업에서 수정하지 않고 현행 동작을 테스트로 고정**한다. 동작 변경은 별도 작업으로 분리 (커버리지 작업과 행위 변경을 섞지 않음)
  > 주석: 수정이 필요하면 여기에 의견 남겨주세요

## 커밋 계획 (기능별 분리)

### 커밋 1 — `삭제(Middleware): 미사용 ImageRateLimit 제거`

| 파일 | 변경 |
|---|---|
| `app/Http/Middleware/ImageRateLimit.php` | 삭제 |
| `bootstrap/app.php` | 25행 주석에서 ImageRateLimit 언급 제거, `throttle` 미들웨어 안내로 교체 |

```php
/**
 * 이미지 서빙 전용 그룹. 세션·CSRF 쿠키를 붙이지 않는다.
 * ...
 * 레이트 리밋이 필요하면 내장 throttle 미들웨어를 여기에 넣는다.
 */
$middleware->group('image', []);
```

### 커밋 2 — `삭제(Test): 스캐폴드 잔재 Unit/ExampleTest 제거`

| 파일 | 변경 |
|---|---|
| `tests/Unit/ExampleTest.php` | 삭제 (`assertTrue(true)` 만 있는 스캐폴드 잔재) |

※ 테스트 파일 삭제는 승인 필요 항목 — 이 계획의 확정이 승인을 겸한다.

### 커밋 3 — `추가(Test): ImageController HTTP 응답 테스트`

신규 파일: `tests/Feature/ImageControllerTest.php`

ImageService 는 `Storage::build()` 를 직접 쓰므로 `Storage::fake()` 로 대체 불가.
**컨테이너에 ImageService mock 바인딩** 방식을 쓴다.

```php
$this->mock(ImageService::class, function (MockInterface $mock): void {
    $mock->shouldReceive('getStorageDisk')->andReturn($bytes);
});
```

검증 항목 (show):
- [x] 200 + `Cache-Control: public, max-age=31536000`
- [x] Content-Type 이 확장자 기반 결정 (`.jpg` → `image/jpg`) — 현행 고정
- [x] 확장자 없는 경로 → `image/` 빈 서브타입 — 현행 고정 (버그 후보 문서화)
- [x] 서비스가 NotFoundHttpException throw → 404 응답

검증 항목 (resize):
- [x] `400x300` → 200 + `Content-Type: image/webp` + `Vary: Accept` + Cache-Control
- [x] `400x300c`, `400x300!`, `%21` → forceCrop 옵션이 서비스에 전달됨 (mock 인자 검증)
- [x] `3001x100` / `100x3001` → InvalidArgumentException (현행: 500) — 현행 고정
- [x] `0x0` → InvalidArgumentException (현행: 500) — 현행 고정
- [x] ETag 일치 `If-None-Match` → 304, 본문 없음
- [x] ETag 불일치 → 200 + ETag 헤더 존재

### 커밋 4 — `추가(Test): 이미지 라우트 매칭 테스트`

신규 파일: `tests/Feature/ImageRouteMatchingTest.php` (mock 바인딩은 커밋 3 과 동일 패턴)

- [x] `/{bucket}/{size}/{path}` — size 패턴(`400x300`, `400x300c`, `400x300!`, `400x300%21`)이 resize 로 매칭
- [x] size 패턴 불일치 2번째 세그먼트(`/bucket/foo/bar.jpg`)는 show 의 path 로 흡수
- [x] 슬래시 포함 다단계 path (`a/b/c.jpg`) 매칭
- [x] 1세그먼트 경로(`/bucket-only`)는 404

### 커밋 5 — `추가(Test): ImageService 미커버 경로 테스트`

기존 `tests/Unit/ImageServiceTest.php` 에 추가 (GD 강제 + 픽스처 패턴 유지):

- [x] `crop=false` 리사이즈 — 비정방형 원본(600x300)에 200x200 요청
      (계획 시점에는 aspectRatio 유지를 기대했으나 **실제로는 늘어남**. 아래 구현 결과 참조)
- [x] `width=0, height>0` — 비율 계산으로 width 자동 산출
- [x] `height=0, width>0` — 비율 계산으로 height 자동 산출
- [x] 손상된 이미지 데이터 → `RuntimeException`
- [x] `getProcessedImage(options: null)` → 원본 바이트 그대로 반환 (리플렉션으로 disk mock 주입, 기존 PreviewTest 패턴)
- [x] `getStorageDisk()` 디스크 예외 → `NotFoundHttpException` 변환

### 커밋 6 — `추가(Test): robots.txt 크롤링 차단 고정`

f7ec395 (검색엔진 크롤링 전면 차단) 이 테스트 없이 커밋됨. public/ 정적 파일은
Laravel 라우터를 타지 않아 `$this->get('/robots.txt')` 로는 검증 불가 —
**파일 내용 직접 검증** 방식을 쓴다.

신규 파일: `tests/Feature/RobotsTest.php`

- [x] `public/robots.txt` 존재
- [x] `Disallow: /` 포함 (빈 `Disallow:` 로의 회귀 방지 — 스캐폴드 재생성 시 전체 허용으로 되돌아가는 사고 방지)

## 계획에서 제외한 것

- 컨트롤러 동작 변경 (500 → 400, Content-Type 수정) — 별도 작업
- `app/Models/User.php` 삭제 검토 — 별도 작업
- PCOV 설치 및 수치 측정 — 선택 사항, 이번 범위 아님
- `maintainAspectRatio` dead option 정리 — 동작 변경에 해당, 별도 작업

## 예상 영향 범위

- 프로덕션 코드 변경: ImageRateLimit 삭제 + bootstrap 주석 1줄 (동작 영향 없음 — 미등록 클래스)
- 나머지는 전부 테스트 추가
- 완료 후 전체 스위트 재실행으로 회귀 확인

## 구현 결과

커밋 6개 완료 (5eb8f71 ~ 50c6f0a). 테스트 **15건 → 56건** (41 → 140 assertions), 전부 통과.

### 계획과 달라진 점

| 항목 | 계획 | 실제 |
|---|---|---|
| Cache-Control 단언 | `public, max-age=31536000` | Symfony 가 디렉티브를 알파벳순 정규화 → `max-age=31536000, public` 로 고정 |
| 응답 본문 단언 | `streamedContent()` | 일반 Response 이므로 `assertContent()` |
| `crop=false` 결과 | aspectRatio 유지 기대 | **비율 무시하고 늘어남** (아래 신규 결함) |
| 커밋 5 파일 배치 | 전부 `ImageServiceTest` | 디스크 mock 이 필요한 2건은 `ImageServicePreviewTest` 로 (기존 `serviceWithDisk` 헬퍼 재사용, 리플렉션 중복 방지) |
| 커밋 4 구현 방식 | 컨트롤러 mock 바인딩 | 라우터에 직접 질의(`Route::getRoutes()->match()`) — 서비스 mock 불필요 |

### 조사 중 추가로 드러난 결함 (전부 현행 고정, 수정은 별도 작업)

1. **`crop=false` 가 이미지를 늘린다** (신규 발견, `app/Services/ImageService.php:147`)
   코드가 `resize()` 에 aspectRatio·upsize 제약 클로저를 넘기지만 그건 Intervention **v2** API 다.
   설치된 **v3.11.8** 의 `resize(?int, ?int)` 는 3번째 인자를 받지 않고, PHP 는 사용자 정의
   메서드의 초과 인자를 조용히 버린다. 결과적으로 클로저는 죽은 코드이고 리사이즈는 비율을
   무시한다. 2:1 원본에 200x200 요청 → 늘어난 200x200.
   → 의도한 동작이라면 v3 의 `scaleDown()` 또는 `contain()` 으로 교체해야 한다.
2. `jpg` 경로가 `image/jpeg` 대신 `image/jpg` 를 내보낸다 (research.md 기재)
3. 확장자 없는 경로가 서브타입이 빈 `image/` 를 내보낸다 (research.md 기재)
4. 사이즈 검증 실패가 `InvalidArgumentException` 을 그대로 던져 **500** 이 나간다 (research.md 기재)

### 후속 작업 후보

- 위 결함 1~4 수정 (동작 변경이므로 별도 브랜치)
- `maintainAspectRatio` dead option 정리 — 결함 1 수정과 함께 처리하면 자연스럽다
- `app/Models/User.php` 삭제 검토 (인증 없는 앱)
- PCOV 설치 후 수치 측정
