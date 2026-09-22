# 첫 동작 결과: VM 신청부터 Rocky Linux 부팅까지 · 실행 초안

> 상태: 구현 전 계획. 실습 호스트는 사용자가 확인한 **별도 64GB Windows 11 Pro PC**다. 해당 PC의 실제 가용 자원·Hyper-V·네트워크를 확인한 뒤 수치·경로·제품 버전을 확정한다.

## 이번 단계의 완료 장면

관리자가 만든 테스트 회원이 PHP·CodeIgniter 포털에서 **고정된 VM 사양 하나**를 신청한다. 승인 대기 없이 작업이 등록되고, 포털이 지정된 Hyper-V 호스트 IP로 cURL 요청을 보낸다. 호스트의 IIS가 받아 지정된 PowerShell 절차로 Rocky Linux 10 VM 한 대를 만든다. 포털의 작업 결과, Hyper-V에서 읽은 VM 상태와 게스트의 Rocky Linux 부팅 결과가 일치한다.

수동으로 설치한 Rocky Linux VM은 이미지 준비용이다. 첫 결과로 보여줄 VM은 **회원 신청으로 새로 만들어진 별도 VM**이다. 준비용 VM은 동시에 실행할 필요가 없다.

## 포함할 범위

- 단일 Windows 11 Pro Hyper-V 호스트의 자원·가상 스위치·저장공간·CPU 호환성 확인
- Rocky Linux 10 계열 VM 수동 설치, 부팅 확인, 재사용 가능한 이미지 준비
- 한 가지 고정 VM 사양과 한 가지 허용된 네트워크 구성
- 관리자 1명과 테스트 회원 2명, 공개 가입 없는 로그인과 소유자 권한 검사
- 회원의 VM 신청, 작업 ID 발급, 대기·실행 중·성공·실패 상태 조회
- Hyper-V 호스트의 IIS 수신부가 고정 PowerShell 작업을 호출하고 실제 VM 상태를 다시 조회
- 중복 신청, 작업 실패, 재실행 시 중복 VM 생성 방지와 안전한 정리

## 이번 단계에서 제외

VM 시작·중지·재시작·삭제·사양 변경의 **포털 기능**, 가상 청구, 결제, Slack·이메일·SMS, Galera, Nginx·Keepalived, VPC, Node 재구현, 다중 호스트 장애 내성은 다음 단계로 둔다. 테스트 후 VM 정리는 운영자가 기록을 남기고 수동으로 한다. 이 범위는 전체 프로젝트 완료 기준을 축소하는 것이 아니다.

## 첫 구현 구조

```text
테스트 회원 브라우저
    → CodeIgniter 포털 (신청·권한·상태 조회)
    → MySQL의 VM 요청/작업 기록
    → 지정된 Hyper-V 호스트 IP로 서버 측 cURL 요청
    → Windows IIS 수신부 → 지정된 PowerShell VM 생성 절차
    → Hyper-V 상태 재조회 → 작업 결과 저장 → 포털 표시
```

- 사용자 결정에 따라 첫 단계의 포털·MySQL·IIS·Hyper-V는 **같은 별도 64GB Windows 실습 호스트**에 둔다. 포털은 그 PC의 지정 IP로 호출한다. 정확한 IP는 사용자가 실제 환경에서 지정한다.
- IIS 수신부가 임의 PowerShell 명령을 받지 않고 미리 정한 작업만 실행한다. 포털→IIS 요청은 HTTPS를 강제한다. 인증서 신뢰·호출 인증과 포털 웹 계정·IIS 실행 계정·Hyper-V 권한의 경계는 구현 전에 정한다.
- 인증은 CodeIgniter 4의 공식 Shield를 우선 사용한다. 세션 로그인과 관리자·회원 그룹을 두고 공개 가입을 끈다. 설치할 정확한 버전과 계정 생성 절차는 환경 게이트에서 검증한다.
- 브라우저는 VM 이름·저장 경로·스위치 이름·PowerShell 명령을 보내지 못한다. 서버가 고정 사양과 허용 목록에서 값을 선택하고 VM 식별자를 생성한다.
- 작업자는 고정된 스크립트와 검증된 입력만 사용한다. 웹 요청에서 임의 명령 문자열을 조립하지 않는다.
- 포털의 `작업 성공`은 작업자가 반환한 값만으로 결정하지 않는다. Hyper-V에서 VM의 존재·ID·상태·사양을 다시 조회해 기록한다. Rocky Linux 게스트 부팅은 별도 확인 결과로 표시한다.

## 저장소별 산출물

- `cloud-management-portal-lab`: CodeIgniter 앱, MySQL 마이그레이션, IIS 호출부, 신청·상태 계약과 검증 시나리오
- `windows-private-cloud-lab`: Hyper-V 환경 점검, 수동 VM 재현 절차, IIS 수신부, 고정 입력을 받는 PowerShell 자동화와 실제 호스트 결과 기록

VM 생성 명령은 Windows 저장소의 고정된 절차 한 곳에서 관리하고, 포털 저장소는 요청 계약과 실행 결과를 관리한다.

## 순서와 산출물

| 단계 | 할 일 | 끝났다는 증거 |
| --- | --- | --- |
| 0. 환경 게이트 | 실제 호스트의 Windows·Hyper-V, CPU의 Rocky Linux 10 호환성, 여유 RAM·디스크, 저장 위치, 허용 사설망과 스위치를 확인한다. PHP·CodeIgniter·MySQL·Rocky Linux 세부 버전과 단일 VM 사양을 기록한다. | 민감정보를 뺀 환경표와 실행 가능/불가 판정 |
| 1. 수동 VM | Rocky Linux 공식 ISO와 체크섬을 확인한다. Hyper-V Manager에서 VM을 수동 생성해 설치·부팅을 확인한다. Gen 2와 Linux용 Secure Boot 설정을 실제 ISO로 검증한다. | VM 사양, ISO 체크섬 확인, 부팅 화면, 게스트 OS 버전, 사용한 스위치 유형 기록 |
| 2. 재사용 이미지 | 수동 VM을 종료하고 이미지 복제·게스트 식별자 초기화 방법을 공식 자료와 실험으로 정한다. 복제본이 독립적으로 부팅하는지 시험한다. | 이미지 준비 절차와 복제 부팅 기록 |
| 3. 호스트 자동화 | 고정 사양·서버 생성 이름으로 VM 생성 스크립트를 만든다. 동일 요청 재실행, 부분 실패, 잔여 VHDX 정리를 검증한다. | PowerShell 실행 결과와 `Get-VM` 관찰 값의 대조표 |
| 4. 포털 최소 기능 | CodeIgniter 4와 MySQL을 구성하고 관리자·회원 인증, 회원의 신청 화면과 작업 상태 화면을 만든다. 요청·VM·작업 상태를 마이그레이션으로 관리한다. | 관리자/회원 로그인, 권한 거부, 신청 ID와 상태 기록 |
| 5. 연결·검증 | 포털의 서버 측 cURL과 Windows IIS 수신부를 연결하고, 고정 PowerShell 절차가 Hyper-V 작업을 수행하게 한다. 정상·중복·실패 시나리오를 실행한다. | 한 번의 회원 신청 → 새 VM 생성 → Rocky 부팅 → 포털/Hyper-V 상태 일치 기록 |

## 요청·상태의 최소 계약

- 회원 신청은 로그인과 CSRF 검사를 거쳐 **고정 프로필 ID**만 받는다. 한 회원의 활성 테스트 VM 수는 처음에는 1대로 제한한다.
- 신청 화면을 보여줄 때 서버가 중복 방지 키를 발급한다. 제출 시 이 키에 고유 제약을 두고 요청 ID를 생성한다. 같은 제출이 반복돼도 같은 요청에 대응하며 VM을 두 번 만들지 않는다.
- 작업 상태는 `queued → running → succeeded/failed`로 기록한다. VM의 실제 전원 상태와 Rocky 게스트 부팅 확인은 별도 값이다.
- 회원은 자기 요청·VM만, 관리자는 전체 요청·VM을 볼 수 있다. 화면에서 감춘 것과 별개로 서버에서 소유권을 검사한다.
- 기록에는 요청 ID, 서버 생성 VM 식별자, 작업 단계·시각·결과·오류 코드만 남긴다. 비밀번호·토큰·내부 IP·전체 명령행은 공개 자료와 로그에서 제외한다.

## 필수 검증 시나리오

1. 회원이 신청하면 같은 HTTP 요청에서 작업 ID가 발급되고 승인 대기 없이 작업을 시작한다. 포털→IIS 전달 시각과 PowerShell 시작 시각의 실제 지연을 측정한다.
2. 작업 완료 후 Hyper-V의 VM ID·CPU·메모리·디스크·전원 상태와 포털 기록을 대조하고 Rocky Linux 10 부팅을 확인한다.
3. 같은 신청을 반복 전송해도 새 VM이 중복 생성되지 않는다.
4. 다른 회원 계정은 이 VM의 요청·상태를 읽거나 조작할 수 없다.
5. 준비 이미지 누락 등 통제된 실패를 만들면 `failed`와 오류 코드가 표시되고, 재실행·수동 정리 범위가 기록된다. 성공으로 잘못 표시되거나 VM·디스크가 방치되지 않는다.
6. 허용 사설망 밖에서 포털에 접근할 수 없고, 저장소·공개 증거에 비밀정보가 없다.

## 착수 전 확정할 값

확인된 실습 호스트는 현재 접속된 PC와 다른 64GB Windows 11 Pro PC다. 첫 포털·MySQL은 그 호스트에 둔다. 사용자 결정에 따라 포털은 지정 Hyper-V IP에 HTTPS cURL로 요청하고 IIS가 고정 PowerShell을 실행한다. Rocky Linux 10 지원 마이너 버전과 CPU 적합성, 고정 VM 사양·최대 동시 실행 수, PHP·CodeIgniter·MySQL 버전과 상태 갱신 간격을 **환경 게이트에서** 결정한다. 이미지 복제 방식은 AI가 공식 자료·호스트 시험으로 정한다. 가상 스위치·정확한 허용 서브넷은 사용자가 지정한다. IIS 인증서 신뢰·호출 인증·실행 계정·상태 조회 계약은 아직 미정이다.

## 공식 확인 자료

- [Microsoft: Windows Hyper-V 요구사항](https://learn.microsoft.com/en-us/windows-server/virtualization/hyper-v/host-hardware-requirements)
- [Microsoft: VM 만들기와 Hyper-V 관리자 권한](https://learn.microsoft.com/en-us/windows-server/virtualization/hyper-v/get-started/create-a-virtual-machine-in-hyper-v)
- [Microsoft: PowerShell로 Hyper-V 작업](https://learn.microsoft.com/en-us/windows-server/virtualization/hyper-v/powershell)
- [Microsoft: Gen 2 Linux Secure Boot 설정](https://learn.microsoft.com/en-us/windows-server/virtualization/hyper-v/learn-more/Generation-2-virtual-machine-security-settings-for-Hyper-V)
- [Rocky Linux 10 릴리스 정보·CPU 요구사항](https://docs.rockylinux.org/latest/releases/release_notes/10_0/)
- [Rocky Linux 10 설치·ISO 체크섬 확인](https://docs.rockylinux.org/guides/installation/)
- [CodeIgniter 4 서버 요구사항](https://codeigniter.com/user_guide/intro/requirements.html)
- [CodeIgniter 4 데이터베이스 마이그레이션](https://codeigniter.com/user_guide/dbmgmt/migration.html)
- [CodeIgniter 4 CLI 명령](https://codeigniter.com/user_guide/cli/cli_commands.html)
- [CodeIgniter Shield 공식 인증 안내](https://codeigniter.com/user_guide/extending/authentication.html)
- [CodeIgniter 4 CSRF 보호](https://www.codeigniter.com/user_guide/libraries/security.html)
