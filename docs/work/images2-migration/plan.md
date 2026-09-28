# images2 전환 계획

- 작성일: 2026-08-27
- 대상 브랜치: `dev` (origin/main 대비 10커밋, 99파일, +11,118/-1,755)
- 목표: 누적 개발 이력을 운영에 한 번에 올리는 위험을 제거하고, 검증된 상태로 컷오버 + 성능 개선

## 변경 이력

- 2026-08-27 초안: Phase 3 에서 Octane(FrankenPHP) 전환을 계획에 포함
- 2026-08-28 완료: **컷오버 완료.** `dev -> main` PR #1 머지·배포, 프록시 우회를 운영에
  적용했다. 계획했던 별도 서버(images2) 전환은 불필요해졌다 — ct117 한 대가 운영·dev 를
  모두 호스팅하고 NPM proxy host 33 하나가 두 도메인을 처리하는 구조라, 서버 이전 없이
  코드 배포와 프록시 설정만으로 목표를 달성했다. 최종 상태는 아래 "적용 결과" 참조
- 2026-08-27 개정: **Octane 을 보류로 변경.** 서버 실측에서 프레임워크 부트스트랩 비용이
  약 1.5ms 로 확인되어 개선 여지가 없다고 판단(근거는 아래 "조사로 확인된 사실" 5번).
  Phase 4 우선순위를 실측 기반으로 재작성하고, 안정성 수정을 Phase 1.5 로 신설.

## 적용 결과 (2026-08-28)

운영 기준 개선 전후. 전은 같은 날 변경 착수 직전 실측값이다.

| 항목 | 전 | 후 |
|---|---|---|
| 미리보기 히트 | 35.5ms | **6.8ms** |
| 원본 324KB | 42.5ms | **8.2ms** |
| 원본 659KB | 50.0ms | **8.1ms** |
| 리사이즈 미스 | 356~567ms | **198~294ms** |
| 브라우저 캐시 수명 | 7일 | **1년** |
| `Set-Cookie` | 2건(약 900B) | **0건** |
| 원본 `Content-Type` | `image/jpg` | **`image/jpeg`** |
| 원본 `ETag`·`Last-Modified` | 없음 | **있음** |

dev 와 운영이 동등해졌다(히트 6.8 / 6.2ms, 원본 8.2 / 8.3ms).

**히트 경로에서 PHP 가 완전히 빠졌다.** PHP 가 처리하는 것은 리사이즈 미스와
폴백(익명읽기가 막힌 버킷, 미생성 미리보기)뿐이다.

적용한 것: 레버 2(NPM Cache Assets 해제), 레버 3(미리보기·원본 우회),
레버 5(vips), 레버 6(ETag 경로 기반) + dev 코드 배포(레버 1).
보류: 레버 4(프록시 디스크 캐시, 잔여 2~3ms), 레버 7(`opcache.preload`, 미스의 2~3%).

미해결: 사실 14번(NPM open file 한도 1024). 성능이 아니라 부하 시 연결 실패 문제다.

## 확정 전제

| 항목 | 값 | 출처 |
|---|---|---|
| 운영 런타임 | PHP-FPM (Octane 미사용) | 사용자 확인 |
| CDN | 없음. 앞단에 openresty 리버스 프록시 | 사용자 확인 + 응답 헤더 |
| 스토리지 | MinIO, 로컬 NAS | 사용자 확인 |
| 테스트 스토리지 | 별도 버킷 → 컷오버 시 운영 스토리지로 교체 | 사용자 확인 |
| images2 | 별도 서버(Proxmox ct110), **standard 배포**로 재생성됨 | 서버 확인 |
| 서버 PHP | 8.5 (로컬은 8.4.23) | 서버 출력 |

## 조사로 확인된 사실

### 1. 운영 콜드 패스에 bcrypt 202ms 낭비 (dev 에서 이미 제거됨)

- `origin/main:app/Services/ImageCacheService.php` 의 `getCacheKey()` 가 `Hash::make()`(bcrypt) 사용
- `BCRYPT_ROUNDS=12` 기준 실측 **202.1ms**
- bcrypt 는 호출마다 랜덤 솔트를 쓰므로 이 키는 재현 불가 → 쓰기만 하고 읽히지 않는 죽은 캐시
- 호출 위치는 preview 미스 경로 → **preview 생성 요청마다 202ms 순수 낭비**
- 추가로 `exists()` + `makeDirectory()` 로 MinIO 왕복 2회
- **실측 대조**: 운영에서 preview 미스 요청(240x240) 실제 응답시간 **520ms**. 이 중 202ms 가 bcrypt 다
- `dev` 는 `ImageCacheService` 를 삭제하고 위 호출을 모두 제거함

### 2. 외부 계약 변화 없음

- preview 경로 생성 로직이 main/dev 동일: `previews/{w}x{h}[!]/{md5앞2}/{md5}.webp`
  → 컷오버 시 운영 스토리지로 전환해도 **기존 preview 전부 히트**. 대량 재생성 없음
- 라우트 패턴 동일: `size` = `^(\d+x\d+)(c|!|%21)?$`, `path` = `.*`
- `app/Http/Controllers/ImageController.php` 변경 없음
- 라우트 매칭 정상 동작 확인: `3001x3001` / `0x0` 이 양쪽에서 500(`resize()` 내부
  `InvalidArgumentException`)을 반환 → 컨트롤러까지 진입한다

### 3. 의도된 동작 변화

- 이미지 라우트가 `web` 그룹 밖 `image` 빈 그룹으로 이동 → 응답에서 `Set-Cookie` 사라짐
  (운영은 `XSRF-TOKEN`·`laravel_session` 부착 중, 응답당 약 900B)
- `robots.txt` 전면 차단
- Laravel 11.31 → 13.26
- 로컬 테스트 56 passed / 140 assertions

### 4. 모든 예외가 404 로 마스킹된다 (안정성 최대 결함)

`app/Services/ImageService.php` 의 `getStorageDisk()`:

```php
try {
    return $this->getMinioStorage($bucket)->get($path);
} catch (\Exception $e) {
    throw new NotFoundHttpException("Failed to retrieve image: {$e->getMessage()}");
}
```

- `AccessDenied`, `NoSuchBucket`, 연결 거부, 타임아웃, MinIO 5xx 가 **전부 404 "이미지 없음"**
- 원래 예외를 `previous` 로 넘기지 않아 체인이 끊긴다
- `NotFoundHttpException` 은 기본 리포트 대상이 아니다 → **`laravel.log` 에도 흔적이 없다**
- 2026-08-27 실제 사례 2건이 모두 이것 때문에 원인 불명으로 남았다
  - 운영: 약 30분간 모든 리사이즈 404 (원본은 정상 200). 자연 해소, 원인 미확인
  - images2: 스토리지 읽기가 **13초 후 404** (연결 타임아웃 + AWS SDK 재시도 3회 패턴)

### 5. 프레임워크 부트스트랩 비용은 약 1.5ms (Octane 보류 근거)

images2 서버에서 루프백으로 직접 측정(각 10회, 전부 HTTP 200):

| 요청 | 중앙값 | 내용 |
|---|---|---|
| `/robots.txt` | 0.19ms | nginx 정적 = 네트워크 제거된 하한 |
| `/up` | 1.74ms (워밍 후 1.26ms) | Laravel 전체 부트스트랩 + 라우팅 |

- 부트스트랩 비용 ≈ **1.5ms**
- 같은 앱의 콜드 패스(preview 생성)는 **520ms** — 비교 대상이 되지 않는다
- 이 앱은 라우트 2개, `image` 그룹 미들웨어 0개, DB·모델·세션 접근 없음. Octane 이 제거할 대상이 애초에 없다

### 8. vips 전환 시 `ext-ffi` 설정이 필수 선행 조건이다

`intervention/image-driver-vips` 는 PECL 확장이 아니라 `jcupitt/vips`(PHP 코어 내장
`ext-ffi` 로 libvips 공유 라이브러리를 직접 호출하는 바인딩)에 의존한다.

- 시스템 요구사항은 `libvips.so.42` 하나뿐(`-dev` 헤더 불필요, ct117 에는 이미
  `libvips42t64` 8.15.1 로 설치되어 있었음)
- **`ffi.enable` 기본값 `preload` 가 일반 요청에서 FFI 호출을 차단한다.** CLI/FPM
  양쪽 `php.ini` 에서 `true` 로 바꾸지 않으면 `processImage()` 가
  "libvips does not seem to be installed correctly" 라는 오해하기 쉬운 메시지로
  실패한다(실제 원인은 설정이지 설치가 아니다) — RuntimeException 500 으로
  올바르게 로그에 남았고 프로세스는 죽지 않았다(Octane worker 모드였다면
  워커가 종료됐을 수 있다 — Octane 보류 판단을 재확인해주는 사례)
- `ffi.enable=true` 는 opcache preload 없이는 매 요청 FFI 파싱 오버헤드가 붙는다.
  컷오버 시점에 `opcache.preload` 로 전환 검토 (Phase 4-7 과 함께)
- PHP 버전 단위로 적용되며 같은 컨테이너의 다른 사이트에 영향을 준다. 검증에
  필요한 버전에만 적용할 것 — ct117 은 운영·dev 모두 PHP 8.4 라 그 버전만 필요했다

### 6. openresty 가 캐시 수명을 1년 → 7일로 축소한다

```
앱 코드   : public, max-age=31536000   (ImageController.php:24, 73)
실제 응답 : cache-control: max-age=604800
            cache-control: public, max-age=604800   (중복 출력)
            Vary: Accept 는 소실
```

- 오리진 히트 수에 직접 영향. 코드 변경 없이 개선 가능한 가장 싼 항목
- 부작용: 브라우저가 7일간 캐시하므로 **오리진 장애가 사용자에게 가려진다.**
  2026-08-27 운영 404 장애를 사용자가 "정상 접속"으로 인식한 원인이다

### 7. `config/database.php` deprecation (PHP 8.5)

- `config/database.php:61`·`:81` 의 `PDO::MYSQL_ATTR_SSL_CA` 가 PHP 8.5 에서 deprecated
- 부팅마다 2건 발생. `DB_CONNECTION=sqlite` 이고 `MYSQL_ATTR_SSL_CA` 를 설정하지 않으므로
  `array_filter` 결과는 항상 빈 배열 → **상수 참조 자체가 낭비**
- 수정: `mysql`·`mariadb` 의 `'options' => []`, 또는
  `defined('Pdo\Mysql::ATTR_SSL_CA') ? Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA`
- CLI 에서 화면 출력됨 = `display_errors=On`. **웹 SAPI 도 On 이면 바이너리 응답이 깨진다** → 확인 필요

### 9. MinIO 를 사설 IP 직결로 바꿔도 이 워크로드에서는 측정 가능한 이득이 없다

`MINIO_ENDPOINT` 를 `https://storage.connple.com`(hairpin NAT 경유) 에서
`http://192.168.0.254:9000`(같은 컨테이너의 사설망) 로 전환하고 실측했다.

```
                    HTTPS(hairpin)    사설 IP 직결
MinIO 헬스체크        —                connect 0.33ms / total 1.1ms
앱 히트               34~42ms          42~56ms
앱 콜드               178~208ms        263ms
```

- 연결·응답은 정상(200, 정상 바이트) — **정합성 문제는 없다**
- 그러나 **개선은 관측되지 않는다.** 오차 범위 내 동일하거나 소폭 높다
- 원인: 콜드 경로 178~263ms 의 대부분은 vips 리사이즈 자체의 CPU 비용이다.
  TLS 핸드셰이크·hairpin NAT 가 아낄 수 있는 값(수~수십 ms)은 이 총량에서
  노이즈 수준이라 관측되지 않는다. 히트 경로도 부트스트랩·ETag 계산이
  지배적이라 마찬가지다
- **결론**: 이 변경은 Phase 4 의 "성능 레버"가 아니라 "인프라 정리" 항목으로
  재분류한다. 사설망 직결 자체는 hairpin NAT 를 거치지 않는 더 단순한 경로이므로
  유지할 가치는 있으나, 성능 개선을 목적으로 우선순위를 매기지 않는다
- 2026-08-27 images-dev `.env` 에 적용 완료. **운영(images)은 여전히
  `https://storage.connple.com`** — 손대지 않음. 사설 IP는 이 컨테이너(ct117)
  안에서만 유효한 값이므로 `.env.example` 에는 반영하지 않는다(공개 예시 값 유지)

### 10. 히트 경로는 이미 하한에 도달했다 — 남은 레버는 전부 프록시 쪽이다

2026-08-28 LAN(192.168.0.193)에서 실측. keep-alive 로 TLS 수립 비용을 제거한 값이다.

| 측정 | dev |
|---|---|
| `robots.txt` (openresty 정적) | 4.3ms |
| `/up` (Laravel 부트스트랩) | 6.5ms |
| MinIO 직접 GET (preview 오브젝트, LAN) | 6.3ms |
| preview 히트 (캐시버스트, 앱 경유) | 12.1ms |

- **12.1ms ≈ 부트스트랩 6.5 + MinIO GET 6.3.** 앱이 그 위에 얹는 비용은 측정 한계 이하다
- 즉 히트 경로에서 코드로 줄일 대상이 남아 있지 않다. 부트스트랩은 preload/Octane 영역이고
  MinIO GET 은 우회(레버 3)로만 없앨 수 있다
- 신규 커넥션 기준 TTFB 는 dev·운영 모두 약 20ms 로 동일하다. 차이의 대부분은 TLS 수립이라
  앱 성능 지표로 쓰면 안 된다

미스 경로 대조(신규 커넥션 TTFB, 같은 오브젝트 `orderhow/cropped-images/105114-DaeXhsad.jpg`):

| | dev | 운영 |
|---|---|---|
| 리사이즈 미스 | 85 / 197 / 187ms | 489 / 365ms |

- Phase 4-1(dev 코드 배포)의 3~4배 개선이 재확인됐다
- 운영은 여전히 `Set-Cookie` 2건(XSRF-TOKEN·laravel_session)을 바이너리 응답에 붙인다. dev 는 없다

**부산물**: 측정으로 preview 가 생성됐다 — dev `241x241`·`242x242`·`243x243`,
운영 `244x244`·`245x245` (전부 위 오브젝트). 남겨도 무해하다.

### 11. preview 오브젝트는 익명 읽기가 열려 있다 (레버 3 의 전제 조건 충족)

`http://192.168.0.254:9000/orderhow/previews/300x300/54/549a8f1cc95650780e81d8317e946763.webp`
가 인증 없이 200 을 반환한다. preview 키는 `previews/{w}x{h}[!]/{md5앞2}/{md5(경로)}.webp`
로 결정적이므로 openresty 가 `ngx.md5` 로 직접 계산해 MinIO 를 먼저 때리고 404 면 앱으로
폴백하는 구성이 가능하다. **레버 3 은 코드 변경 없이 프록시 설정만으로 구현된다.**

### 12. 캐시 수명 축소의 범인은 NPM 의 "Cache Assets" 블록이다 (레버 2 확정)

2026-08-28. 앞단은 **Nginx Proxy Manager**(호스트명 `nginxproxymanager`, openresty 기반).
설정은 `/usr/local/openresty/nginx/conf` 가 아니라 NPM 이 생성하는 `/data/nginx/` 에 있다.

Host 헤더를 바꿔가며 오리진(ct117)과 프록시를 대조해 원인을 분리했다.

| 요청 | 오리진 ct117 | openresty 경유 |
|---|---|---|
| `.jpg` (asset 확장자) | `max-age=31536000, public` / `Vary: Accept` | `max-age=604800` ×2 / **Vary 소실** |
| `/up` (확장자 없음) | `no-cache, private` / `Vary: Accept-Encoding` | **그대로 통과** |

- **앱도 ct117 의 nginx 도 정상이다.** 7일 축소·헤더 중복·`Vary` 소실은 전부 NPM 이 만든다
- asset 확장자에만 적용되는 점이 NPM 의 "Cache Assets" 토글(`expires` + `add_header
  Cache-Control`) 임을 가리킨다
- **그 블록의 `proxy_cache` 는 설정돼 있었으나 동작하지 않고 있었다.** 처음에는 응답에
  `Age`·`X-Cache-Status` 가 없고 응답시간이 평탄한 것(20ms 고정)을 보고 "캐시가 아예
  걸려 있지 않다" 고 판단했으나, `proxy-host-33_error.log` 에서 정정됐다 — 사실 14번 참조.
  결과적으로 토글을 꺼도 잃을 캐시는 없었지만 근거는 틀렸다
- 부작용 주의: NPM 의 asset 블록은 `access_log off` 도 포함한다. 끄면 이미지 요청이 다시
  로그에 남는다 — Phase 0 의 URL 추출에는 유리하고, 로그 용량은 늘어난다
- **레버 4(프록시 디스크 캐시)는 아직 전혀 적용돼 있지 않다**는 것도 같이 확인됐다

조건부 요청은 프록시를 통과해 정상 동작한다(`If-None-Match` → 304, 0바이트). 레버 6 의
효과가 실제로 나타나는 경로임이 확인됐다.

ct117 은 두 사이트를 모두 호스팅한다: `images.connple.com` 은 `Set-Cookie` 2건(main 코드),
`images-dev.connple.com` 은 0건(dev 코드), 기본 server 는 images-dev.

**적용 완료 (2026-08-28)**: NPM 의 Cache Assets 토글을 껐다. 증상 3가지가 모두 해소됐고
프록시 응답이 오리진과 완전히 일치한다.

```
전: cache-control: max-age=604800 / cache-control: public, max-age=604800 / Vary 없음 / expires 헤더 존재
후: cache-control: max-age=31536000, public / vary: Accept / expires 헤더 없음
```

회귀 검증: 바이트 md5 오리진=프록시 일치, `If-None-Match` → 304 0바이트, 없는 이미지 404,
원본(`show`) 경로도 1년 캐시 적용.

**주의 — 이 변경은 dev 뿐 아니라 운영(images)에도 함께 적용됐다.** 레버 2 는 코드와 무관한
프록시 설정이라 컷오버를 기다릴 이유가 없지만, 두 가지를 감수한 것이다.

1. **오리진 장애가 더 오래 가려진다.** 7일 → 1년. 2026-08-27 운영 404 장애를 사용자가
   인지하지 못한 것과 같은 구조이며 노출 기간만 길어졌다. 장애 확인은 반드시 시크릿 창
   또는 `curl` 로 한다 (기존 "위험 요소" 항목과 동일)
2. **같은 키로 원본을 덮어쓰면 최대 1년간 반영되지 않는다.** 실측 확인 결과 위험은 낮다 —
   orderhow `product_images` 85,840건 중 `storage_path` 중복 0건, 91%(78,409건)가
   `cropped-images/{id}-{랜덤}.jpg` 로 키 충돌이 구조적으로 불가능하다. 다만
   `product-Images/{id}.jpg`(3,581건)는 ID 기반이라 덮어쓰기가 가능한 형태다.
   **이미지 교체가 필요하면 같은 키를 덮어쓰지 말고 새 키로 올린다**(preview 키가 원본
   경로의 함수라 덮어쓰기는 preview 도 함께 stale 로 만든다 — 1년 캐시 이전부터 있던 문제다)

### 13. 미리보기 히트 PHP 우회 적용 완료 (레버 3, dev 한정 — 2026-08-28)

NPM proxy host 33 의 Advanced 에 lua 블록을 넣어, 미리보기가 이미 있으면 MinIO 로
직접 프록시하고 없으면 앱으로 폴백한다. 설정 원본은
`docs/work/images2-migration/npm-preview-bypass.conf` 에 보관한다.

**proxy host 33 하나가 `images-dev` 와 `images` 를 같은 server 블록에서 처리한다.**
레버 2 가 운영까지 함께 반영된 이유가 이것이다. 레버 3 은 lua 에서 `$host` 로 dev
한정으로 막아 뒀다(그 줄을 지우면 운영에도 적용된다).

폴백은 `proxy.conf` 를 그대로 include 한다 — 그 파일이 `proxy_pass` 까지 포함하므로
현재 동작을 추측 없이 복제한다.

측정 (keep-alive, 13회 중 12회 중앙값):

| 경로 | 값 |
|---|---|
| 우회 (dev, 신규) | **7.4ms** |
| 앱 경유 (적용 전 dev, 사실 10번) | 12.1ms |
| MinIO 직접 (LAN, 이론 하한) | 5.0ms |

- 프록시 오버헤드는 2.4ms 뿐이다. **히트 경로에서 PHP 가 완전히 사라졌다** — 응답시간
  감소보다 FPM 워커를 소비하지 않는 것이 본 효과다
- 조건부 요청은 lua 가 프록시에서 304 로 끝낸다. MinIO 도 PHP 도 접촉하지 않는다

검증 (전부 통과):

| 항목 | 결과 |
|---|---|
| 바이트 정합성 (우회 vs 앱) | md5 일치 |
| 조건부 요청 | 304 / 0바이트 |
| 폴백 A 미생성 preview | 200, 앱이 생성 (324ms) |
| 폴백 B 없는 이미지 | 404 |
| 폴백 C 익명읽기 막힌 버킷(disclo-bot) | 200 (앱 경유) |
| 폴백 D 운영 호스트 | 앱 경로 유지 |
| 크롭 표기 `c`/`!`/`%21` | ETag 3종 동일, 비크롭과는 상이 |
| 상한초과 `3001x300`·`0x0` | 500 유지 |
| 비-리사이즈 경로(원본·`/up`·`robots.txt`) | 회귀 없음 |
| 쿼리스트링 부착 | 200 |

**의도된 동작 변화 — Range 요청**: 우회 경로는 MinIO 가 처리하므로 `Range: bytes=0-99`
가 206/100바이트로 응답한다. 앱은 전체 200 을 반환했다. 개선이지만 변화이므로 기록해 둔다.

**운영 적용 전 필수 선행 조건**: 레버 6(ETag 경로 기반, 커밋 `4ba5a82`)이 **먼저 배포돼야
한다.** 우회 경로는 nginx 가 `md5(bucket + preview경로)` 를 내보내는데 미배포 상태의 앱은
`md5(바이트 + size)` 를 내보낸다. dev 에서 실측으로 확인했다 — 콜드 미스(앱)와 이후
히트(우회)의 ETag 가 달랐다(`004cb9f8…` vs `679b35d9…`). 순서를 지키지 않으면 폴백이
일어날 때마다 클라이언트 캐시가 무효화된다.

#### 13b. 원본(`show`) 경로도 우회로 확장 (2026-08-28)

같은 방식으로 원본도 MinIO 직결로 돌렸다. 미리보기보다 이득이 크다.

| 원본 크기 | 적용 전(dev 앱) | 적용 후(우회) | MinIO 직접 |
|---|---|---|---|
| 324KB | 15.2ms | **8.3ms** | 5.4ms |
| 659KB | 19.2ms | **7.8ms** | 5.0ms |

- 앱은 파일 전체를 PHP 메모리에 올렸다가 복사하므로 **크기에 비례해 느려진다**(15.2 → 19.2ms).
  우회는 평탄하다(8.3 → 7.8ms). 큰 파일일수록 격차가 벌어진다
- 운영(main 코드)은 같은 요청이 43~48ms 지만 코드 차이가 섞인 값이라 레버 효과로 쓰면 안 된다

부수 개선 2건:

- **Content-Type 교정**: 앱은 `image/jpg`(비표준)를 내보냈다. MinIO 는 `image/jpeg` 를 내보낸다
- **ETag·Last-Modified 신규 부여**: 원본 응답에는 검증자가 아예 없어 재검증이 항상 200 이었다.
  MinIO 의 ETag 는 내용 기반이라 **미리보기와 달리 staleness 위험이 없다** — 원본이 바뀌면
  값도 바뀐다. 사실 12번에서 감수했던 1년 캐시 위험 2번 항목이 원본에 한해 해소된다
- 따라서 **원본 우회는 레버 6 배포와 무관하다.** 미리보기 우회만 ETag 배포에 묶여 있다

설계에서 주의한 3가지 (전부 실제로 문제가 될 수 있었다):

1. **순서** — nginx 는 regex location 을 작성 순서대로 시도한다. 원본 정규식은 리사이즈 URL 도
   매치하므로 반드시 미리보기 블록 **다음**에 와야 한다. 앞서면 미리보기 우회가 무력화된다
2. **`/.well-known/acme-challenge/`** — 버킷을 `[^/.]` 로 시작하게 해 배제했다. 빠뜨리면
   **인증서 자동 갱신이 깨진다**
3. **`Cache-Control` 중복** — MinIO 가 원본에 `Cache-Control` 을 메타데이터로 저장하고 있어
   (업로드 앱이 설정) `proxy_hide_header` 없이는 헤더가 두 번 나간다. 적용 중 실제로 발생해
   수정했다. 미리보기 오브젝트에는 저장값이 없지만 재발 방지로 양쪽에 넣었다

원본 키의 임의 문자 대비로 디코딩된 `$uri` 대신 `request_uri` 의 원본 인코딩을 넘긴다.
orderhow `product_images` 기준 `[^A-Za-z0-9/._-]` 를 포함한 키는 0건이라 현재 위험은 없다.

검증: 바이트 정합성 일치, 없는 원본 404, `disclo-bot`(403 버킷) 앱 폴백 200,
ACME 경로 미탈취, `/up`·`robots.txt` 무영향, 미리보기 우회 회귀 없음.

### 14. NPM 의 open file 한도가 1024 다 (레버와 무관한 안정성 결함)

`nginx -t` 경고와 에러 로그에서 확인됐다.

```
nginx: [warn] 4096 worker_connections exceed open file resource limit: 1024
2026/08/28 08:19:27 [crit] open() "/var/lib/nginx/cache/public/..." failed (24: Too many open files)
```

- `worker_connections` 는 4096 인데 프로세스 fd 한도가 1024 다. **동시 처리 한도가 설정값의
  1/4 로 잘려 있다**
- 오늘(2026-08-28) 운영 트래픽에서 실제로 터졌다. asset 캐시 쓰기가 EMFILE 로 실패했다
- 이것이 사실 12번에서 `Age`·`X-Cache-Status` 가 보이지 않았던 이유다. `proxy_cache` 는
  설정돼 있었지만 **쓰기가 실패해 캐시가 동작하지 않았다**
- 조치: NPM 의 systemd 유닛에 `LimitNOFILE` 상향, 또는 `nginx.conf` 에
  `worker_rlimit_nofile 65535;` 추가. **사용자 확인 후 별도 진행**
- 레버 4(프록시 디스크 캐시)의 재평가 필요: 우회(7.4ms)가 MinIO 직접(5.0ms)에 이미
  근접해 로컬 캐시의 잔여 이득은 2~3ms 수준이다. 우선순위는 낮아졌고, fd 한도는
  성능보다 **부하 시 연결 실패** 문제로 다뤄야 한다

---

## Phase 0. 기준선 + 병목 위치 판별

- [x] 프레임워크 부트스트랩 비용 측정 → 약 1.5ms (사실 5번)
- [ ] 액세스 로그에서 URL 리스트·크기 분포·히트/미스 비율 추출 (`scripts/extract-urls.sh`)
- [ ] 4경로 응답시간 측정 (`scripts/bench-baseline.php`)
  - A: MinIO 직접 GET / B: 앱 `show` / C: 앱 `resize` 히트 / D: 앱 `resize` 미스
- [ ] NAS 동시 읽기 처리량 한계 측정
- [ ] p50/p95/p99, RPS, 5xx율, CPU·메모리 기록

**판단 기준**: `B − A` 가 작으면(5ms 미만) NAS 가 천장이다. 그 경우 Phase 4 의 프록시 레버에 집중한다.

## Phase 1. images2 인프라

- [x] 별도 서버(ct110), standard 배포로 재생성 — zero-downtime 배포 이슈 해소
- [ ] `libvips` + `ext-vips` 설치 (사용은 Phase 4-4)
- [ ] Basic Auth 또는 IP 제한으로 외부 차단
- [ ] `display_errors` 웹 SAPI 설정 확인 (사실 7번)

## Phase 1.5. 안정성 수정 (완료 — 2026-08-27, `fix/storage-exception-masking`)

성능 개선과 무관하게 확정된 작업이었다. 2026-08-27 장애 2건이 모두 여기서 비롯됐다.
`config/database.php` deprecation 은 PHP 8.5(ct110) 전용이라 ct117(PHP 8.4)에는
해당하지 않아 범위에서 제외했다 — ct110 폐기로 항목 자체가 무의미해짐.

- [x] **예외 마스킹 제거** (커밋 `ae205b4`) — `getStorageDisk()` 에서 AWS 오류
  코드별 분기: `NoSuchKey`/`NoSuchBucket` → 404, 연결 실패 → 503, 그 외
  권한·설정 오류 → 500. `previous` 보존, 404 아닌 경우 `Log::error` 기록.
  테스트 6건(`ImageServiceStorageExceptionTest`)
- [x] **S3 HTTP 타임아웃·재시도 추가** (커밋 `16048ec`) — `connect_timeout` 2초,
  `timeout` 10초, `retries` 1회. `MINIO_CONNECT_TIMEOUT`/`MINIO_TIMEOUT`/
  `MINIO_RETRIES` 로 재정의 가능, `.env.example` 문서화.
  테스트 4건(`ImageServiceStorageTimeoutTest`)
- [x] 전체 스위트 66 passed (기존 56 + 신규 10), Pint 통과, 회귀 없음

## Phase 2. 테스트 스토리지 + dev 배포 검증

- [x] **images-dev 스토리지 연결 확인** — 원인은 images2(ct110, 폐기)의 네트워크
      경로 문제였을 뿐, images-dev(ct117) 자체는 처음부터 정상이었다(사실 4번의
      404 미스터리는 config 캐시 staleness 가설도 배제되고 결국 vips FFI 설정
      문제로 판명, 사실 8번 참조). 2026-08-27 `MINIO_ENDPOINT` 를 사설 IP로
      전환 완료(사실 9번), 원본·히트·콜드 전부 200 확인
- [ ] 별도 MinIO 버킷 생성 (예: `orderhow-staging`) — **같은 NAS 위에**
- [ ] `mc mirror` 로 대표 원본 샘플 복사
- [ ] `.env` 는 `MINIO_ENDPOINT`/`MINIO_BUCKET`/자격증명만 교체 (코드 변경 없음)
- [ ] 서버에서 `php artisan test` 1회 (56건)
- [ ] shadow diff 실행 (`scripts/shadow-diff.php`) — 정상 URL 상위 500 + 경계 케이스
- [ ] 헤더 회귀 확인: `Set-Cookie` 없음(의도), `ETag`·`Cache-Control` 유지

**Laravel 11 → 13 2단계 점프의 유일한 방어선이다.** URL 샘플 수를 아끼지 않는다.

## Phase 3. Octane(FrankenPHP) — 보류

**보류 근거**: 프레임워크 부트스트랩 비용 실측 **약 1.5ms**(사실 5번). Octane 이 줄일 수 있는
상한이 이 값이며, 같은 앱의 콜드 패스는 520ms 다. 반면 비용은 확정적이다.

- 워커 수가 곧 동시성 한계다. FPM 은 자식 수십 개로 NAS 지연을 흡수하지만 워커 4~8개면 정지한다
- 워커 메모리: GD 비트맵은 `width×height×4` 이므로 3000×3000 허용 기준 건당 약 36MB × 워커 수
- 부팅 시 PHP 8.5 deprecation 2건 → FrankenPHP worker 모드에서 워커 종료 위험
- 상태 유출 검토가 향후 모든 코드 변경에 영구 부담으로 추가된다
- Phase 4-2(프록시 우회)를 적용하면 히트 경로에서 PHP 가 사라져 잔여 이득이 더 줄어든다

**재검토 조건**: Phase 4 를 모두 적용한 뒤에도 부트스트랩 비중이 유의미하게 남는 경우.
그때는 `opcache.preload` 를 먼저 시도한다(리스크 없음).

`laravel/octane` 의존성과 `config/octane.php` 는 그대로 둔다. `OCTANE_SERVER` 는 설정하지 않는다
(`config/octane.php` 기본값이 `Swoole` 이므로 실수로 기동하면 실패한다).

## Phase 4. 성능 레버 — 하나씩 적용하고 매번 재측정

| 순위 | 항목 | 효과 구간 | 실측 근거 |
|---|---|---|---|
| 1 | **dev 코드 배포** (bcrypt 202ms + MinIO 왕복 2회 제거) | 미스 | 콜드 580ms(main) → 145ms(dev), 4배. 히트·원본은 무변화(예상대로) — 2026-08-27 동일 오브젝트 A/B 실측 |
| 2 | **캐시 수명 1년 복원** — 완료(dev·**운영 동시**, 2026-08-28) | 히트 | 사실 6번·12번 |
| 3 | **히트 시 PHP 우회 (미리보기+원본)** — 완료(dev, 2026-08-28) | 히트 | 미리보기 12.1->7.4ms, 원본 15.2->8.3ms. 사실 13·13b |
| 4 | 리버스 프록시 로컬 디스크 캐시 — **우선순위 하향** | 히트 | 잔여 이득 2~3ms. 사실 14번 |
| 5 | **vips 전환** (`IMAGE_DRIVER=vips`) — 완료(images-dev, 2026-08-27) | 미스 | 콜드 178~208ms, 히트 34~42ms. main(gd) 대비 픽셀·바이트 완전 일치(300x300, 6,056B) |
| 6 | **ETag 계산 방식 변경** — preview 경로 기반 — 완료(코드, 2026-08-28) | 히트 | 아래 참조 |
| 7 | `opcache.preload` | 전체 | 부트스트랩 1.5ms 의 일부 |

**레버 6 상세 (2026-08-28 구현)**: `ImageService::previewETag()` 추가, `ImageController::resize()`
가 바이트를 읽기 전에 ETag 를 확정하고 조건부 요청을 끝낸다.

- 전: `md5($processedImage.$size)` → 304 로 끝나는 요청도 preview 전체를 MinIO 에서 받았다
- 후: `md5($bucket.'/'.$previewPath)` → 304 는 스토리지 왕복 0회. 사실 10번 기준 12.1ms → 약 6.5ms
- 정합성: preview 경로는 (원본 경로, 폭, 높이, 크롭) 의 순수 함수이고 그 조합의 내용은
  이미 preview 캐시가 고정한다. 원본을 같은 키로 덮어써도 **기존 구현 역시** 캐시된 preview 를
  그대로 반환했으므로 staleness 는 새로 생기지 않는다
- 버킷을 해시 입력에 넣었다 — preview 경로에 버킷이 없어 서로 다른 버킷의 같은 키가 같은
  ETag 를 받는 상태를 만들지 않기 위해서다
- 304 응답에 `ETag`·`Cache-Control`·`Vary` 를 실었다(전에는 헤더 없는 빈 304, RFC 9110 §15.4.5 위반)
- **배포 시 1회성 비용**: ETag 값 체계가 바뀌므로 기존 클라이언트 캐시의 첫 재검증이
  304 대신 200 으로 나간다. 이후 정상화된다
- `show()`(원본)에는 **의도적으로 적용하지 않았다.** 원본은 preview 캐시 같은 고정 장치가 없어
  경로 기반 ETag 를 붙이면 원본 교체가 클라이언트에 영원히 반영되지 않는다. 현재는 ETag 가
  없어 재검증이 항상 200 이므로 안전하다. 필요하면 MinIO 오브젝트 메타데이터 기반으로 별도 검토
- 테스트: `ImageControllerTest` 19건(신규 3건 포함), 전체 68 passed / 171 assertions, Pint 통과

**인프라 정리 (성능 레버 아님)**: `MINIO_ENDPOINT` 사설 IP 직결 — 완료(images-dev,
2026-08-27). 측정 가능한 성능 이득 없음(사실 9번). hairpin NAT 회피 목적의 위생
개선으로만 유지. 컷오버 시 운영에도 반영할지는 별도 판단.

## Phase 5. 부하 테스트

- [ ] **사전 워밍 필수** — `php scripts/shadow-diff.php --warm-only`
- [ ] 히트·미스 분리 측정 + Phase 0 실제 비율로 혼합한 시나리오
- [ ] 동시성 계단식 증가로 포화점 확인, NAS 처리량 한계와 대조

## Phase 6. 컷오버

1. [ ] 48시간 전 도메인 DNS TTL 60초로 하향
2. [ ] `dev` → `main` PR 생성·머지 (main 은 PR 로만, 머지는 사용자가 직접)
3. [ ] images2 를 `main` 기준 재배포
4. [ ] `.env` 의 MinIO 설정을 **운영 스토리지로 교체** → `config:clear` → 스모크 테스트
       - preview 경로 로직 동일함은 확인했으나 상위 20개 URL 로 실제 히트 여부 육안 확인
5. [ ] DNS(또는 openresty upstream) 전환. **구 images 서버는 종료하지 않고 유지**
6. [ ] 관찰 창 최소 24시간: 5xx율, p95, NAS I/O, MinIO 에러율
7. [ ] 문제 시 롤백 = 전환 원복
8. [ ] 안정 확인 후 구 서버 폐기 → Forge 사이트명을 `images` 로 정리

## 위험 요소

- **스테이징 버킷 정리**: 컷오버 후 지우기 전에 images2 가 운영 버킷을 보는지 재확인
- **NAS 가 천장인 경우**: Phase 0 의 `B − A` 가 작으면 프록시 레버(3·4)로 방향 전환
- **Laravel 11 → 13 2단계 점프**: 방어선은 Phase 2 shadow diff 뿐
- **브라우저 캐시가 장애를 가린다**: 7일 캐시 때문에 오리진 404 를 사용자가 인지하지 못한다.
  장애 확인은 반드시 시크릿 창 또는 `curl` 로 한다
- **Phase 2·4 를 합치지 말 것**: 합치면 문제 발생 시 되돌릴 지점이 사라진다
- **미스 측정의 부작용**: `bench-baseline.php --miss` 는 실제로 preview 를 생성한다.
  스크립트가 정리 명령을 출력하므로 반드시 수행할 것

## 스크립트

`docs/work/images2-migration/scripts/` — 전환 완료 후 삭제 가능한 일회성 도구다.

| 파일 | Phase | 용도 |
|---|---|---|
| `diag-storage.php` | 1.5, 2 | 스토리지 접근 실패 원인 진단 (404 마스킹을 벗긴다) |
| `extract-urls.sh` | 0 | 액세스 로그 → URL 리스트, 크기 분포, 히트/미스 비율 추정 |
| `bench-baseline.php` | 0, 4 | A/B/C/D 4경로 응답시간 측정 및 분해 |
| `shadow-diff.php` | 2, 5 | 두 호스트 응답 동등성 비교 / preview 워밍 |
| `bench.env.example` | 0 | 측정 설정 템플릿 |
| `urls.edge-cases.txt` | 2 | 경계 케이스 URL 템플릿 |
