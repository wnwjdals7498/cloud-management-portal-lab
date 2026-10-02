# 로컬 Prototype 실행

VM 없이 PHP·MySQL·Nginx와 모의 VMM 프로세스를 실행한다. 고객/관리자 콘솔은 같은 출처의 통합 API를 이용한다. 실제 VM·VMM·청구·외부 알림·Production은 포함하지 않는다.

## 실행

프로젝트 루트에서 실행한다. 첫 준비는 공식 배포물·잠금 의존성을 받으므로 인터넷 연결이 필요하며, 이후 포털 실행은 로컬 자산을 사용한다.

```powershell
python tools/bootstrap-runtime.py
pwsh -NoProfile -File tools/start-dev.ps1
```

- [고객 콘솔](http://127.0.0.1:18080/) · [별칭 customer.localhost](http://customer.localhost:18080/)
- [관리자 콘솔](http://admin.localhost:18080/)

초기 계정은 관리자와 테스트 회원이며 자격 증명은 Git에서 제외한 `.runtime/login.txt`에 둔다. 자동 생성한 테스트 비밀번호·DB 비밀번호·프록시/모의 서비스 키를 공유 문서에 복사하지 않는다. 계정 준비 재실행은 동일 계정을 반환한다.

이미 실행 중이면 먼저 중지한다. 웹·worker와 MySQL은 loopback 주소에만 바인딩한다. 이 개발 경로는 로컬 HTTP이며 사설망 배포의 TLS/mTLS는 E/R 단계에서 검증한다. 외부 접근을 위해 바인딩이나 포트를 임의로 확대하지 않는다.

이름 해석이 안 되면 고객 콘솔은 `http://127.0.0.1:18080/`로 접속한다. 연결 거부가 계속되면 `start-dev.ps1`로 서버 실행을 확인하고 기존 브라우저 탭을 새로고침한다. `localhost`도 고객 콘솔의 명시적 별칭이며 API 프록시는 기존 고객 출처·인증 계약을 유지한다.

중지는 아래 명령이다. 포털·worker·프로젝트 전용 MySQL만 종료하고 데이터는 보존한다.

```powershell
pwsh -NoProfile -File tools/stop-dev.ps1
```

## 기능 확인

Python·PowerShell 7·Node·Chrome이 필요하다. 첫 브라우저 Test 준비는 npm 공식 배포물의 잠금 의존성을 받는다.

```powershell
npm install --prefix .runtime/browser-test --registry=https://registry.npmjs.org playwright@1.63.0 --no-fund --no-audit
```

```powershell
python tools/verify-repository.py
pwsh -NoProfile -File tools/test-unit.ps1
python tools/test-p1.py
pwsh -NoProfile -File tools/run-integration.ps1
python tools/test-e2e.py
node tools/test-browser.cjs
pwsh -NoProfile -File tools/run-integration.ps1 -Stop
```

`test-unit.ps1`과 `run-integration.ps1`은 테스트 schema를 준비한다. 통합 환경은 테스트 계정도 준비하며 개발 설정과 다른 DB·포트 및 단축 지연을 사용한다. 검증은 위 순서로 직렬 실행하고 Test 도중 통합/개발 worker를 같은 테스트 DB에 동시에 연결하지 않는다. Playwright는 기존 Chrome을 headless로 사용한다. 코드 커버리지 측정은 별도 도구가 필요하므로 기능 검사는 `--no-coverage`로 실행한다.

기술 로그는 `.runtime/logs/<service>/events.jsonl`, 감사는 업무 DB의 append-only 원장, UI/HTTP 테스트 원본은 `.runtime/evidence/`에 저장한다. 정제 검증 결과는 [구현·검증 기록](prototype-progress.md)에 남긴다.

## 현재 경계

- 모의 결과는 성공·실패·늦은 완료·통지 누락·중복이며 설정에 지정한 범위에서 추첨 후 저장한다. 조회/재기동으로 재추첨하지 않는다.
- 접수·완료·전원 관찰을 구분하고 누락은 확인 필요로 유지한다. 실습용 SSH 주소를 생성하거나 실제 VM이 있다고 표시하지 않는다.
- 개발 CLI `simulator:resend-missing`은 저장된 원본 누락 통지를 재예약한다. 원래 사건·내용·시도를 보존하며 새 VM 실행을 만들지 않는다. HTTP 관리 기능이나 실제 VMM 재시도가 아니다.
- 사양·한도·지연·가중치는 로컬 테스트 설정이다. 원문 제안 수치를 실제 호스트 가용 자원이나 상용 운영 기준으로 해석하지 않는다.
- 다음 실제 연동은 G1의 별도 실습 PC 자원·제품 사용 권한·네트워크·IIS·MSSQL 조회 대상 확인이 필요하다.
