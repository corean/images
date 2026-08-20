# 테스트 커버리지 조사

## 조사 일자
2026-08-20 (브랜치: feature/laravel-13-upgrade)

## 현재 상태

- 테스트: 16건 전부 통과 (42 assertions, 0.38초)
- 커버리지 드라이버(Xdebug/PCOV) 미설치 — 수치 측정 불가, 수동 갭 분석으로 대체
- 앱 코드 전체: 6개 파일 (Controller 2, Service 1, Middleware 1, Provider 1, Model 1)

## 기존 테스트 목록

| 파일 | 검증 내용 |
|---|---|
| `tests/Unit/ImageServiceTest.php` | webp 인코딩, 다운스케일, 업스케일 방지, 버킷별 디스크 재사용, 미리보기 경로 결정성 |
| `tests/Unit/ImageServicePreviewTest.php` | 미리보기 히트 시 원본 미조회, 콜드 요청 S3 왕복 3회 고정, put 실패 시에도 이미지 반환 |
| `tests/Feature/ImageRouteMiddlewareTest.php` | image 그룹에 세션·쿠키 미들웨어 부재 (구성 + 실제 응답 양쪽 검증) |
| `tests/Feature/ExampleTest.php` | `/up` 헬스체크 200, `/` 404 |
| `tests/Unit/ExampleTest.php` | 스캐폴드 잔재 (`assertTrue(true)`) — 가치 없음 |

## 커버리지 갭 (심각도 순)

### 1. ImageController — HTTP 레벨 테스트 0건 (가장 큰 갭)

이 앱의 실제 진입점인데 컨트롤러를 거치는 테스트가 하나도 없다.

`show()` (app/Http/Controllers/ImageController.php:18):
- 원본 서빙 200 + `Cache-Control: public, max-age=31536000`
- Content-Type 이 `pathinfo($path, PATHINFO_EXTENSION)` 로 결정됨 — **확장자 없는 경로면 `image/` (빈 서브타입) 이 나가는 잠재 버그**. 테스트 없음
- 존재하지 않는 이미지 → `NotFoundHttpException` → 404 응답 매핑 미검증

`resize()` (app/Http/Controllers/ImageController.php:28):
- size 파싱: `400x400`, `400x400c`, `400x400!`, `%21` urldecode 케이스 미검증
- 검증 로직: 3000 초과, `0x0` 거부 — **`InvalidArgumentException` 을 그냥 throw 하므로 클라이언트에 500 이 나감** (422/400 이 적절). 테스트가 없어서 이 동작이 의도인지 버그인지 판단 근거도 없음
- ETag 생성, `If-None-Match` → 304 응답 미검증
- `Vary: Accept` 헤더 미검증
- `maintainAspectRatio` 옵션은 계산만 하고 ImageService 가 사용하지 않음 (dead option)

### 2. 라우트 매칭 미검증

`routes/image.php`:
- `/{bucket}/{size}/{path}` vs `/{bucket}/{path}` 우선순위 — size 패턴에 안 맞는 2번째 세그먼트가 show 의 path 로 흡수되는 동작
- path 의 `.*` (슬래시 포함 다단계 경로) 매칭
- size 의 `%21` 인코딩 라우트 제약 통과

### 3. ImageService 미커버 경로

- `getStorageDisk()` 예외 → `NotFoundHttpException` 변환 (app/Services/ImageService.php:49) 미검증
- `processImage()` — 기존 테스트 전부 `crop=true`. **`crop=false` (resize + aspectRatio 경로) 테스트 0건**
- 한쪽 차원이 0일 때의 비율 계산 (width=0 또는 height=0) 미검증
- 손상된 이미지 데이터 → `RuntimeException` 미검증
- `getProcessedImage()` 에 `options=null` 전달 시 원본 그대로 반환 미검증

### 4. ImageRateLimit — 테스트 0건 + 미등록 (dead code)

`app/Http/Middleware/ImageRateLimit.php` 는 어디에도 등록되어 있지 않다
(bootstrap/app.php:25 의 image 그룹은 빈 배열). 테스트를 만들기 전에
**유지(등록 예정) / 삭제** 방침 결정이 먼저 필요하다.

### 5. 기타

- `tests/Unit/ExampleTest.php` — 스캐폴드 잔재, 정리 대상
- `app/Models/User.php` — 이 앱에서 미사용으로 보임 (인증 없음). 테스트 대상이라기보다 삭제 검토 대상

## 테스트 작성 시 제약

- **`Storage::build()` 는 `Storage::fake()` 로 대체되지 않는다.** ImageService 가 디스크를 직접 build 하므로, Feature 테스트에서는 (a) 컨테이너에 ImageService mock 바인딩, 또는 (b) 기존 PreviewTest 방식처럼 리플렉션으로 `disks` 프로퍼티에 mock 주입 중 택일
- 기존 Unit 테스트가 GD 드라이버 강제(`config('app.image_driver', 'gd')`) + GD 픽스처 생성 패턴을 확립해 둠 — 그대로 따르면 됨
- 프레임워크: PHPUnit 12 (Pest 아님). `#[DataProvider]` 어트리뷰트 패턴 사용 중

## 커버리지 수치 측정 방법 (선택)

PCOV 설치 시 수치 측정 가능:

```bash
herd install pcov   # 또는 pecl install pcov
php artisan test --coverage
```

## 권장 우선순위

1. **ImageController Feature 테스트** (show/resize 정상 경로, 404, ETag/304, size 검증 실패 응답) — 실사용 진입점이라 효과 최대
2. **라우트 매칭 테스트** (size 패턴, path 슬래시, %21)
3. **ImageService 나머지 경로** (crop=false, 한쪽 0, null options, 404 변환, 손상 데이터)
4. **ImageRateLimit 방침 결정 후** 테스트 또는 삭제
5. 스캐폴드 잔재 정리 (Unit/ExampleTest.php)
