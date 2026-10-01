# 작업별 참조 문서

기준: [1차 구현 계획](../implementation-plan-v1.md), 2026-10-01. 이 폴더는 계획을 빠르게 찾아 적용하기 위한 요약이며 PMT 엔진의 데이터 루트가 아니다.

## 읽는 순서

[AGENTS.md](../../AGENTS.md) → 이 안내 → 아래 작업별 문서 → 구현 계획의 해당 절 순서로 읽는다. 전체 계획을 이미 확인했다면 변경된 부분만 다시 확인한다.

| 작업 | 참조 문서 | 원문 절 |
| --- | --- | --- |
| 필요한 기능·담당·최초 적용 단계 | [기능목록](feature-list.md) | 전체 범위 추출 |
| 기능별 목표·입출력 의미·Test·확인 로그 | [기능 명세서](function-specification.md) | 해당 기능의 원문·참조 |
| 기능별 구현 범위·동작·의존·완료 증거 | [구현 상세계획](implementation-details/README.md) · [ID별 찾기](implementation-details/feature-map.md) | 기능 명세·원문 구체화 |
| Luna 병렬 인계·공유 계약·통합 순서 | [병렬 실행](implementation-details/parallel-execution.md) | 상세계획 통합 경계 |
| 착수·자원·범위·완료 판정 | [단계와 확인 항목](stages.md) | 1·3·12 |
| 현재 실행·중지·검증 결과 | [로컬 실행](../operations/local-prototype.md) · [검증 기록](../operations/prototype-progress.md) | G0·P0~P4 실행 증거 |
| 프레임워크·폴더·모듈·확장 | [구조](architecture.md) | 2·4·5·9 |
| API·인증·데이터·작업 흐름 | [통신과 작업 계약](contracts.md) | 6·8 |
| 무작위 응답·재현·예외 시나리오 | [VMM 모의 연동](vmm-simulator.md) | 7·8 |
| 코딩·검증·로그·배포·복구 | [구현 및 운영 규칙](coding-and-operations.md) | 10·11·12 |

## 원천과 갱신

- 사용자 확정: VM 없는 지정 범위 무작위 VMM 응답(D64), 사설망에서 지속 운영하는 실습 서비스(D63).
- 프레임워크·확률·지연·보존 기간·복구 목표는 1차 계획의 선정안/제안이다. 문서 연결 작업만으로 확정 상태를 바꾸지 않는다.
- 계획을 변경하면 관련 참조 문서도 같은 작업에서 수정한다. 사용자 확정 결정은 PMT 결정 이력과 명세에 반영한다.
- 기능 변경 시 목록과 명세의 동일 ID를 함께 갱신한다. 명세의 input/output은 각 값의 의미로 작성하며 직접 예제 값은 넣지 않는다.
- 구현 범위가 바뀌면 해당 ID의 상세계획과 의존/공유 계약도 함께 갱신한다. 파일 편집 여부를 기능 완료로 판정하지 않는다.
- 문서 충돌은 최신 사용자 지시·확정 결정을 기준으로 원문부터 바로잡는다. 새 결정과 실제 검증이 없으면 완료로 기록하지 않는다.

## PMT 연결

이 공유 작업공간의 PMT 원천은 저장소 기준 `../docs/projects/cloud-management-portal-lab/`, 엔진의 `--docs-root`는 `../docs`다. 저장소 안의 `docs/pmt-docs`로 원천을 옮기지 않는다.

- [재개 요약](../../../docs/projects/cloud-management-portal-lab/RESUME.md): PMT `resume`으로 갱신한 뒤 현재 Item 확인.
- [명세](../../../docs/projects/cloud-management-portal-lab/resources/derived/specification.md): 확정 결정과 요구사항 기준.
- [구조 검토표](../../../docs/projects/architecture-review.md): Windows·Linux 배치와 기존 결정 연결.

위 세 링크는 저장소 밖 공유 작업공간을 가리킨다. 단독 clone에서 원천이 없으면 저장소의 계획·참조 문서로 내용을 확인하고, PMT 기록을 읽거나 갱신했다고 보고하지 않는다. PMT가 있는 환경에서는 동일 session으로 `resume → start → note/verify → end`를 수행한다.

범위 요약은 [단계별 범위](../implementation-scope.md), 화면 기준은 [UI 가이드](../ui/guide.md)를 참조한다.
