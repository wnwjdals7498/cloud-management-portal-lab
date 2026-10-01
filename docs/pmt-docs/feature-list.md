# 기능목록

> 2026-10-01 · 추출 기준: [AGENTS.md](../../AGENTS.md), [참조 안내](README.md), [1차 구현 계획](../implementation-plan-v1.md). 구현·검증 완료 목록이 아니다.

각 ID의 목표·입출력 의미·Test·로깅은 [기능 명세서](function-specification.md)에 있다. 단계는 최초 적용 시점이며 이후 단계에서도 유지한다. 운영 절차는 웹 기능이나 신규 자동 구축 API로 확대 해석하지 않는다.

구체 구현 범위·동작·의존은 [ID별 상세계획](implementation-details/feature-map.md), 병렬 작업 인계는 [공유 계약·통합 순서](implementation-details/parallel-execution.md)를 따른다.

Prototype 적용 범위·실행 Test·남은 E/R 단계는 [구현·검증 기록](../operations/prototype-progress.md)에 있다. 단계가 겹치는 기능은 P 부분의 검증을 전체 기능 완료로 확대하지 않는다.

| ID | 기능 | 단계 | 담당 |
| --- | --- | --- | --- |
| [FS-001](function-specification.md#fs-001) | 실행환경·설정·자원 게이트 확인 | P0 / G1·G2 | 환경 점검 절차 |
| [FS-002](function-specification.md#fs-002) | 의존성 잠금·실행환경 명세 | P0 / R1 | 배포 도구 |
| [FS-003](function-specification.md#fs-003) | 초기 관리자·회원 계정 준비 | P1 | Identity·CLI |
| [FS-004](function-specification.md#fs-004) | 로그인·로그아웃·현재 사용자 | P1 | Identity |
| [FS-005](function-specification.md#fs-005) | CSRF 준비·검증 | P1 | Identity·Filter |
| [FS-006](function-specification.md#fs-006) | 역할·소유권 검사 | P1 | Identity·Vm·각 업무 모듈 |
| [FS-007](function-specification.md#fs-007) | 고객·관리자 개요 화면 | P4 / E3·E4 | 콘솔 |
| [FS-008](function-specification.md#fs-008) | VM 목록·상세 조회 | P4 | Vm·콘솔 |
| [FS-009](function-specification.md#fs-009) | 작업 기록·상세 조회 | P4 | Jobs·콘솔 |
| [FS-010](function-specification.md#fs-010) | VM 사양 조회·관리 | P2 / E2 | Vm |
| [FS-011](function-specification.md#fs-011) | 관리자 회원 관리 | E2 | Identity |
| [FS-012](function-specification.md#fs-012) | VM 생성 신청·작업 등록 | P2 / E1 | Vm·Jobs |
| [FS-013](function-specification.md#fs-013) | 요청 중복 방지 | P2 | Jobs·Persistence |
| [FS-014](function-specification.md#fs-014) | outbox 점유·외부 전달 | P3 | Jobs·worker |
| [FS-015](function-specification.md#fs-015) | 작업 상태 전이·결과 반영 | P3 | Jobs·Vm |
| [FS-016](function-specification.md#fs-016) | 완료 통지 인증·수신 검증 | P3 / E1 | CompletionReceiver |
| [FS-017](function-specification.md#fs-017) | 선행·중복·충돌 통지 처리 | P3 | Jobs·inbox |
| [FS-018](function-specification.md#fs-018) | 외부 상태 관찰·동기화 조회 | P3 / E1 | StatusReader |
| [FS-019](function-specification.md#fs-019) | 지연·누락 재확인·명시적 재시도 | P3 / E1·E2 | Jobs |
| [FS-020](function-specification.md#fs-020) | worker 중단·재기동 복구 | P3 | Jobs·worker |
| [FS-021](function-specification.md#fs-021) | 모의 응답 범위 설정 검증 | P0·P3 | VMM simulator |
| [FS-022](function-specification.md#fs-022) | 무작위 추첨·저장·재현 | P3 | VMM simulator |
| [FS-023](function-specification.md#fs-023) | 모의 상태 저장·예정 통지 실행 | P3 | VMM simulator·dispatcher |
| [FS-024](function-specification.md#fs-024) | 성공·실패·지연·누락·중복 시나리오 | P3 | VMM simulator·개발 CLI |
| [FS-025](function-specification.md#fs-025) | 모의·실제 실행환경 분리 | P0·P3 / R1 | 설정·Integrations |
| [FS-026](function-specification.md#fs-026) | 감사 원장 기록·확인 | P1 기반 / P2 업무 연계 | Audit |
| [FS-027](function-specification.md#fs-027) | 기술 로그·추적·민감정보 제거 | P0~P4 | Logging |
| [FS-028](function-specification.md#fs-028) | 실제 VMM 요청·결과 adapter | E1 | Integrations·Windows 저장소 |
| [FS-029](function-specification.md#fs-029) | 실제 VM 부팅·SSH 접근 확인 | E1 | Vm·콘솔·검증 절차 |
| [FS-030](function-specification.md#fs-030) | VM 시작 | E2 | Vm·Jobs·Integrations |
| [FS-031](function-specification.md#fs-031) | VM 중지 | E2 | Vm·Jobs·Integrations |
| [FS-032](function-specification.md#fs-032) | VM 재시작 | E2 | Vm·Jobs·Integrations |
| [FS-033](function-specification.md#fs-033) | VM 삭제·잔여 디스크 처리 | E2 | Vm·Jobs·Integrations |
| [FS-034](function-specification.md#fs-034) | VM 사양 변경·적용 이력 | E2 | Vm·Jobs |
| [FS-035](function-specification.md#fs-035) | 가상 단가·반올림 버전 관리 | E3 | Billing |
| [FS-036](function-specification.md#fs-036) | 실제 적용 사양별 사용 구간 | E3 | Billing·Vm |
| [FS-037](function-specification.md#fs-037) | 전월 청구서 발행·중복 방지 | E3 | Billing·월별 실행 |
| [FS-038](function-specification.md#fs-038) | 청구서 조회·상태 관리 | E3 | Billing·콘솔 |
| [FS-039](function-specification.md#fs-039) | Windows 인프라 상태·검증 기록 | E0·E4 | Infrastructure·관리자 콘솔 |
| [FS-040](function-specification.md#fs-040) | 인프라·Linux 별도 검증·보존 축소 | E0 | 실습·검증 절차 |
| [FS-041](function-specification.md#fs-041) | Linux·DB·접근 경로 상태 관찰 | E0·E4 | Infrastructure |
| [FS-042](function-specification.md#fs-042) | 허용 Linux 제어·장애 복구 확인 | E4 | Infrastructure·executor |
| [FS-043](function-specification.md#fs-043) | VPC 분리·연결·제어 | E4 | Infrastructure |
| [FS-044](function-specification.md#fs-044) | Slack 알림 | E4 | Notifications |
| [FS-045](function-specification.md#fs-045) | 제한 이메일 시험 | E4 | Notifications |
| [FS-046](function-specification.md#fs-046) | SMS 시험 가능성·조건부 연동 | E4·조건부 | Notifications |
| [FS-047](function-specification.md#fs-047) | 테스트 PG 가능성·조건부 연동 | E4·조건부 | Billing·Integrations |
| [FS-048](function-specification.md#fs-048) | 로그 회전·보존·원장 정리 | R2 | 로그·DB 관리 절차 |
| [FS-049](function-specification.md#fs-049) | 서비스·작업 감시·경보 | R2 | 감시·Notifications |
| [FS-050](function-specification.md#fs-050) | 인증서 갱신·비밀정보 권한 관리 | R1·R2 | 보안 운영 절차 |
| [FS-051](function-specification.md#fs-051) | 재부팅 후 서비스 기동·가동 대수 | R1 | 호스트·서비스 운영 절차 |
| [FS-052](function-specification.md#fs-052) | DB·설정·고객 VM 백업 | E 기본 수단 / R2 | 백업 절차 |
| [FS-053](function-specification.md#fs-053) | DB·고객 VM 복원·복구 검증 | R2 | 복원 절차 |
| [FS-054](function-specification.md#fs-054) | 릴리스 배포·롤백 | R1·R2 | 배포 절차 |
| [FS-055](function-specification.md#fs-055) | 운영 부하·용량·한도 확인 | G1 / R3 | 부하 검증 절차 |
| [FS-056](function-specification.md#fs-056) | 기능검증 증거·공개자료 정리 | P4 / E·R | 검증 도구·문서 |
| [FS-057](function-specification.md#fs-057) | DB 스키마 구성·마이그레이션 | P1 / E·R | Database·배포 절차 |
| [FS-058](function-specification.md#fs-058) | 콘솔 API 프록시·신뢰 헤더 | P1 / R1 | 콘솔 웹 경로·API |

## 원문 대응

| 원문 범위 | 기능 대응 |
| --- | --- |
| 자원·프레임워크·설정·폴더·모듈 경계 | FS-001~002·025·057~058, [구조](architecture.md) |
| 인증·권한·콘솔·회원·사양 | FS-003~011·058 |
| 신청·작업·통지·외부 조회·복구 | FS-012~020 |
| 무작위 모의 응답·5개 시나리오 | FS-021~025 |
| 감사·로깅·코드/검증 규칙 | FS-026~027·048·056~057, [구현 및 운영 규칙](coding-and-operations.md) |
| 실제 연동·VM 전체 동작 | FS-028~034, FS-018의 실제 MSSQL 경로 |
| 가상 청구 | FS-035~038 |
| 인프라·이중화·Galera·접근 경로·VPC | FS-039~043 |
| 알림·조건부 결제 | FS-044~047 |
| Production·복구·운영 한도 | FS-048~055 |

코드 스타일·폴더 규칙은 기존 참조 문서를 적용한다. 기능 목록에 별도 사용자 상품으로 추가하지 않는다. FS-057~058은 검토 중 보완한 공통 기반 기능이며 해당 P 단계에서 먼저 구현한다.

## 범위와 보류

- P 기능은 실제 VM 없이 확인한다. E 기능의 실제 결과는 G1 확인 후 실습 환경에서 검증한다.
- G1의 자원·제품 사용 권한·네트워크·IIS·MSSQL 대상, Linux DB 이중화·Galera·VPC 방식은 기존 결정 절차로 확인한다.
- SMS와 테스트 PG는 가능성 확인 및 결정 기록이 필수이며, 연동 코드는 조건 충족 시 구현한다. 실제 금전 결제·공인 서비스·포털 내부 알림은 추가하지 않는다.
- 새로운 값·정책이 필요한 부분은 명세에서 의미만 정의한다. 수치 확정과 구현 완료는 별도 기록한다.
