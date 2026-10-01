# 작업 지침

## 시작과 참조

1. [참조 문서 안내](docs/pmt-docs/README.md)에서 현재 단계와 작업별 문서를 찾는다.
2. [1차 구현 계획](docs/implementation-plan-v1.md)의 해당 절과 참조 문서를 읽고 작업한다.
3. 기능 구현 시 [기능목록](docs/pmt-docs/feature-list.md)과 [기능 명세서](docs/pmt-docs/function-specification.md)의 해당 ID로 목표·입출력·Test·로깅을 확인한다.
4. [기능별 구현 상세계획](docs/pmt-docs/implementation-details/README.md)의 해당 ID와 병렬 인계·통합 계약으로 구현 범위를 지정한다.
5. [현재 구현·검증 기록](docs/operations/prototype-progress.md)으로 실제 완료 범위와 실행 방법을 확인한다. PMT를 사용하는 환경에서는 안내의 원천 경로로 `resume`한 뒤 대상 Item을 `start`한다. 결정·진행·검증은 PMT 절차로 기록한다.

## 기준

- 사용자 지시와 최신 확정 결정을 우선한다. 계획의 선정안·제안 수치를 사용자 확정이나 실측으로 표현하지 않는다.
- `docs/pmt-docs/`는 구현 계획의 작업별 요약이다. 변경 시 원문과 관련 요약을 함께 갱신한다. PMT 상태·결정 원본을 이 폴더에 복제하지 않는다.
- Prototype은 실제 VM 없이 지정 범위의 무작위 VMM 응답으로 구현한다. G0·P0~P4 로컬 검증과 실제 연동 G1·기능 확장·Production을 구분한다. 완료 여부는 기록과 증거로 판단한다.
- 프레임워크·모듈·통신 경계는 [구조](docs/pmt-docs/architecture.md)와 [계약](docs/pmt-docs/contracts.md)을 따른다.
- 재현 가능한 모의 동작은 [VMM 모의 연동](docs/pmt-docs/vmm-simulator.md), 구현·검증·로그는 [구현 및 운영 규칙](docs/pmt-docs/coding-and-operations.md)을 따른다.
- 실제 연동은 G1, 상시 운영은 G2의 확인 항목을 충족한 뒤 진행한다. 미확인 실습 자원을 추정값으로 통과시키지 않는다.
- 과거 `docs/plan.md`·`docs/first-working-slice.md`의 배치·인증 설명을 최신 결정 확인 없이 실행하지 않는다.
- 정보성 근거는 공식 문서만 사용한다. 시장 평가·사람들의 의견을 요청받은 경우에만 비공식 의견을 구분해 사용한다.
- 설명은 간단명료하게 한다. 비밀정보·회사 원본·내부 주소·원본 운영 로그를 공개 문서나 저장소에 넣지 않는다.
- 인계는 목적·추가/수정/삭제 책임·goal/non-goal·입출력 의미·동작·Test·로그·증거로 작성한다. 파일별 편집 명령을 인계 목표나 완료 기준으로 삼지 않는다.
- 병렬 위임 시 공유 계약·schema·상태 변경의 단일 소유와 선행 조건을 먼저 확인한다. 서브에이전트는 자기 책임의 산출물·검증 증거를 반환하고 PMT 원천·공유 통합은 주 에이전트가 관리한다.

## 완료 보고

변경한 내용, 검증 결과, 남은 미확인 항목을 짧게 보고한다. 문서 검증과 실제 서비스 검증을 구분한다.
