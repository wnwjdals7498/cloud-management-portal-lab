# cloud-management-portal-lab

> 상태: 계획 및 UI 목업 단계. 포털·인프라 구현과 장애 검증 전.

PHP·CodeIgniter 기반의 관리포털과 Linux IaaS·고가용성 실습을 한 저장소에서 다룬다. 포털의 회원·관리자 화면, Hyper-V VM 신청·관리·청구, Rocky Linux 기반 MariaDB Galera·접근 경로·VPC 실습과 제어 기능이 범위다. 실제 인프라 작업은 포털의 요청과 실행 결과를 구분해 기록한다.

포털 업무 데이터는 계획대로 MySQL을 사용한다. Linux 실습의 단일 DB와 Galera는 MariaDB다. 두 데이터의 역할과 장애 범위를 혼동하지 않도록 처음에는 분리한다. 회사 원본 코드·데이터는 사용하지 않는다.

## 문서와 목업

- [통합 구축 계획](docs/plan.md)
- [Linux IaaS·고가용성 세부 계획](docs/linux-iaas-ha.md)
- [첫 동작 결과 세부 계획](docs/first-working-slice.md)
- [포털 UI 가이드](docs/ui/guide.md)
- [포털 HTML 목업](docs/ui/mockup.html)

HTML 목업은 화면 방향을 검토하기 위한 예시이며, 표시된 VM·사용량·작업 이력은 가상 데이터다. 실행 서비스는 허용된 사설망에서만 접근한다. 공개 저장소에는 코드와 안전한 검증 자료만 둔다.
