# images2 전환 측정 스크립트

`../plan.md` 의 Phase 0 / 2 / 5 에서 쓰는 일회성 도구다. 전환 완료 후 삭제해도 된다.
외부 의존성 없음 (PHP 8.3+ / bash / curl 확장).

## 1. URL 리스트 추출 — Phase 0

```bash
# nginx combined 로그 기준. 다른 포맷이면 PATH_FIELD 지정.
./extract-urls.sh /var/log/nginx/images.access.log*

# upstream_response_time 이 로그에 있으면 히트/미스 비율까지 추정한다.
UPSTREAM_FIELD=NF ./extract-urls.sh /var/log/nginx/images.access.log*
```

출력: `urls.top.txt`, `urls.sizes.txt`, `urls.timing.txt`

## 2. 기준선 측정 — Phase 0, 그리고 Phase 4 의 매 레버 적용 후

```bash
cp bench.env.example bench.env   # 값 채우기
php bench-baseline.php --config=bench.env --runs=30
php bench-baseline.php --config=bench.env --runs=30 --miss --csv=samples.csv
```

`--miss` 는 **실제로 preview 를 생성한다.** 종료 시 정리 명령을 출력하므로 운영 버킷에
실행했다면 반드시 수행할 것.

분해 결과 해석은 `../plan.md` Phase 0 참조. `B - A` 가 5ms 미만이면 NAS 가 천장이므로
Octane·vips 보다 리버스 프록시 캐시를 우선한다.

## 3. 동등성 비교 — Phase 2, 3

```bash
# 경계 케이스 파일 준비
sed -e 's|__BUCKET__|orderhow|g' -e 's|__OBJECT__|products/2026/01/sample.jpg|g' \
    urls.edge-cases.txt > urls.edge.txt

# 정상 URL
php shadow-diff.php --a=https://images.example.com --b=https://images2.example.com \
    --urls=urls.top.txt --concurrency=8 --auth-b=user:pass --out=diff.csv

# 경계 케이스
php shadow-diff.php --a=... --b=... --urls=urls.edge.txt --out=diff-edge.csv
```

- 종료코드 0 = 불일치 없음, 1 = 불일치 존재 (CI 에서 게이트로 쓸 수 있다)
- B 는 별도 스토리지를 쓰므로 판정 우선순위는 상태 → 픽셀 크기 → 바이트 순이다.
  `BYTES` 는 참고값이고, `DIMENSION` / `STATUS` / `CONTENT_TYPE` 이 실제 회귀 신호다.
- `Set-Cookie` 는 B 에서 0건이어야 정상이다 (image 미들웨어 그룹 분리의 의도된 효과).

## 4. preview 사전 워밍 — Phase 5

스테이징 버킷은 preview 가 비어 있어 그대로 측정하면 전부 미스가 나온다.

```bash
php shadow-diff.php --b=https://images2.example.com --urls=urls.top.txt \
    --warm-only --concurrency=4
```
