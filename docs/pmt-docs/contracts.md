# 통신과 작업 계약

원문: [1차 구현 계획](../implementation-plan-v1.md) 6·8절의 엔드포인트·메시지·JSON 예시. [참조 안내](README.md).

## 통신 경로

브라우저 → 고객/관리자 콘솔의 `/api/` 프록시 → 통합 API → MySQL.

API worker → 모의/실제 VMM 수신부 → API 완료 통지. 화면 조회 시 API StatusReader → 모의 HTTP 상태/실제 MSSQL.

- 인증·세션·업무 DB는 API가 소유한다. 콘솔별 등록 호스트와 host-only 쿠키, Shield 세션·CSRF를 사용한다.
- 실제 배포는 검증된 HTTPS, API↔VMM은 상호 TLS·등록 출발지 제한을 따른다. 모의 HTTP 예외는 loopback 개발 모드에만 적용한다.
- 실제 MSSQL 조회 대상과 IIS 구현 기술은 G1에서 결정한다. 모의 HTTP 조회로 실제 계약을 변경하지 않는다.

## 요청과 결과

- 신청은 고정 `profile_id`와 `Idempotency-Key`를 받는다. VM ID·이름은 서버가 생성한다.
- `(사용자, 작업종류, 키)`와 본문 해시를 기록한다. 같은 키·본문은 기존 작업, 다른 본문은 409다.
- 접수 응답은 202와 `job_id`, `vm_id`, `status`, `mode`, `request_id`다. 접수를 성공 완료로 표시하지 않는다.
- 완료 메시지의 `event_id`, `job_id`, 외부 작업/VM ID, `attempt`, 결과·관찰 시각·오류·mode를 검증한다. `event_id` 고유 제약으로 중복 반영을 막는다.
- 외부 ID 저장보다 먼저 온 통지는 inbox에 보류한다. 이전 시도·충돌 결과는 격리·감사 기록하고 이미 확정된 상태를 덮어쓰지 않는다.

## 상태와 동작

`queued → dispatching → running → succeeded/failed`. 응답 유실·기한 초과·통지 누락은 `reconciliation_required`로 구분한다. 유효한 늦은 통지는 검증 후 확정할 수 있다.

신청 시 Vm·Jobs·outbox·Audit를 한 트랜잭션으로 저장한다. worker가 lease로 작업을 점유하고 외부 호출 후 결과를 저장한다. 네트워크 호출 중 DB 잠금을 유지하지 않는다. 중단 후에는 동일 외부 멱등 키로 접수 여부를 확인하며 생성 명령을 맹목 재시도하지 않는다.

상태 조회값은 관찰값·시각으로 기록한다. 조회 실패는 마지막 값의 오래됨을 표시하고, callback과 충돌하면 재확인 대상으로 둔다. 누락 callback을 조회값만으로 자동 성공 확정하지 않는다.

업무 데이터·세션·작업·감사는 MySQL, 모의 작업·VM·예정 통지는 별도 모의 DB에 둔다. 원장 시각은 UTC, 화면·청구 월 경계는 Asia/Seoul이다.
