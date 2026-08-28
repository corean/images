# 프로젝트 커스텀 지침

## Git Commits

- 커밋은 기능별로 분리하여 작성한다. 하나의 커밋에 여러 기능을 묶지 않는다.
- 각 커밋은 단일 책임 원칙을 따른다 (설정 변경, 모델 수정, 헬퍼 함수 수정, UI 변경 등).
- 커밋 메시지 형식: `타입(범위): 설명`
  - 타입: 수정, 개선, 설정, 추가, 삭제, 리팩터 등
  - 범위: 변경된 주요 파일이나 기능 (Product, Audit, UI 등)
- 예시:
  - `설정(Audit): 빈 변경 이력 저장 방지`
  - `개선(Product): 변경되지 않은 필드 audit 저장 방지`
  - `수정(Helpers): boolean 필드 audit 값 표시 개선`
  - `개선(UI): 변경 내용 없는 audit 항목 숨김 처리`
- 커밋 메시지에 `Co-Authored-By` 라인을 포함하지 않는다.

## 작업 워크플로우 (계획과 실행의 분리)

비사소한(non-trivial) 작업은 반드시 아래 단계를 순서대로 따른다. **계획이 확정되기 전에는 절대 코드를 작성하지 않는다.**

### 1단계: 리서치 (Research)
- 관련 코드베이스를 깊이 분석하고 `docs/work/{작업명}/research.md`에 기록한다.
- 기존 패턴, 관련 파일, 의존성, 제약조건을 문서화한다.
- 사용자에게 분석 결과를 공유하고 이해도를 검증받는다.

### 2단계: 계획 (Planning)
- 구체적 구현 계획을 `docs/work/{작업명}/plan.md`에 작성한다.
- 반드시 포함할 내용: 수정할 파일 경로, 변경 내용 요약, 핵심 코드 스니펫, 예상 영향 범위.
- "아직 구현하지 말 것" - 이 단계에서는 문서만 작성한다.

### 3단계: 주석 사이클 (Annotation)
- 사용자가 plan.md를 검토하고 인라인 주석(`> 주석:`)으로 피드백을 추가한다.
- Claude는 피드백을 반영하여 plan.md를 수정한다.
- 사용자가 "확정" 또는 "구현해"라고 할 때까지 반복한다.

### 4단계: 구현 (Implementation)
- 확정된 plan.md를 기계적으로 따라 코드를 작성한다.
- 계획에 없는 변경은 하지 않는다.
- 구현 완료 후 plan.md의 각 항목에 완료 체크를 남긴다.

### 5단계: 피드백 (Feedback)
- 구현 결과를 사용자가 검토한다.
- 문제 발견 시 짧고 명확한 지시로 수정한다.

### 예외
- 단순 버그 수정, 오타 수정, 1~2줄 변경 등 사소한 작업은 이 워크플로우를 생략할 수 있다.
- 사용자가 "바로 구현해"라고 명시하면 리서치/계획 단계를 건너뛸 수 있다.

## Git Worktree 워크플로우

병렬 작업 시 충돌·커밋 섞임 방지를 위해 아래 순서와 규칙을 따른다.

### 브랜치 이름

접두어 없는 이름은 금지한다. 아래 3종만 쓴다.

| 접두어 | 용도 |
|---|---|
| `feature/{작업명}` | 기능 추가·개선·리팩터 |
| `fix/{작업명}` | 버그 수정·핫픽스 |
| `claude/{작업명}` | 웹·클라우드 Claude 세션이 생성 (직접 만들지 않음) |

### 순서
1. **생성**: `git fetch origin` 후 `git worktree add ../orderhow-{작업명} -b feature/{작업명} origin/dev` (로컬 dev 기준 분기 금지)
2. **셋업**: `.env` 복사 후 `composer install` 실행. vendor 심볼릭링크 금지(Pest/Faker 깨짐)
3. **작업 + 커밋**: `git status` 확인 후 **명시적 경로만** `git add`. `git add .` / `-A` 금지
4. **통합**: **로컬 dev 체크아웃 금지** — 로컬/원격 dev 갈라짐의 원인이다(`Merge remote-tracking branch 'origin/dev' into dev` 가 그 증상).
   - 통합 전 확인: `git log --oneline --stat origin/dev..HEAD` (내 커밋만 있는지) + `git diff origin/dev HEAD --stat` (범위 밖 파일 없는지)
   - base 가 origin/dev 가 아니면(main 기준 분기 등) `git rebase origin/dev` 로 정리한다. 안 하면 main 의 merge 커밋이 dev 로 역유입된다
   - **feature → dev**: `git push origin HEAD:dev` (원격 fast-forward). FF 가 아니면 push 가 자동 거부되므로 그때 rebase 한다
   - **claude/\* → dev**: 웹·클라우드 세션 브랜치도 **예외 없이 같은 경로**를 쓴다.
     `git fetch origin && git rebase origin/dev` 후 `git push origin HEAD:dev`.
     로컬 dev 에서 `git merge claude/...` 로 합치면 그 순간 원격과 갈라져 이후 FF push 가 전부 막힌다
   - **dev → main**: PR 로만 통합한다(Forge 가 main 을 배포하는 게이트). PR 생성·merge 는 사용자가 직접 처리
5. **정리**: merge 확인 후 메인 체크아웃에서 `git worktree remove` → `git branch -d`(`-D` 금지, merge 누락 안전장치) → `git fetch --prune && git pull`

### 규칙
- 1 워크트리 = 1 브랜치 = 1 세션. 메인 체크아웃(dev)에서는 직접 작업하지 않는다.
- 병행 세션이 수정한 파일(범위 밖 변경)은 커밋에 포함하지 않는다.
- dev 는 로컬에서 merge·체크아웃하지 않는다. dev 를 움직이는 방법은 **원격 FF push 뿐**이다.
  브랜치 출처(로컬 워크트리·웹 세션·클라우드)와 무관하게 적용된다.
- 이 규칙은 **git 설정으로 강제할 수 없다**(2026-08-12 실험 확인). `pull.ff only` 는 `git pull --no-rebase`
  나 명시적 `git merge` 를 막지 못하고, 설정이 없어도 최신 git 은 갈라진 pull 을 이미 거부한다.
  실질적 강제 수단은 GitHub branch protection 의 linear history 뿐인데 private + 무료 플랜에서는
  사용할 수 없다. 따라서 **통합 직전 확인(4번 항목)이 유일한 방어선**이며 생략하지 않는다.

## CI (GitHub Actions)

`.github/workflows/tests.yml` 이 dev push 와 main·dev 대상 PR 에서 전체 Pest 스위트를 실행한다.
DB 는 sqlite `:memory:`, 검색은 `SCOUT_DRIVER=null` 이라 불필요하지만 **Redis 는 필요하다** —
`CartService` 와 `Kan\RateLimiter` 가 `Redis::` 파사드를 직접 써서 `CACHE_DRIVER=array` 와 무관하게
실제 서버를 요구한다(없으면 `Connection refused` 로 약 1,000 건 실패). 워크플로에 서비스 컨테이너로 띄워 둔다.
설정은 저장소에 커밋된 `.env.testing` 을 쓴다(`.env` 불필요).

기준선: 로컬 3,860 passed / 53 skipped (약 3분 30초), CI 3,860 passed / 54 skipped (약 8분).
CI 의 skip 이 1 건 많은 것은 landing-web 번들이 없어서다(정상).

### COMPOSER_AUTH 시크릿 (필수)

`filament/blueprint` 는 유료 저장소 `packages.filamentphp.com` 에서 받으며 **인증 없이는 401** 이다.
시크릿이 없으면 CI 의 의존성 설치 단계가 실패한다. 로컬 `auth.json`(gitignore 대상) 내용을 그대로 등록한다.

```bash
gh secret set COMPOSER_AUTH --repo connple-project/orderhow < auth.json
```

같은 이유로 **캐시가 빈 새 머신에서는 `composer install` 이 실패한다.** 신규 개발자 셋업 시
`auth.json` 을 먼저 배치해야 한다(저장소에 커밋 금지).

### 빌드 산출물 의존 테스트

`public/landing-web/` 처럼 gitignore 대상 산출물을 검사하는 테스트는 산출물이 없으면
`->skip()` 으로 건너뛰게 한다. 그렇지 않으면 클론 직후·CI 에서 항상 실패한다.

## 조사·통합 효율

긴 조사 세션의 실제 비용은 git 작업이 아니라 **검증 순서**와 **중간 산출물 과다**에서 나온다.
아래는 검색 순위 이슈(최종 코드 변경 91줄)가 문서 8개·약 2,000줄, 커밋 19개(그중 문서 17개)로
불어난 사례에서 도출한 규칙이다.

- **가장 싼 가설부터 검증한다.** 심층 분석(ES `_explain`, 스코어 분해, 프로파일링) 전에 먼저 확인할 것:
  - 배포된 코드가 최신인가 — `git log --oneline origin/main..origin/dev`
  - 인덱스 매핑·스키마·설정이 현재 코드와 맞는가 (필드 존재 여부, 마이그레이션 상태)
  - 로컬 재현 환경이 운영과 같은 버전인가 (구버전 인덱스·구버전 배포로 측정하면 결론이 뒤집힌다)

  위 사례의 진짜 원인은 "배포가 10커밋 뒤처짐"이었고 한 줄로 확인 가능했다. 그걸 마지막에 확인해
  앞선 조사 대부분이 무효가 됐다.
- **문서는 결론이 확정된 뒤 통합해 커밋한다.** 판단 기준이 모호하지 않도록 아래를 지킨다.
  - `research.md` 와 `plan.md` 는 **브랜치당 각 1커밋**. 주석 사이클에서 plan 을 고칠 때는
    새 커밋 대신 `git commit --amend` 하거나 통합 직전에 squash 한다
  - 구현 완료 체크(워크플로우 4단계)는 **코드 커밋에 포함하거나 브랜치 마지막에 1커밋**으로 몰아 넣는다
  - 런북·감사 보고서는 조사 중간 상태마다 갱신·커밋하지 않는다. 결론이 뒤집히면 문서도 함께 무효가 된다
  - 자가 점검: 통합 직전 `git log --oneline origin/dev..HEAD -- docs/ | wc -l` 이 3 을 넘으면 squash 한다
- **코드가 완성되면 바로 통합한다.** 브랜치 수명이 길어지면 그 사이 dev 가 진행돼 rebase 가 필요해진다.
  부수 조사(다른 저장소 확인, 운영 점검 등)는 통합 이후 별도로 진행한다.
  워크트리 셋업(`.env` 복사 + `composer install`)도 매번 수 분이 드니 브랜치는 짧게 유지한다.
- **범위 밖 테스트 실패는 조사·보고까지만 한다.** 기준선 확보 목적의 전체 스위트 반복 실행은 하지 않는다.
  1회 실패는 플레이키를 먼저 의심하고, 해당 파일 단독 실행 1회로 확인한 뒤 보고한다.

## 테스트 작성 시점

- 핵심 비즈니스 로직(헬퍼, 서비스, 모델 계산 등)은 테스트를 먼저 작성한다(TDD).
- UI/Filament 컴포넌트는 구현 후 테스트를 작성한다.
- 테스트 작성 여부 자체는 선택이 아니다. 모든 변경은 반드시 테스트로 검증한다.
