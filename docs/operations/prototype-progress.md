# Prototype 구현·검증 기록

> 2026-10-01. 실제 VM 없이 개발 PC의 별도 PHP·MySQL 프로세스로 구현한다. 실습 PC의 G1 자원·라이선스·네트워크는 미확인이다.

## G0·P0

- 공식 배포물로 프로젝트 내부 portable PHP 8.4.26·Composer 2.10.3·MySQL 8.4.11·Nginx 1.31.6을 준비했다. 전역 설치·기존 서비스 변경은 하지 않았다.
- PHP 필수 확장·MySQL 접속·독립 개발/테스트 DB 생성·로컬 PHP 웹 요청을 실제 확인했다.
- 현재 개발 PC는 Ryzen 7 5700U·8코어/16스레드, 사용 가능 RAM 총 13.8GiB이며 측정 시 여유 RAM 1.9GiB·D 드라이브 여유 54.9GiB였다. 이 값은 실습용 64GB PC의 자원으로 사용하지 않는다.
- CodeIgniter 4.7.4 앱 4개와 API Shield 1.4.1, 호환 PHPUnit을 설치·잠금했다. 버전·배포물 URL·체크섬·앱별 잠금파일 연결은 [runtime manifest](../../runtime-manifest.json)에 있다.
- Bootstrap 5.3.8 CSS/JS와 MIT 라이선스를 로컬 자산으로 포함하고 manifest에 파일 체크섬을 기록했다. 실행 화면은 CDN을 사용하지 않는다.
- [Prototype 계약](../../contracts/prototype-v1.json)은 요청·통지·상태·모의 설정·공유 port 경계를 고정한다. 새 입력값이나 실제 VMM 계약의 확정을 뜻하지 않는다.
- 공식 근거: [PHP Windows 배포](https://www.php.net/downloads.php?os=windows&version=8.4), [Composer](https://getcomposer.org/download/), [MySQL Community](https://dev.mysql.com/downloads/mysql/8.4.html), [Nginx](https://nginx.org/en/download.html), [CI4 설치](https://codeigniter.com/user_guide/installation/installing_composer.html), [Shield 설치](https://shield.codeigniter.com/latest/getting_started/install/).

## P1 확인

- Shield·Settings의 공식 schema와 업무/모의 구조를 개발·테스트 DB에 적용했다. 업무/모의 전용 계정과 감사 원장의 추가/조회 권한을 분리했다.
- 초기 관리자·회원 준비를 재실행해 동일 계정만 반환하는 것을 확인했다. 감사 삽입 후 강제 실패에서는 같은 트랜잭션의 변경이 롤백됐다. 감사 원장의 UPDATE/DELETE는 앱 계정에서 거부됐다.
- 실제 HTTP로 미인증 거부, 정상/잘못된 로그인, CSRF 누락 거부, 회원/관리자 역할, host-only 쿠키, 로그아웃 후 거부, 직접 API 접근 차단을 확인했다.
- CSRF 재생성 비활성화는 자동 승인 검토가 거부했다. 안전 대안으로 기본 재생성을 유지하고 응답 토큰 갱신·화면 변경요청 직렬화로 계약을 보완했다.
- 개발용 프록시 키가 진단 출력에 포함된 뒤 즉시 폐기·교체했다. 현재 진단과 증거에는 비밀 설정·자격 증명·토큰 원문을 출력하지 않는다.

## P2~P4 확인

- 신청·한도 예약·멱등·VM/작업/outbox/감사를 같은 트랜잭션으로 처리한다. 같은 키/내용은 원래 작업을 반환하고 다른 내용은 충돌이다. 마지막 한 자리 경쟁을 실제 MySQL에서 10회 반복해 매번 한 요청만 접수되는 것을 확인했다. 한도 초기 행 준비와 업무 잠금 순서를 보완해 교착을 해결했다.
- worker와 dispatcher는 짧은 lease와 시도 검사를 사용한다. 외부 호출은 DB 잠금 밖에서 실행하며, 재기동·기한 만료·소유권 상실·선행/중복 통지·같은 사건의 다른 내용·오래된 시도를 검증했다. 완료 ACK는 inbox의 저장 사실이며 업무 완료는 후속 처리 결과다.
- 모의 추첨·예정 통지·결과는 모의 전용 DB에 저장한다. 다섯 시나리오를 별도 테스트 서버의 실제 HTTP로 확인했다. 누락은 `reconciliation_required`를 유지하며 개발 CLI로 원래 event/payload를 재전송하면 기존 시도에서 완료된다. 반복 복구는 새 외부 실행을 만들지 않는다.
- 고객/관리자 개요·VM/작업 목록과 상세·신청·로그아웃/재로그인을 실제 Chrome으로 확인했다. 화면의 ID·사양·상태는 API와 대조했고 모바일 폭·날짜·로딩 종료·단일 클릭 처리·브라우저 오류를 검사했다. 화면 캡처를 직접 확인했다.
- native CSRF 재생성을 유지한다. 각 변경 전에 최신 토큰을 준비하고 응답 토큰을 소비하며 화면/같은 출처 탭 변경을 직렬화한다. 실제 요청 경로 판정과 세션 저장을 보완해 연속 변경에서 응답·저장 토큰 일치를 확인했다.
- 프로젝트 전용 DB·웹·worker 중지 후 DB 데이터를 보존한 재기동을 확인했다. MySQL 종료 대기와 PID/기동 시각 비교를 보완했다. 동일 초기 계정이 유지되며 잠금 의존성을 다시 설치해 기동할 수 있다.

## 재현한 검증

| 확인 | 결과 | 재현 도구 |
| --- | --- | --- |
| 네 앱의 기능/실제 테스트 MySQL 검사 | 82 tests · 785 assertions 통과. 로그 저장 실패 분기의 `portal_logging_degraded`는 의도된 관찰 | `tools/test-unit.ps1` |
| 인증·CSRF·쿠키·권한·직접 API 차단 | 재기동 후 HTTP 검사 통과 | `tools/test-p1.py` |
| 성공·실패·지연·누락·중복 + 누락 복구 | 실제 HTTP 5개 시나리오 및 원래 시도의 복구·재처리 통과 | `tools/test-e2e.py` |
| 고객/관리자·상세·신청·재로그인·모바일 | 기존 Chrome headless 검사 및 캡처 직접 확인 통과 | `tools/test-browser.cjs` |
| 실행 환경 재준비·중지·재시작 | 체크섬·기존 도구 재사용·기존 계정/데이터 유지 확인 | `tools/bootstrap-runtime.py`, `start-dev.ps1`, `stop-dev.ps1` |
| 계획·문서·배포물 연결 | 58개 ID의 인계 필드·로컬 링크·잠금/자산 digest·비밀값 제외 확인 | `tools/verify-repository.py` |

커버리지 비율은 측정하지 않았다. HTTP 시나리오의 강제 선택과 단축 지연은 격리된 개발 Test 조건이며 기본 포털의 무작위 정책과 구분한다. 위 결과는 로컬 Prototype 증거다.

## 적용 범위와 다음 단계

| 기능 | 이번에 검증한 부분 | 남은 부분 |
| --- | --- | --- |
| FS-001~010·012~020·025~027·057~058 | 로컬 환경·인증·고정 사양·신청/작업·모의 통지/관찰·감사/로그·기본 schema/프록시 | G1/G2, 사양 관리, 실제 adapter/MSSQL/mTLS, 운영 인증서·보존·전체 migration/복구 |
| FS-021~024 | 설정 검증·추첨 영속화·예정 통지·다섯 모의 결과 | 실제 VMM 동작을 대체하지 않음 |
| FS-056 | Prototype의 Test·정제 기록·화면 근거 | E/R 각 단계의 실제 증거 |
| FS-011·028~055 | 상세계획과 책임/계약/Test/로그 기준 | E/R 구현과 실습 검증 미실행 |

G1은 별도 실습 PC의 CPU·디스크·가용 자원·제품 사용 권한·네트워크·IIS 수신부 기술·MSSQL 대상을 확인한 뒤 진행한다. 실제 VM·SSH·Linux 인프라·청구·외부 알림·Production 완료를 주장하지 않는다.

원본 실행 로그·자격 증명·DB·캡처는 Git에서 제외한 `.runtime/`에 둔다. 기술 로그는 JSONL, 감사 원장은 앱 계정에서 추가/조회만 허용한다. 로그 회전·보존 자동화와 백업/복구 운영은 R 단계에 남겨 둔다. 실행·중지·검증 순서는 [로컬 실행 안내](local-prototype.md)에 있다.
