# 클라우드 관리포털·Linux IaaS 통합 구축 계획

> 상태: 전체 실행 계획 초안. UI 목업과 [첫 동작 결과 계획](first-working-slice.md)은 작성했으며 포털·인프라 구현과 검증은 아직 시작하지 않았다.

## 프로젝트의 완료 목표

하나의 PHP·CodeIgniter 포털에서 **회원의 Hyper-V VM 신청·관리·월별 실습 청구**와 **관리자의 Linux 인프라 관찰·제어**를 제공한다. 화면의 작업 상태를 실제 Hyper-V·Linux 결과와 대조하고, 실패·복구와 한계를 재현 가능한 자료로 남긴다.

- 포털 업무 데이터: **MySQL**. Linux 단일 DB·Galera 실습 데이터: **MariaDB**. 두 용도를 처음에는 분리한다.
- 역할: **회원·관리자** 두 가지. 공개 회원가입은 없고 관리자가 테스트 회원을 생성·관리한다.
- 회원 대상 상품: **VM 한 가지**. 별도 스토리지·로드밸런서·방화벽 상품은 만들지 않는다.
- 가정용 실습 서비스는 허용된 사설 10대역에서만 접근한다. 정확한 서브넷은 환경 확인 때 정하고 공인 노출·포트 포워딩은 하지 않는다.
- 물리 호스트는 한 대다. VM·서비스 노드 장애 실험을 물리 호스트 장애 내성으로 표현하지 않는다.

## 저장소 경계

| 저장소 | 담당 |
| --- | --- |
| 이 저장소 | CodeIgniter 회원·관리자 포털, MySQL 스키마·API 계약, 작업 상태·청구·알림, Linux 실습 구성·제어 계약과 검증 자료 |
| [windows-private-cloud-lab](https://github.com/wnwjdals7498/windows-private-cloud-lab) | Hyper-V 호스트 환경 점검, PowerShell VM 실행 절차와 실제 호스트 검증 |
| [node-hosting-and-appbuild](https://github.com/wnwjdals7498/node-hosting-and-appbuild) | 검증된 **VM 포털 공통 기능·스키마·API·시나리오**의 Node 비교 구현. Linux 제어 UI는 현재 비교 범위 밖 |

VM 생성·조작의 PowerShell 구현은 Windows 저장소 한곳에서 관리하고, 이 저장소는 요청·권한·결과 계약을 관리한다. 같은 기능을 두 저장소에 복제하지 않는다.

## 단계별 작업과 통과 기준

| 단계 | 작업 범위 | 다음 단계로 넘어갈 증거 |
| --- | --- | --- |
| **0. 환경·계약** | 별도 64GB Windows 11 Pro 호스트의 CPU·RAM·디스크·Hyper-V·네트워크 확인. PHP·CodeIgniter·MySQL·Rocky Linux 지원 버전 선정. 회원/관리자 권한, VM·작업·사양 이력·청구·인프라 제어 계약과 공개 증거 형식 결정 | 민감정보를 제거한 환경표, 사용 가능한 VM 자원, 데이터·API·상태 전이 초안 |
| **1. 첫 동작 결과** | [첫 동작 결과 계획](first-working-slice.md)대로 수동 Rocky 이미지 준비 → 고정 사양 VM 생성 자동화 → 회원 신청·상태 조회 연결 | **회원 신청으로 새 Rocky Linux VM 한 대가 부팅**하고 포털 기록·Hyper-V 실제 상태가 일치 |
| **2. VM 전체 동작** | 허용된 CPU·메모리·디스크·네트워크 사양으로 VM 신청을 확장. 회원은 본인 VM, 관리자는 전체 VM의 시작·중지·재시작·삭제·사양 변경. 변경의 전원 상태별 가능 범위를 먼저 확인. 작업 실패·재시도·실제 상태 재조회와 관리자 회원 관리 화면 | 두 회원 간 소유권 차단, 신청 사양과 다섯 동작의 포털·Hyper-V 결과 일치, 중복 요청과 부분 실패 복구 기록 |
| **3. 사용량·청구** | 실제 생성·삭제 확인 시점과 실제 적용 사양 이력으로 사용 구간 생성. 가상 단가 버전 관리, 매월 1일 전월 청구서 발행, 회원·관리자 조회 | 월 경계·사양 변경·삭제·재실행 사례의 계산 근거가 일치하고 동일 월 청구서가 중복 발행되지 않음 |
| **4. Linux 고가용성** | 회원 VM과 구분한 실습 노드에서 단일 MariaDB → Galera 데이터 노드 2개와 검증된 정족수 구성. 호스트 자원 확인 뒤 노드 중지·재합류·동시 작업·네트워크 분리 시험. Nginx·Keepalived 접근 경로와 장애 전환 시험 | 어느 한 데이터 노드 중지 시 서비스 지속, 재합류·데이터 일관성 및 접근 경로 동작 기록. 단일 물리 호스트 한계 명시 |
| **5. VPC·인프라 제어** | 두 시험 네트워크의 분리·연결을 관찰 가능하게 구현. 관리자 포털에서 허용된 VPC·Linux 제어 작업 요청, 실제 결과 재조회, 실패 표시 | 분리 때 차단·연결 때 허용되는 실제 통신과 포털 상태 일치. 무허가·실패 제어 작업 차단 |
| **6. 알림·결제 결정** | Slack 알림, 임시 메일 서버와 허용 주소만 이용한 이메일 시험. SMS 시험 환경을 조사해 구현 여부 결정. Toss Payments 공식 테스트 환경을 확인해 테스트 PG 또는 자체 청구서 상태 관리 선택 | 실제/실패 알림 전달 기록, 조건부 기능 결정 근거. 실제 금전 결제 없음 |
| **7. 통합 검증·공개** | 권한 우회·입력·CSRF·작업 중복·비밀정보 노출·오류 복구 검토. UI 가이드 대조, 설치·재현 절차와 안전한 증거 정리 | 문서·코드·화면의 상태가 실제 결과와 같고 민감정보 없이 재현 가능 |

[Linux 세부 계획](linux-iaas-ha.md)은 4~5단계의 노드·정족수·네트워크 시험 절차를 다룬다. [UI 가이드](ui/guide.md)와 [HTML 목업](ui/mockup.html)은 화면 방향이며 예시 수치가 실제 결과는 아니다.

## 단계 사이의 핵심 규칙

- VM 신청 뒤 관리자 승인 대기는 없다. 신청 요청은 즉시 작업으로 등록되며 **요청 접수·실행 중·Hyper-V 확인 완료·게스트 확인**을 구별한다.
- 변경·삭제·재시도는 요청 ID와 실제 VM ID를 연결한다. 이미 완료된 작업의 재전송이 VM을 중복 생성하거나 청구 기간을 바꾸지 않게 한다.
- 청구 기간은 생성 확인부터 삭제 확인까지이며 전원 정지 시간도 포함한다. 사양 변경 시점을 경계로 구간을 나눠 각 구간의 **실제 적용 사양과 가상 단가**를 쓴다. 원장의 시각은 일관되게 저장하고 화면·월 경계는 Asia/Seoul 기준으로 검증한다.
- Linux Galera와 VPC는 **관리자 운영 실습**이다. 회원에게 VM 이외 상품으로 판매하지 않는다. Galera의 성공을 포털 MySQL의 고가용성으로 표현하지 않는다.
- 웹 계정에 Hyper-V·Linux 관리자 권한을 주지 않는다. 별도 작업자가 허용된 작업만 실행하고 결과를 다시 조회한다. 비밀번호·토큰·내부 주소를 코드·로그·공개 자료에 넣지 않는다.
- Node 구현의 착수 기준은 PHP의 VM 권한·작업·사양 이력·청구 **공통 계약과 검증 시나리오**가 안정된 시점이다. Linux 4~5단계 완료는 Node 착수 조건이 아니다.

## 구현 전에 결정할 항목

| 시점 | 결정 |
| --- | --- |
| 0단계 | 정확한 제품 버전, VM 사양·최대 동시 실행 수, 허용 서브넷·스위치, 실습 서비스 배치, 로그인 방식·작업자 권한, 로그와 증거 형식 |
| 2단계 전 | CPU·메모리·디스크·네트워크 변경 가능 조건, 변경 실패의 원복 범위, 삭제 후 디스크 처리 |
| 3단계 전 | 가상 단가·반올림·청구 상태·월 경계 사례와 사양 변경 시각 판정 |
| 4단계 전 | Galera 정족수·Arbitrator 배치와 동시 수정·삭제 시험 데이터·합격 기준 |
| 5단계 전 | VPC 구현·제어 기술, 최소 제어 동작, 허용 통신 규칙과 복구 방법 |
| 6단계 전 | SMS 시험 환경과 조건부 구현 여부, Toss Payments 테스트 가능 여부 |

## 프로젝트 완료 판정

1. 회원·관리자 권한과 VM 신청, 다섯 VM 동작, 실제 사양 기반 전월 청구가 실습 호스트에서 검증된다.
2. Galera 노드 장애·재합류, Nginx·Keepalived, VPC 분리·연결과 관리자 제어가 실제 환경에서 재현된다.
3. Slack과 제한된 이메일 시험이 끝나고 SMS·테스트 PG의 조건부 결정과 결과가 기록된다.
4. 공개 자료에 회사 원본·실제 자격 증명·내부 주소가 없고, 단일 물리 호스트 등 검증 한계를 명시한다.

실제 결제, VM 외 추가 상품, Podman·Kubernetes, Windows Failover Cluster·VMM·DPM의 환경 미확보 범위는 이 프로젝트의 완료 조건이 아니다.

## 공식 확인 자료

- [CodeIgniter 4 요구사항·MySQL 드라이버](https://codeigniter.com/user_guide/intro/requirements.html)
- [CodeIgniter 4 보안·CSRF](https://www.codeigniter.com/user_guide/libraries/security.html)
- [Microsoft Hyper-V VM 만들기](https://learn.microsoft.com/en-us/windows-server/virtualization/hyper-v/get-started/create-a-virtual-machine-in-hyper-v)
- [Rocky Linux 10 릴리스 정보](https://docs.rockylinux.org/latest/releases/release_notes/10_0/)
- [MariaDB Galera 정족수·복구](https://mariadb.com/docs/galera-cluster/high-availability/understanding-quorum-monitoring-and-recovery)
- [MariaDB Galera 배치 변형](https://mariadb.com/docs/galera-cluster/galera-architecture/galera-cluster-deployment-variants/)
- [nginx HTTP 부하 분산](https://nginx.org/en/docs/http/load_balancing.html)
- [Keepalived 설정](https://www.keepalived.org/documentation/user-guide/configuration-synopsis/)
- [Slack Incoming Webhooks](https://api.slack.com/messaging/webhooks)
- [Toss Payments 테스트 안내](https://docs.tosspayments.com/blog/how-to-test-toss-payments)
