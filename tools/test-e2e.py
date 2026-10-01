"""Five outcomes over dedicated test API/simulator HTTP boundaries."""
from __future__ import annotations
import json
import os
import pathlib
import runpy
import subprocess
import time
import uuid

ROOT=pathlib.Path(__file__).resolve().parents[1]
MODULE=runpy.run_path(str(ROOT/'tools/test-p1.py'))
ENV=os.environ.copy()
ENV['PORTAL_CONFIG']=str(ROOT/'.runtime/integration.json')
PHP=str(ROOT/'.runtime/tools/php/php.exe')


def cli(app, command):
    result=subprocess.run([PHP,str(ROOT/'tools/portal-cli.php'),app,command],cwd=ROOT,env=ENV,text=True,capture_output=True)
    if result.returncode:
        raise AssertionError(f'CLI failed: {app}/{command}; details suppressed')
    return result.stdout


class TestClient(MODULE['Client']):
    def __init__(self,host='customer.localhost'):
        super().__init__(host)
        self.host=host+':18081'
    def request(self,method,path,payload=None,csrf=True,extra_headers=None):
        # Parent client appends the default development port; replace only the test origin.
        original=self.opener.open
        def test_open(request,*args,**kwargs):
            if hasattr(request,'full_url'):
                request.full_url=request.full_url.replace(':18081:18080',':18081')
            return original(request,*args,**kwargs)
        self.opener.open=test_open
        try:return super().request(method,path,payload,csrf,extra_headers)
        finally:self.opener.open=original


def main():
    cli('api','seed')
    prepared=subprocess.run([PHP,str(ROOT/'tools/integration-state.php'),'capacity'],cwd=ROOT,env=ENV,capture_output=True)
    assert prepared.returncode==0,'Test capacity preparation failed'
    client=TestClient();client.login('membera@example.test')
    report=[]
    for scenario in ['success','failure','late','missing','duplicate']:
        key='http-e2e-'+uuid.uuid4().hex
        headers={'Idempotency-Key':key}
        status,receipt=client.request('POST','/api/v1/vms',{'profile_id':'sim-basic-1'},extra_headers=headers)
        assert status==202, f'{scenario}: submission status={status}'
        job=receipt['job_id']
        repeated,replay=client.request('POST','/api/v1/vms',{'profile_id':'sim-basic-1'},extra_headers=headers)
        assert repeated==202 and replay['job_id']==job, f'{scenario}: replay did not preserve original job'
        command=subprocess.run([PHP,str(ROOT/'tools/integration-state.php'),'command',job],cwd=ROOT,env=ENV,capture_output=True)
        assert command.returncode==0,'Test command lookup failed'
        forced=subprocess.run([PHP,'spark','simulator:force',scenario],input=command.stdout,cwd=ROOT/'apps/vmm-simulator',env=ENV,capture_output=True)
        assert forced.returncode==0, f'{scenario}: forced development branch failed'
        cli('api','tick')
        if scenario=='late':
            time.sleep(1.15);cli('api','tick')
            status,view=client.request('GET','/api/v1/jobs/'+job)
            assert view['job']['state']=='reconciliation_required','Late completion was not marked for reconciliation'
            time.sleep(1.05)
        cli('vmm-simulator','tick');cli('api','tick')
        if scenario=='missing':time.sleep(1.15);cli('api','tick')
        status,view=client.request('GET','/api/v1/jobs/'+job)
        state=view['job']['state']
        expected='failed' if scenario=='failure' else ('reconciliation_required' if scenario=='missing' else 'succeeded')
        assert status==200 and state==expected, f'{scenario}: expected {expected}, observed {state}'
        report.append({'scenario':scenario,'expected':expected,'observed':state,'result':'pass'})
        if scenario=='missing':
            external=view['job']['attempts'][0]['external_job_id']
            def resend():
                result=subprocess.run([PHP,'spark','simulator:resend-missing',external],cwd=ROOT/'apps/vmm-simulator',env=ENV,capture_output=True)
                assert result.returncode==0,'Missing completion recovery scheduling failed'
            resend();resend()
            cli('vmm-simulator','tick');cli('api','tick')
            status,recovered=client.request('GET','/api/v1/jobs/'+job)
            assert status==200 and recovered['job']['state']=='succeeded','Original missing completion did not recover the existing job'
            assert len(recovered['job']['attempts'])==1 and recovered['job']['attempts'][0]['external_job_id']==external,'Recovery created another external attempt'
            resend();cli('vmm-simulator','tick');cli('api','tick')
            status,repeated=client.request('GET','/api/v1/jobs/'+job)
            assert status==200 and repeated['job']['state']=='succeeded','Recovery replay changed the terminal job'
            report.append({'scenario':'missing_recovery','expected':'succeeded with original attempt','observed':'succeeded with original attempt','result':'pass'})
    out=ROOT/'.runtime/evidence';out.mkdir(exist_ok=True)
    (out/'http-five-scenarios.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    print('Five HTTP scenarios and missing completion recovery passed; replay remained single-effect')


if __name__=='__main__':main()
