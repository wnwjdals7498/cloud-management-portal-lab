# cloud-management-portal-lab

> 상태: VM 없는 Prototype 구현·로컬 검증 완료. 지정 범위의 무작위 VMM 응답을 사용한다. 실제 VMM·VM·기능 확장·Production은 미실행이다.

PHP·CodeIgniter 기반의 관리포털과 Linux IaaS·고가용성 실습을 한 저장소에서 다룬다. 포털의 회원·관리자 화면, Hyper-V VM 신청·관리·청구, Rocky Linux 기반 MariaDB Galera·접근 경로·VPC 실습과 제어 기능이 범위다. 실제 인프라 작업은 포털의 요청과 실행 결과를 구분해 기록한다.

포털 업무 데이터는 계획대로 MySQL을 사용한다. Linux 실습의 단일 DB와 Galera는 MariaDB다. 두 데이터의 역할과 장애 범위를 혼동하지 않도록 처음에는 분리한다. 회사 원본 코드·데이터는 사용하지 않는다.

## 개발환경 구축

현재 준비 스크립트는 **Windows x64 로컬 개발환경**용이다. 검증은 이 PC에서 수행했으며 Codex Cloud에서는 수행하지 않았다. VM 없이 모의 VMM을 실행한다.

### 1. 사전 준비

| 도구 | 용도·준비 |
| --- | --- |
| [Git](https://git-scm.com/install/windows) | 저장소 받기·문서/잠금파일 검사 |
| [Python](https://www.python.org/downloads/windows/) | 공식 배포물 다운로드·검증 도구 실행. 검증한 계열은 3.13 |
| [PowerShell 7](https://learn.microsoft.com/en-us/powershell/scripting/install/installing-powershell-on-windows) | 개발 프로세스 준비·기동·중지. `pwsh` 명령 사용 |
| [Visual C++ Redistributable x64](https://learn.microsoft.com/en-us/cpp/windows/latest-supported-vc-redist) | PHP Windows 실행에 필요한 런타임. [PHP 공식 요구사항](https://www.php.net/downloads.php?os=windows) 참조 |
| [Node.js](https://nodejs.org/en/download)·[Chrome](https://www.google.com/chrome/) | 브라우저 자동 검증 시 준비. 검증한 Node 계열은 24 |

첫 준비에는 공식 배포물·Composer 의존성을 받을 인터넷 연결과 압축/해제·DB·캐시를 저장할 디스크 공간이 필요하다. PHP·Composer·MySQL·Nginx는 아래 절차가 프로젝트의 `.runtime/tools/`에 준비한다.

PowerShell에서 저장소 루트로 이동하고 명령 인식을 확인한다. 다른 위치에 저장소를 받았다면 첫 경로를 변경한다.

```powershell
Set-Location 'D:\workspace\test-space\github\cloud-management-portal-lab'
git --version
python --version
pwsh --version
```

### 2. 도구 준비·최초 실행

```powershell
python tools/bootstrap-runtime.py
pwsh -NoProfile -File tools/start-dev.ps1
```

`bootstrap-runtime.py`는 [runtime manifest](runtime-manifest.json)의 PHP·Composer·MySQL·Nginx 배포물을 받고 체크섬을 검사한다. `start-dev.ps1`은 잠금파일대로 앱 의존성을 설치하고, 로컬 DB·schema·초기 계정을 준비한 뒤 웹·API·모의 VMM·worker를 시작한다. PHP 확장과 CA 경로도 자동 설정한다.

| 항목 | 접속·저장 위치 |
| --- | --- |
| 고객 콘솔 | [http://customer.localhost:18080/](http://customer.localhost:18080/) |
| 관리자 콘솔 | [http://admin.localhost:18080/](http://admin.localhost:18080/) |
| 초기 계정·비밀번호 | `.runtime/login.txt`에서 로컬 확인. 관리자 1개·회원 2개를 준비 |
| 개발 설정·키 | `.runtime/local.json`, 각 앱의 `.env` |
| DB 데이터·기술 로그 | `.runtime/mysql-data/`, `.runtime/logs/` |

위 설정·자격 증명·DB·로그는 Git에서 제외한다. 콘솔은 loopback HTTP로 접근하며, 사용 포트는 웹 `18080`, API/모의 VMM/콘솔 내부 `18100`·`18200`·`18300`·`18400`, MySQL `33307`이다. 직접 API 접근은 차단한다.

### 3. 중지·다음 실행

```powershell
pwsh -NoProfile -File tools/stop-dev.ps1
pwsh -NoProfile -File tools/start-dev.ps1
```

중지는 프로젝트 전용 프로세스를 종료하고 DB 데이터를 보존한다. 이미 실행 중인 환경을 다시 시작할 때도 먼저 중지한다. 도구 다운로드는 최초 준비 또는 도구 재준비 때 실행하며, 초기 계정 준비를 반복해도 기존 계정을 유지한다.

### 4. 기능 검증

개발환경을 실행한 상태에서 아래 순서로 검사한다.

```powershell
python tools/verify-repository.py
pwsh -NoProfile -File tools/test-unit.ps1
python tools/test-p1.py
```

브라우저 검증은 Node.js·Chrome을 준비한 뒤 이어서 실행한다. 통합 환경은 별도 테스트 DB와 포트 `18081`·`18101`·`18201`을 사용한다.

```powershell
npm install --prefix .runtime/browser-test --registry=https://registry.npmjs.org playwright@1.63.0 --no-fund --no-audit
pwsh -NoProfile -File tools/run-integration.ps1
python tools/test-e2e.py
node tools/test-browser.cjs
pwsh -NoProfile -File tools/run-integration.ps1 -Stop
```

검사는 직렬 실행한다. 통합 검증 도중 오류가 나면 마지막 중지 명령으로 테스트 서버를 정리한다. 결과·캡처는 `.runtime/evidence/`, 정제된 결과는 [구현·검증 기록](docs/operations/prototype-progress.md)에 있다. 실패 시 로그 위치와 모의 복구 경계는 [로컬 실행 안내](docs/operations/local-prototype.md)를 참조한다.

## 문서와 목업

- [로컬 Prototype 실행·중지·Test](docs/operations/local-prototype.md) · [구현·검증 기록과 남은 범위](docs/operations/prototype-progress.md)
- [에이전트 작업 지침](AGENTS.md) · [작업별 참조 문서](docs/pmt-docs/README.md)
- [기능목록](docs/pmt-docs/feature-list.md) · [기능 명세서: 목표·입출력 의미·Test·로깅](docs/pmt-docs/function-specification.md)
- [기능별 구현 상세계획](docs/pmt-docs/implementation-details/README.md) · [Luna 병렬 인계·통합](docs/pmt-docs/implementation-details/parallel-execution.md)
- [1차 구현 계획: 자원·프레임워크·작업·모듈·통신·로그](docs/implementation-plan-v1.md)
- [Prototype·기능 확장·Production 범위](docs/implementation-scope.md)
- [통합 구축 계획](docs/plan.md)
- [Linux IaaS·고가용성 세부 계획](docs/linux-iaas-ha.md)
- [첫 동작 결과 세부 계획](docs/first-working-slice.md)
- [포털 UI 가이드](docs/ui/guide.md)
- [포털 HTML 목업](docs/ui/mockup.html)

HTML 목업은 화면 방향을 검토하기 위한 예시이며, 표시된 VM·사용량·작업 이력은 가상 데이터다. 실행 서비스는 허용된 사설망에서만 접근한다. 공개 저장소에는 코드와 안전한 검증 자료만 둔다.

기존 통합·첫 동작 계획은 구조 재검토 전 실행 초안을 포함한다. Prototype 작업은 1차 구현 계획을 따른다. 실제 연동은 최신 PMT 결정·구조 검토와 실습 호스트 자원 확인 뒤 진행한다.
