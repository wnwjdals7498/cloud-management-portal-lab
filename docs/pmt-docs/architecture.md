# 구조

원문: [1차 구현 계획](../implementation-plan-v1.md) 2·4·5·9절. [참조 안내](README.md).

## 구성과 소유 범위

| 경로·구성 | 기능 |
| --- | --- |
| `apps/customer-console/` | CI4 View·Bootstrap 5.3·브라우저 JS, 회원 화면과 API 호출 |
| `apps/admin-console/` | 같은 구성의 별도 앱, 관리자 화면과 API 호출 |
| `apps/api/` | CI4·Shield·MySQL, 인증·업무 규칙·작업·감사·CLI worker |
| `apps/vmm-simulator/` | 별도 CI4 앱·CLI dispatcher·모의 DB, 응답 추첨·상태·통지 |
| `packages/ui/` | 공통 템플릿·자산·문구 |
| `contracts/`, `fixtures/simulator/` | API/schema와 시나리오·동작 예시 |
| `config/examples/`, `deploy/`, `tools/` | 비밀 없는 설정 예·환경별 배포·검증 도구 |

PHP 8.4·Composer 2·MySQL 8.4 LTS를 1차 선정했다. 정확한 패치는 G0 호환성 검사 후 manifest·lock에 고정한다. 전체 생성 예정 tree와 공식 근거는 원문을 따른다. 현재 문서의 경로 표는 코드가 존재한다는 뜻이 아니다.

## API 모듈

- Identity: Shield 계정·그룹·권한·세션.
- Vm: 소유권·사양·VM 식별자·실제 관찰 상태.
- Jobs: 작업·시도·상태 전이·outbox·callback inbox.
- Audit: 행위자·대상·허용/거부·상태 변경 원장.
- Integrations: `VmCommandGateway`·`VmStatusReader`·`CompletionReceiver`의 모의/실제 변환.

모듈 내부는 필요한 만큼 `Http/`, `Application/`, `Domain/`, `Persistence/`로 나눈다. API 내부는 PHP 메서드·DTO로 연결하며 외부 통신은 adapter에서만 수행한다. 콘솔은 업무 DB에 접근하지 않고, 모의 앱은 API 업무 테이블을 수정하지 않는다.

## 확장

Prototype에서는 위 모듈과 모의 adapter를 구현한다. 실제 VMM·SQL은 인터페이스만 정의하고 구현은 E 단계에 추가한다. Billing(E3), Infrastructure·Notifications(E4)는 해당 단계에 모듈을 추가한다.

모의/실제 교체는 설정의 adapter 조립으로 수행한다. DB·인증서·작업 ID namespace를 분리하고 모의 VM을 실제 자원으로 이관하지 않는다. 새 동작은 입력·권한·상태·adapter·계약 검증을 함께 추가한다. VMM/Hyper-V 실행 코드는 `windows-private-cloud-lab`에서 관리한다.
