#!/usr/bin/env python3
import json,urllib.request,urllib.error,time,uuid,pathlib,hashlib,argparse
class Api:
    def __init__(self,base): self.base=base;self.token=None
    def request(self,method,path,body=None,headers=None,raw=False):
        h={'Accept':'application/json'};h.update(headers or {})
        if self.token:h['Authorization']='Bearer '+self.token
        if body is not None and not isinstance(body,bytes): body=json.dumps(body).encode();h.setdefault('Content-Type','application/json')
        req=urllib.request.Request(self.base+path,data=body,headers=h,method=method)
        try:
            with urllib.request.urlopen(req,timeout=20) as r: data=r.read();return r.status,data if raw else (json.loads(data) if data else None)
        except urllib.error.HTTPError as e:
            data=e.read()
            try:data=json.loads(data)
            except ValueError:data=data.decode(errors='replace')[:300]
            return e.code,data
    def ok(self,method,path,body=None,headers=None,raw=False,status=None):
        code,data=self.request(method,path,body,headers,raw)
        assert code==status if status else 200<=code<300,(method,path,code,data)
        return data
    def login(self,login,password): self.token=None;self.token=self.ok('POST','/api/login',{'login':login,'password':password})['token']
def multipart(data,name='demo.any'):
    boundary='midterm'+uuid.uuid4().hex
    return (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+data+f'\r\n--{boundary}--\r\n'.encode(),{'Content-Type':'multipart/form-data; boundary='+boundary})
def main():
    p=argparse.ArgumentParser();p.add_argument('--base',default='http://midterm.k8s.orb.local');args=p.parse_args();api=Api(args.base);run=uuid.uuid4().hex[:12]
    api.ok('GET','/health/live');api.ok('GET','/health/ready');contract=api.ok('GET','/api/docs.jsonopenapi',headers={'Accept':'application/vnd.openapi+json'});assert '/api/assignments/{id}/submit' in contract['paths']
    courses={c['name']:c['id'] for c in api.ok('GET','/api/courses')}
    login='smoke-'+run;api.ok('POST','/api/register',{'login':login,'username':'Smoke Student','password':'StudentPass123!'});api.login(login,'StudentPass123!');student=api.ok('GET','/api/me');assert not student['isAdmin']
    cid=courses['Free Computing'];api.ok('POST',f'/api/courses/{cid}/enroll');assign=api.ok('GET',f'/api/courses/{cid}/assignments')[0]
    data=b'Midterm submission: arbitrary format\n';body,h=multipart(data);submission=api.ok('POST',f"/api/assignments/{assign['id']}/submit",body,h);assert submission['sha256']==hashlib.sha256(data).hexdigest()
    assert api.ok('GET',f"/api/submissions/{submission['id']}/download",raw=True)==data
    api.ok('POST',f"/api/assignments/{assign['id']}/submit",body,h,status=409)
    scenarios={'success':('Payment Success','succeeded',1),'retry_once':('Payment Retry Once','succeeded',2),'retry_twice':('Payment Retry Twice','succeeded',3),'failure':('Payment Failure','failed',3),'decline':('Payment Decline','declined',1),'timeout':('Payment Timeout','failed',3)}
    results=[]
    for fixture,(course,status,attempts) in scenarios.items():
        payload=json.loads(pathlib.Path(f'docs/examples/payment-{fixture}.json').read_text());key='smoke-'+run+'-'+fixture
        purchase=api.ok('POST',f"/api/courses/{courses[course]}/purchase",payload,{'Idempotency-Key':key});pid=purchase['id']
        replay=api.ok('POST',f"/api/courses/{courses[course]}/purchase",payload,{'Idempotency-Key':key});assert replay['id']==pid
        start=time.monotonic()
        while time.monotonic()-start<120:
            purchase=api.ok('GET',f'/api/purchases/{pid}')
            if purchase['status'] in ('succeeded','failed','declined'):break
            time.sleep(.5)
        assert purchase['status']==status,(fixture,purchase)
        assert len(purchase['attempts'])==attempts,(fixture,purchase)
        assert 'cvv' not in json.dumps(purchase) and '990000' not in json.dumps(purchase)
        results.append({'fixture':fixture,'purchaseId':pid,'status':status,'attempts':attempts,'elapsedSeconds':round(time.monotonic()-start,3)})
    api.login('other','StudentPass123!');api.ok('GET',f"/api/submissions/{submission['id']}",status=404);api.ok('GET',f'/api/purchases/{pid}',status=404)
    api.login('admin','AdminPass123!');grade=api.ok('POST','/api/grades',{'assignmentId':assign['id'],'userId':student['id'],'grade':90,'comment':'Verified local demo'})
    api.ok('PATCH',f"/api/grades/{grade['id']}",{'grade':93},{'Content-Type':'application/merge-patch+json'})
    report=api.ok('GET',f'/api/courses/{cid}/report',raw=True);assert report.startswith(b'%PDF-')
    pathlib.Path('output/reports').mkdir(parents=True,exist_ok=True);pathlib.Path('output/reports/deployed-course-report.pdf').write_bytes(report)
    path=pathlib.Path('output/evaluation/smoke.json');path.parent.mkdir(parents=True,exist_ok=True);path.write_text(json.dumps({'base':args.base,'utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'payments':results,'fileChecksumVerified':True,'ownershipVerified':True,'pdfBytes':len(report)},indent=2)+'\n');print(path.read_text())
if __name__=='__main__':main()
