# 기능별 상세계획 찾아보기

[상세계획 안내](README.md) · [병렬 인계·통합](parallel-execution.md) · [기능목록](../feature-list.md) · [기능 명세](../function-specification.md)

아래 단계는 최초 적용 시점이다. 계획 작성 완료와 코드 구현·Test 완료를 구분한다. 각 링크는 목적·변경범위·goal/non-goal·입출력 의미·동작·의존·Test·로그·반환 증거를 가리킨다.

| 기능 ID | 기능 | 적용 단계 | 상세계획 작성 담당 |
| --- | --- | --- | --- |
| [FS-001](foundation-infrastructure-operations.md#fs-001) | 실행환경·설정·자원 게이트 확인 | P0 / G1·G2 | Luna C |
| [FS-002](foundation-infrastructure-operations.md#fs-002) | 의존성 잠금·실행환경 명세 | P0 / R1 | Luna C |
| [FS-003](identity-console-billing-notifications.md#fs-003) | 초기 관리자·회원 계정 준비 | P1 | Luna A |
| [FS-004](identity-console-billing-notifications.md#fs-004) | 로그인·로그아웃·현재 사용자 | P1 | Luna A |
| [FS-005](identity-console-billing-notifications.md#fs-005) | CSRF 준비·검증 | P1 | Luna A |
| [FS-006](identity-console-billing-notifications.md#fs-006) | 역할·소유권 검사 | P1 | Luna A |
| [FS-007](identity-console-billing-notifications.md#fs-007) | 고객·관리자 개요 화면 | P4 / E3·E4 | Luna A |
| [FS-008](identity-console-billing-notifications.md#fs-008) | VM 목록·상세 조회 | P4 | Luna A |
| [FS-009](identity-console-billing-notifications.md#fs-009) | 작업 기록·상세 조회 | P4 | Luna A |
| [FS-010](identity-console-billing-notifications.md#fs-010) | VM 사양 조회·관리 | P2 / E2 | Luna A |
| [FS-011](identity-console-billing-notifications.md#fs-011) | 관리자 회원 관리 | E2 | Luna A |
| [FS-012](jobs-vmm-vm.md#fs-012) | VM 생성 신청·작업 등록 | P2 / E1 | Luna B |
| [FS-013](jobs-vmm-vm.md#fs-013) | 요청 중복 방지 | P2 | Luna B |
| [FS-014](jobs-vmm-vm.md#fs-014) | outbox 점유·외부 전달 | P3 | Luna B |
| [FS-015](jobs-vmm-vm.md#fs-015) | 작업 상태 전이·결과 반영 | P3 | Luna B |
| [FS-016](jobs-vmm-vm.md#fs-016) | 완료 통지 인증·수신 검증 | P3 / E1 | Luna B |
| [FS-017](jobs-vmm-vm.md#fs-017) | 선행·중복·충돌 통지 처리 | P3 | Luna B |
| [FS-018](jobs-vmm-vm.md#fs-018) | 외부 상태 관찰·동기화 조회 | P3 / E1 | Luna B |
| [FS-019](jobs-vmm-vm.md#fs-019) | 지연·누락 재확인·명시적 재시도 | P3 / E1·E2 | Luna B |
| [FS-020](jobs-vmm-vm.md#fs-020) | worker 중단·재기동 복구 | P3 | Luna B |
| [FS-021](jobs-vmm-vm.md#fs-021) | 모의 응답 범위 설정 검증 | P0·P3 | Luna B |
| [FS-022](jobs-vmm-vm.md#fs-022) | 무작위 추첨·저장·재현 | P3 | Luna B |
| [FS-023](jobs-vmm-vm.md#fs-023) | 모의 상태 저장·예정 통지 실행 | P3 | Luna B |
| [FS-024](jobs-vmm-vm.md#fs-024) | 성공·실패·지연·누락·중복 시나리오 | P3 | Luna B |
| [FS-025](foundation-infrastructure-operations.md#fs-025) | 모의·실제 실행환경 분리 | P0·P3 / R1 | Luna C |
| [FS-026](foundation-infrastructure-operations.md#fs-026) | 감사 원장 기록·확인 | P1 기반 / P2 업무 연계 | Luna C |
| [FS-027](foundation-infrastructure-operations.md#fs-027) | 기술 로그·추적·민감정보 제거 | P0~P4 | Luna C |
| [FS-028](jobs-vmm-vm.md#fs-028) | 실제 VMM 요청·결과 adapter | E1 | Luna B |
| [FS-029](jobs-vmm-vm.md#fs-029) | 실제 VM 부팅·SSH 접근 확인 | E1 | Luna B |
| [FS-030](jobs-vmm-vm.md#fs-030) | VM 시작 | E2 | Luna B |
| [FS-031](jobs-vmm-vm.md#fs-031) | VM 중지 | E2 | Luna B |
| [FS-032](jobs-vmm-vm.md#fs-032) | VM 재시작 | E2 | Luna B |
| [FS-033](jobs-vmm-vm.md#fs-033) | VM 삭제·잔여 디스크 처리 | E2 | Luna B |
| [FS-034](jobs-vmm-vm.md#fs-034) | VM 사양 변경·적용 이력 | E2 | Luna B |
| [FS-035](identity-console-billing-notifications.md#fs-035) | 가상 단가·반올림 버전 관리 | E3 | Luna A |
| [FS-036](identity-console-billing-notifications.md#fs-036) | 실제 적용 사양별 사용 구간 | E3 | Luna A |
| [FS-037](identity-console-billing-notifications.md#fs-037) | 전월 청구서 발행·중복 방지 | E3 | Luna A |
| [FS-038](identity-console-billing-notifications.md#fs-038) | 청구서 조회·상태 관리 | E3 | Luna A |
| [FS-039](foundation-infrastructure-operations.md#fs-039) | Windows 인프라 상태·검증 기록 | E0·E4 | Luna C |
| [FS-040](foundation-infrastructure-operations.md#fs-040) | 인프라·Linux 별도 검증·보존 축소 | E0 | Luna C |
| [FS-041](foundation-infrastructure-operations.md#fs-041) | Linux·DB·접근 경로 상태 관찰 | E0·E4 | Luna C |
| [FS-042](foundation-infrastructure-operations.md#fs-042) | 허용 Linux 제어·장애 복구 확인 | E4 | Luna C |
| [FS-043](foundation-infrastructure-operations.md#fs-043) | VPC 분리·연결·제어 | E4 | Luna C |
| [FS-044](identity-console-billing-notifications.md#fs-044) | Slack 알림 | E4 | Luna A |
| [FS-045](identity-console-billing-notifications.md#fs-045) | 제한 이메일 시험 | E4 | Luna A |
| [FS-046](identity-console-billing-notifications.md#fs-046) | SMS 시험 가능성·조건부 연동 | E4·조건부 | Luna A |
| [FS-047](identity-console-billing-notifications.md#fs-047) | 테스트 PG 가능성·조건부 연동 | E4·조건부 | Luna A |
| [FS-048](foundation-infrastructure-operations.md#fs-048) | 로그 회전·보존·원장 정리 | R2 | Luna C |
| [FS-049](foundation-infrastructure-operations.md#fs-049) | 서비스·작업 감시·경보 | R2 | Luna C |
| [FS-050](foundation-infrastructure-operations.md#fs-050) | 인증서 갱신·비밀정보 권한 관리 | R1·R2 | Luna C |
| [FS-051](foundation-infrastructure-operations.md#fs-051) | 재부팅 후 서비스 기동·가동 대수 | R1 | Luna C |
| [FS-052](foundation-infrastructure-operations.md#fs-052) | DB·설정·고객 VM 백업 | E 기본 수단 / R2 | Luna C |
| [FS-053](foundation-infrastructure-operations.md#fs-053) | DB·고객 VM 복원·복구 검증 | R2 | Luna C |
| [FS-054](foundation-infrastructure-operations.md#fs-054) | 릴리스 배포·롤백 | R1·R2 | Luna C |
| [FS-055](foundation-infrastructure-operations.md#fs-055) | 운영 부하·용량·한도 확인 | G1 / R3 | Luna C |
| [FS-056](foundation-infrastructure-operations.md#fs-056) | 기능검증 증거·공개자료 정리 | P4 / E·R | Luna C |
| [FS-057](foundation-infrastructure-operations.md#fs-057) | DB 스키마 구성·마이그레이션 | P1 / E·R | Luna C |
| [FS-058](identity-console-billing-notifications.md#fs-058) | 콘솔 API 프록시·신뢰 헤더 | P1 / R1 | Luna A |
