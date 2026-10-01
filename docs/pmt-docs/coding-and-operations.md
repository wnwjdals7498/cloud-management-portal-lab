# 구현 및 운영 규칙

원문: [1차 구현 계획](../implementation-plan-v1.md) 10·11·12절. [참조 안내](README.md).

## 코드와 검증

- 명시적 routes·입력 DTO·응답 schema, strict types·PSR-4·PSR-12·반환 타입을 적용한다.
- Controller는 HTTP 변환, Application은 사용 사례, Domain은 규칙, adapter는 외부 변환을 담당한다.
- SQL 바인딩·migration·고유 제약을 사용하고 트랜잭션 실패를 검사한다. framework/vendor를 직접 수정하지 않는다.
- 인증·소유권·입력 allowlist·출력 escaping을 서버에서 적용한다. 외부 호출에는 timeout·크기 제한·schema·TLS 검증을 둔다.
- 시간·ID·무작위 생성기를 주입한다. 재전송과 새 attempt를 구분하고 무제한 재시도를 금지한다.
- 환경값은 구성 경계에서 검증한다. 비밀은 Git·웹 루트 밖에 보관하고 `.env.example`에는 빈 값·설명만 둔다.
- 변경에 맞춰 권한·멱등·상태·누락/지연·worker 중단·계약을 검증한다. 문서 수정에는 링크·내용 일관성 검사를 수행한다.

## 로그와 원장

| 종류 | 저장 | 기본 보존 제안 |
| --- | --- | --- |
| 기술 로그 | 개발 `.runtime/<service>/logs/`, 운영 `/var/log/cloud-portal/<service>/`, JSON Lines | 개발 14일·200MiB, 운영 30일·1GiB/서비스. 일별 또는 20MiB 회전·압축 |
| Nginx 로그 | 운영 로그 디렉터리의 별도 파일 | 30일·1GiB/서비스 |
| 감사 | MySQL `audit_events`, 추가/조회 권한 | 운영 180일, 별도 관리 계정으로 archive·정리 |
| 작업·통지·청구 | MySQL 원장 | 작업 180일·가상 청구 24개월. 활성/재확인 작업은 보존 |
| 검증 증거 | 민감정보 제거 후 `docs/operations/`·fixture | 원본 운영 로그는 Git에 올리지 않음 |

위 보존·용량은 실습 제안이다. 원장 용량 부족은 경보·접수 제한으로 처리하며 감사·청구를 조용히 유실시키지 않는다.

공통 로그 필드는 UTC 시각·수준·서비스·release·mode·request_id·job_id·attempt·event·duration_ms·error_code다. 비밀번호·쿠키·토큰·개인키·전체 본문·내부 주소는 제외/마스킹한다. Nginx는 query string도 제외한다. 운영 DEBUG는 기본 비활성화하고 파일 재열기·회전·서비스 계정 권한을 검증한다.

## 배포와 복구

버전 잠금·릴리스 디렉터리·health check·이전 코드 전환을 사용한다. DB 변경은 호환 migration을 우선하고 파괴적 변경에는 복원 절차가 필요하다.

DB·설정·고객 VM/VHDX를 별도 매체에 백업하고 실제 복원한다. RPO 24시간·RTO 4시간은 측정 후 확정할 목표다. worker heartbeat·대기 시간·오류·디스크·백업 시각·인증서 만료를 감시한다. Prototype은 화면·로그로 확인하고 외부 알림은 E4에서 연결한다.

Production 완료는 문서 작성만으로 판정하지 않는다. 4+4 재기동·백업 복원·작업 복구·배포 롤백·인증서 갱신 및 운영 부하 기준을 실제 검증한다.
