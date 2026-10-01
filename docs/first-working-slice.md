# 첫 동작 결과: VM 신청부터 Rocky Linux 부팅까지 · 실행 초안

> 2026-10-01: 이 문서는 기능 확장에서 수행할 첫 **실제** VM 결과의 이전 초안이다. VM 없는 무작위 모의 VMM Prototype은 [1차 구현 계획](implementation-plan-v1.md)의 P0~P4를 따른다.

> 전체 구조 재검토 중. 이 절차는 [구조 재검토 기준표](../../docs/projects/architecture-review.md)의 전체 배치 확인 전 실행하지 않는다.

> 최신 인프라 8→4대와 Linux 8→4대 구조를 반영해 순서를 다시 작성할 예정이다. 두 8대 구성을 동시에 시험하지 않으며, 각 검증 뒤 추가 VM을 종료·자동 부팅 제한한다. Linux VM은 물리 Hyper-V에 직접 둔다. 아래의 기존 포털 배치와 단계는 재작성 전 초안이다.

> 상태: 구현 전 계획. 실습 호스트는 사용자가 확인한 **별도 64GB Windows 11 Pro PC**다. 첫 회원 신청 전 Windows Server 2022 중첩 Hyper-V 컴퓨트 노드의 단독 시험과 VMM 편입을 완료한다. 실제 가용 자원·네트워크·제품 지원성과 라이선스는 입력 게이트에서 확인한다.

## 이번 단계의 완료 장면

관리자가 만든 테스트 회원이 PHP·CodeIgniter 포털에서 **고정된 VM 사양 하나**를 신청한다. 승인 대기 없이 작업이 등록되고, 포털이 VMM/SQL Server VM의 IIS API에 HTTPS cURL 요청을 보낸다. IIS는 공식 VMM PowerShell 명령으로 Windows Server 2022 컴퓨트 노드의 중첩 Hyper-V에 Rocky Linux 10 VM 한 대를 만든다. 접수·등록 응답은 즉시 반환한다. PHP는 VMM SQL Server DB를 조회해 생성 완료를 반영하고 컴퓨트 노드의 실제 VM 상태와 게스트 부팅을 대조한다. 직접 DB 조회의 공식 계약을 확인하지 못하면 IIS가 공식 VMM 조회 명령 결과를 포털에 전달하는 방식으로 대체한다.

수동으로 설치한 Rocky Linux VM은 이미지 준비용이다. 첫 결과로 보여줄 VM은 **회원 신청으로 새로 만들어진 별도 VM**이다. 준비용 VM은 동시에 실행할 필요가 없다.

## 포함할 범위

- 단일 Windows 11 Pro Hyper-V 물리 호스트의 자원·가상 스위치·저장공간·중첩 가상화·CPU 호환성 확인
- Windows Server 2022 컴퓨트 노드 VM에 Hyper-V 설치, Rocky Linux 10 VM 수동 설치·부팅과 재사용 이미지 준비
- 컴퓨트 노드의 PowerShell VM 생성·삭제 단독 시험
- 별도 AD VM, VMM·SQL Server 통합 VM, 컴퓨트 노드 VMM 편입과 IIS 수신부 이전
- 한 가지 고정 VM 사양과 한 가지 허용된 네트워크 구성
- 관리자 1명과 테스트 회원 2명, 공개 가입 없는 로그인과 소유자 권한 검사
- 회원의 VM 신청, 작업 ID 발급, 대기·실행 중·성공·실패 상태 조회
- 처음에는 컴퓨트 노드에서 IIS 수신부를 시험하고, 회원 신청 전 VMM VM으로 옮겨 실제 VM 상태를 재조회
- 중복 신청, 작업 실패, 재실행 시 중복 VM 생성 방지와 안전한 정리

## 이번 단계에서 제외

VM 시작·중지·재시작·삭제·사양 변경의 **포털 기능**, 가상 청구, 결제, Slack·이메일·SMS, Galera, Nginx·Keepalived, VPC, Node 재구현, 다중 호스트 장애 내성은 다음 단계로 둔다. 테스트 후 VM 정리는 운영자가 기록을 남기고 수동으로 한다. 이 범위는 전체 프로젝트 완료 기준을 축소하는 것이 아니다.

## 첫 구현 구조

```text
테스트 회원 브라우저
    → CodeIgniter 포털 (신청·권한·상태 조회)
    → MySQL의 VM 요청/작업 기록
    → VMM/SQL Server VM의 IIS API로 서버 측 HTTPS cURL 요청
    → 공식 VMM PowerShell 명령 → Windows Server 2022 컴퓨트 노드의 Hyper-V 작업
    → PHP의 VMM SQL Server DB 생성 상태 조회
    → 컴퓨트 노드 실제 VM 상태 재조회 → 작업 결과 저장 → 포털 표시
```

- 이전 계획은 포털·MySQL을 물리 Windows 11 Pro OS에 두었다. 컴퓨트 노드, AD, VMM/SQL Server VM의 계층과 포털 배치는 전체 구조 재검토에서 확정한다. IIS는 컴퓨트 노드에서 단독 시험한 뒤 VMM VM으로 이전하고 첫 회원 신청은 그 뒤 진행한다. 단계별 IP는 실제 환경에서 지정한다.
- IIS 수신부가 임의 PowerShell 명령을 받지 않고 미리 정한 작업만 실행한다. 포털→IIS 요청은 HTTPS를 강제하고 등록한 사설망 고정 출발지 IP만 API에 허용한다. 자체 사설 CA가 IIS 서버 인증서를 발급하고, PHP 호출 환경이 CA를 신뢰하며 인증서에 지정 호스트 IP를 포함한다. CA 개인키는 실습 호스트 밖에 오프라인 보관한다. 실제 출발지 IP·인증서 배포/갱신과 포털 웹 계정·IIS 실행 계정·Hyper-V 권한의 경계는 구현 전에 정한다. IIS 수신부 기술은 공식 지원성·호스트 확인 뒤 사용자에게 선택지를 제시한다.
- 인증은 CodeIgniter 4의 공식 Shield를 우선 사용한다. 세션 로그인과 관리자·회원 그룹을 두고 공개 가입을 끈다. 설치할 정확한 버전과 계정 생성 절차는 환경 게이트에서 검증한다.
- 브라우저는 VM 이름·저장 경로·스위치 이름·PowerShell 명령을 보내지 못한다. 서버가 고정 사양과 허용 목록에서 값을 선택하고 VM 식별자를 생성한다.
- 작업자는 고정된 스크립트와 검증된 입력만 사용한다. 웹 요청에서 임의 명령 문자열을 조립하지 않는다.
- 포털의 `작업 성공`은 즉시 접수 응답만으로 결정하지 않는다. 생성 완료는 VMM DB 조회 결과를 사용하되 컴퓨트 노드 Hyper-V에서 VM의 존재·ID·상태·사양을 다시 대조해 기록한다. Rocky Linux 게스트 부팅은 별도 확인 결과로 표시한다. 다른 VM 동작은 지연 콜백을 사용한다.

## 저장소별 산출물

- `cloud-management-portal-lab`: CodeIgniter 앱, MySQL 마이그레이션, IIS 호출부, 신청·상태 계약과 검증 시나리오
- `windows-private-cloud-lab`: Hyper-V 환경 점검, 수동 VM 재현 절차, IIS 수신부, 고정 입력을 받는 PowerShell 자동화와 실제 호스트 결과 기록

VM 생성 명령은 Windows 저장소의 고정된 절차 한 곳에서 관리하고, 포털 저장소는 요청 계약과 실행 결과를 관리한다.

## 순서와 산출물

| 단계 | 할 일 | 끝났다는 증거 |
| --- | --- | --- |
| 0. 환경 게이트 | 물리 호스트 중첩 가상화 지원, 64GB 자원 예산, 허용 사설망·스위치, Windows Server 2022·VMM·SQL Server 라이선스와 공식 지원성을 확인한다. 제품 버전과 첫 VM 사양을 기록한다. | 민감정보를 뺀 환경표, 세 VM 역할의 자원 예산과 실행 가능/불가 판정 |
| 1. 컴퓨트 노드 | Windows Server 2022 VM에 가상화 확장을 노출하고 Hyper-V를 설치한다. 중첩 게스트 네트워크 방식은 공식 자료와 호스트 확인 뒤 상세히 설명해 사용자 선택을 받고 구성한다. | 물리 호스트→컴퓨트 노드의 중첩 Hyper-V 설치·네트워크 증거 |
| 2. 수동 Rocky VM·이미지 | 컴퓨트 노드에서 공식 Rocky ISO·체크섬 확인, 수동 설치·부팅, 재사용 이미지 준비와 독립 복제 부팅을 검증한다. | ISO 검증, 중첩 VM 부팅, 이미지 재사용 기록 |
| 3. 노드 단독 자동화 | 컴퓨트 노드 PowerShell에서 고정 사양 VM 생성·조회·삭제를 시험한다. IIS 수신부를 노드에 시험 설치하고 중복·실패·잔여 VHDX를 점검한다. | 요청 결과와 컴퓨트 노드 `Get-VM` 값 대조, 삭제 후 자원 확인 |
| 4. AD·VMM 구축 | Windows Server 2022 AD VM과 Windows Server 2022 VMM 2022·SQL Server 2022 통합 VM을 만들고 컴퓨트 노드를 편입한다. 업데이트·사용 권한 확인 뒤 IIS를 VMM VM으로 이전한다. | 도메인 가입, VMM의 노드 인식, IIS 이전 뒤 허용 IP·HTTPS 재검증 |
| 5. 포털 최소 기능 | CodeIgniter 4와 MySQL을 구성하고 관리자·회원 인증, 신청·작업 상태 화면을 만든다. 요청·VM·작업 상태를 마이그레이션으로 관리한다. | 관리자/회원 로그인, 권한 거부, 신청 ID와 상태 기록 |
| 6. 연결·검증 | VMM 편입 뒤 포털 cURL과 VMM VM IIS를 연결한다. IIS의 공식 VMM PowerShell 작업, 생성의 VMM DB 조회, 컴퓨트 노드 재조회로 정상·중복·실패를 검증한다. | 회원 신청 → 중첩 Rocky VM 생성·부팅 → 포털·VMM DB·컴퓨트 노드 상태 대조 |

## 요청·상태의 최소 계약

- 회원 신청은 로그인과 CSRF 검사를 거쳐 **고정 프로필 ID**만 받는다. 한 회원의 활성 테스트 VM 수는 처음에는 1대로 제한한다.
- 신청 화면을 보여줄 때 서버가 중복 방지 키를 발급한다. 제출 시 이 키에 고유 제약을 두고 요청 ID를 생성한다. 같은 제출이 반복돼도 같은 요청에 대응하며 VM을 두 번 만들지 않는다.
- 작업 상태는 `queued → running → succeeded/failed`로 기록한다. 즉시 응답은 접수·등록 상태만 뜻한다. 생성 완료는 VMM DB 조회와 실제 VM 대조 뒤 반영하며, VM의 전원 상태와 Rocky 게스트 부팅 확인은 별도 값이다.
- 회원은 자기 요청·VM만, 관리자는 전체 요청·VM을 볼 수 있다. 화면에서 감춘 것과 별개로 서버에서 소유권을 검사한다.
- 기록에는 요청 ID, 서버 생성 VM 식별자, 작업 단계·시각·결과·오류 코드만 남긴다. 비밀번호·토큰·내부 IP·전체 명령행은 공개 자료와 로그에서 제외한다.

## 필수 검증 시나리오

1. 회원이 신청하면 같은 HTTP 요청에서 작업 ID가 발급되고 승인 대기 없이 작업을 시작한다. 포털→IIS 전달 시각과 PowerShell 시작 시각의 실제 지연을 측정한다.
2. 작업 완료 후 VMM에 편입된 컴퓨트 노드 Hyper-V의 VM ID·CPU·메모리·디스크·전원 상태와 포털 기록을 대조하고 Rocky Linux 10 부팅을 확인한다.
3. 같은 신청을 반복 전송해도 새 VM이 중복 생성되지 않는다.
4. 다른 회원 계정은 이 VM의 요청·상태를 읽거나 조작할 수 없다.
5. 준비 이미지 누락 등 통제된 실패를 만들면 `failed`와 오류 코드가 표시되고, 재실행·수동 정리 범위가 기록된다. 성공으로 잘못 표시되거나 VM·디스크가 방치되지 않는다.
6. 허용 사설망 밖에서 포털에 접근할 수 없고, 저장소·공개 증거에 비밀정보가 없다.

## 착수 전 확정할 값

확인된 실습 호스트는 현재 접속된 PC와 다른 64GB Windows 11 Pro PC다. 포털·MySQL을 물리 OS에 둔다는 이전 배치는 전체 구조에서 재확인한다. Windows Server 2022 컴퓨트 노드에서 VM 생성·삭제를 먼저 시험하고, 별도 AD VM과 VMM·SQL Server 통합 VM을 만든 뒤 노드를 VMM에 편입한다. IIS는 컴퓨트 노드에 시험 설치한 뒤 VMM VM으로 이전한다. **첫 회원 신청은 편입 후** 실행한다. 앞서 선택한 IIS 고정 IP·HTTPS, 사설 CA, VMM 공식 PowerShell, 생성의 VMM DB 조회·다른 작업 콜백은 전체 배치가 확정된 뒤 연결을 다시 검증한다. 정확한 VM 사양·자원 예산·제품 버전·라이선스·IP는 환경 게이트에서 확인한다. 이미지 복제 방식은 AI가 공식 자료·호스트 시험으로 정한다.

## 공식 확인 자료

- [Microsoft: Windows Hyper-V 요구사항](https://learn.microsoft.com/en-us/windows-server/virtualization/hyper-v/host-hardware-requirements)
- [Microsoft: VM 만들기와 Hyper-V 관리자 권한](https://learn.microsoft.com/en-us/windows-server/virtualization/hyper-v/get-started/create-a-virtual-machine-in-hyper-v)
- [Microsoft: PowerShell로 Hyper-V 작업](https://learn.microsoft.com/en-us/windows-server/virtualization/hyper-v/powershell)
- [Microsoft: Hyper-V 중첩 가상화](https://learn.microsoft.com/en-us/windows-server/virtualization/hyper-v/enable-nested-virtualization)
- [Microsoft: VMM 설치 계획](https://learn.microsoft.com/en-us/system-center/vmm/plan-install?view=sc-vmm-2025)
- [Microsoft: Gen 2 Linux Secure Boot 설정](https://learn.microsoft.com/en-us/windows-server/virtualization/hyper-v/learn-more/Generation-2-virtual-machine-security-settings-for-Hyper-V)
- [Rocky Linux 10 릴리스 정보·CPU 요구사항](https://docs.rockylinux.org/latest/releases/release_notes/10_0/)
- [Rocky Linux 10 설치·ISO 체크섬 확인](https://docs.rockylinux.org/guides/installation/)
- [CodeIgniter 4 서버 요구사항](https://codeigniter.com/user_guide/intro/requirements.html)
- [CodeIgniter 4 데이터베이스 마이그레이션](https://codeigniter.com/user_guide/dbmgmt/migration.html)
- [CodeIgniter 4 CLI 명령](https://codeigniter.com/user_guide/cli/cli_commands.html)
- [CodeIgniter Shield 공식 인증 안내](https://codeigniter.com/user_guide/extending/authentication.html)
- [CodeIgniter 4 CSRF 보호](https://www.codeigniter.com/user_guide/libraries/security.html)
