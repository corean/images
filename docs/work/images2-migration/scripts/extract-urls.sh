#!/usr/bin/env bash
#
# Phase 0: 액세스 로그에서 측정용 URL 리스트를 뽑는다.
#
# 사용법:
#   ./extract-urls.sh /var/log/nginx/images.access.log*        # 상위 500 URL
#   TOP=1000 ./extract-urls.sh /var/log/nginx/images.access.log*
#
# 출력 파일 (현재 디렉터리):
#   urls.top.txt        측정용 URL 경로 목록
#   urls.sizes.txt      크기 토큰 분포 (300x300 등)
#   urls.timing.txt     upstream_response_time 분포 → 히트/미스 비율 추정
#
# 로그 포맷 가정: nginx combined ($7 = request path).
#   다른 포맷이면 PATH_FIELD 로 필드 번호를 지정한다.
#     PATH_FIELD=7 ./extract-urls.sh ...
#   upstream_response_time 이 로그 끝에 있으면 UPSTREAM_FIELD 로 지정한다.
#     UPSTREAM_FIELD=NF ./extract-urls.sh ...

set -euo pipefail

TOP="${TOP:-500}"
PATH_FIELD="${PATH_FIELD:-7}"
UPSTREAM_FIELD="${UPSTREAM_FIELD:-}"
# 히트/미스 경계(초). 운영 main 은 미스마다 bcrypt 202ms 를 쓰므로 분포가 뚜렷이 갈린다.
MISS_THRESHOLD="${MISS_THRESHOLD:-0.15}"

if [ "$#" -eq 0 ]; then
    echo "usage: $0 <access-log> [access-log ...]" >&2
    exit 1
fi

cat_logs() {
    for f in "$@"; do
        case "$f" in
            *.gz) gzip -dc "$f" ;;
            *)    cat "$f" ;;
        esac
    done
}

echo "== 상위 ${TOP} URL 추출 =="
cat_logs "$@" \
    | awk -v f="$PATH_FIELD" '{print $f}' \
    | sed 's/?.*$//' \
    | grep -E '^/[^/]+/.+' \
    | grep -vE '^/(up|robots\.txt|favicon\.ico)' \
    | sort | uniq -c | sort -rn \
    | head -n "$TOP" \
    | awk '{print $2}' > urls.top.txt
echo "  -> urls.top.txt ($(wc -l < urls.top.txt | tr -d " ") 건)"

echo "== 크기 토큰 분포 =="
cat_logs "$@" \
    | awk -v f="$PATH_FIELD" '{print $f}' \
    | sed 's/?.*$//' \
    | grep -oE '/[0-9]+x[0-9]+(c|!|%21)?/' \
    | tr -d '/' \
    | sort | uniq -c | sort -rn > urls.sizes.txt
echo "  -> urls.sizes.txt ($(wc -l < urls.sizes.txt | tr -d " ") 종)"
head -n 15 urls.sizes.txt

if [ -n "$UPSTREAM_FIELD" ]; then
    echo "== 응답시간 분포 / 히트·미스 비율 추정 (경계 ${MISS_THRESHOLD}s) =="
    cat_logs "$@" \
        | awk -v pf="$PATH_FIELD" -v uf="$UPSTREAM_FIELD" -v t="$MISS_THRESHOLD" '
            {
                path = (pf == "NF") ? $NF : $(pf + 0)
                sub(/\?.*$/, "", path)
                # 이미지 라우트만 집계한다. /up, /robots.txt 등은 제외.
                if (path !~ /^\/[^\/]+\/.+/) next
                if (path ~ /^\/(up|robots\.txt|favicon\.ico)/) next

                v = ((uf == "NF") ? $NF : $(uf + 0)) + 0
                if (v <= 0) next

                n++
                if (v >= t) miss++; else hit++

                b = int(v * 100)          # 10ms 버킷
                if (b > 100) b = 100
                h[b]++
            }
            END {
                if (n == 0) {
                    print "집계 가능한 레코드가 없다. PATH_FIELD / UPSTREAM_FIELD 를 확인할 것."
                    exit
                }
                printf "총 %d건  히트추정 %d (%.1f%%)  미스추정 %d (%.1f%%)\n",
                       n, hit, hit * 100 / n, miss, miss * 100 / n
                print "--- 10ms 버킷 히스토그램 (1.00 = 1s 이상) ---"
                for (i = 0; i <= 100; i++) {
                    if (h[i] > 0) printf "%6.2fs %8d\n", i / 100, h[i]
                }
            }' > urls.timing.txt
    echo "  -> urls.timing.txt"
    head -n 3 urls.timing.txt
else
    echo "== 응답시간 분포: 건너뜀 =="
    echo "  UPSTREAM_FIELD 를 지정하면 히트/미스 비율을 추정한다."
    echo "  로그에 upstream_response_time 이 없다면 nginx log_format 에 추가한 뒤 다시 실행할 것."
fi
