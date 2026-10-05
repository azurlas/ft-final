#!/usr/bin/env python3
"""HTTP/static checks and live API contracts used by the frontend; no browser rendering claim."""
import argparse
import hashlib
import json
import pathlib
import re
import time
import urllib.error
import urllib.request
import uuid
from smoke import Api, multipart


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base', default='http://midterm.k8s.orb.local')
    args = parser.parse_args()
    root = pathlib.Path('output/evaluation')
    root.mkdir(parents=True, exist_ok=True)
    with urllib.request.urlopen(args.base + '/', timeout=20) as response:
        assert response.url.endswith('/ui/courses.html')
        html = response.read().decode()
    assert 'Campus' in html and 'Content-Security-Policy' in html
    assets = set(re.findall(r'(?:src|href)="(/ui/[^"?]+)', html))
    assets.update('/ui/' + name for name in ['index.html', 'courses.html', 'assignments.html', 'submissions.html', 'grades.html', 'purchases.html', 'login.html', 'register.html', 'submit.html', 'checkout.html', 'app.js', 'api.js', 'format.js', 'navigation.js', 'submission.js'])
    asset_report = []
    for path in sorted(assets):
        with urllib.request.urlopen(args.base + path, timeout=20) as response:
            data = response.read()
            assert response.status == 200
            assert response.headers['X-Static-Server'] == 'RoadRunner'
            assert response.headers['X-Content-Type-Options'] == 'nosniff'
            etag = response.headers.get('ETag')
            assert etag
            assert data == pathlib.Path('public' + path).read_bytes(), path
            asset_report.append({'path': path, 'bytes': len(data), 'etag': etag, 'contentType': response.headers['Content-Type']})
        try:
            urllib.request.urlopen(urllib.request.Request(args.base + path, headers={'If-None-Match': etag}), timeout=20)
            raise AssertionError('Expected HTTP 304')
        except urllib.error.HTTPError as error:
            assert error.code == 304
    api = Api(args.base)
    api.ok('GET', '/index.php', status=404)
    api.ok('GET', '/ui/missing.js', status=404)
    api.ok('GET', '/api/docs.html', headers={'Accept': 'text/html'}, raw=True)
    api.ok('GET', '/api/me', status=401)
    tag = uuid.uuid4().hex[:10]
    api.login('admin', 'AdminPass123!')
    free = api.ok('POST', '/api/courses', {'name': 'Frontend free ' + tag, 'price': None})
    paid = api.ok('POST', '/api/courses', {'name': 'Frontend paid ' + tag, 'price': 1250})
    api.ok('PATCH', f"/api/courses/{free['id']}", {'name': 'Frontend course ' + tag}, {'Content-Type': 'application/merge-patch+json'})
    assignment = api.ok('POST', '/api/assignments', {'courseId': free['id'], 'name': 'Frontend assignment', 'description': 'One arbitrary file', 'deadline': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(time.time() + 86400))})
    api.ok('PATCH', f"/api/assignments/{assignment['id']}", {'description': 'Updated through the frontend contract'}, {'Content-Type': 'application/merge-patch+json'})
    login = 'ui-smoke-' + tag
    api.token = None
    api.ok('POST', '/api/register', {'login': login, 'username': 'Frontend Student', 'password': 'StudentPass123!'})
    api.login(login, 'StudentPass123!')
    student = api.ok('GET', '/api/me')
    api.ok('POST', f"/api/courses/{free['id']}/enroll")
    api.ok('GET', f"/api/courses/{free['id']}/assignments")
    content = b'Frontend single file contract\n'
    body, headers = multipart(content, 'frontend.any')
    submission = api.ok('POST', f"/api/assignments/{assignment['id']}/submit", body, headers)
    assert submission['sha256'] == hashlib.sha256(content).hexdigest()
    assert api.ok('GET', f"/api/submissions/{submission['id']}/download", raw=True) == content
    api.ok('POST', f"/api/assignments/{assignment['id']}/submit", body, headers, status=409)
    api.ok('GET', f"/api/courses/{free['id']}/report", raw=True, status=403)
    payment = json.loads(pathlib.Path('docs/examples/payment-retry_twice.json').read_text())
    key = {'Idempotency-Key': 'frontend-' + tag}
    purchase = api.ok('POST', f"/api/courses/{paid['id']}/purchase", payment, key)
    replay = api.ok('POST', f"/api/courses/{paid['id']}/purchase", payment, key)
    assert replay['id'] == purchase['id']
    start = time.monotonic()
    while time.monotonic() - start < 120:
        purchase = api.ok('GET', f"/api/purchases/{purchase['id']}")
        if purchase['status'] in ('succeeded', 'declined', 'failed'):
            break
        time.sleep(.5)
    assert purchase['status'] == 'succeeded' and len(purchase['attempts']) == 3
    api.ok('GET', '/api/grades')
    api.ok('GET', '/api/submissions')
    api.login('admin', 'AdminPass123!')
    grade = api.ok('POST', '/api/grades', {'assignmentId': assignment['id'], 'userId': student['id'], 'grade': 91, 'comment': 'Frontend contract checked'})
    api.ok('PATCH', f"/api/grades/{grade['id']}", {'grade': 95}, {'Content-Type': 'application/merge-patch+json'})
    pdf = api.ok('GET', f"/api/courses/{free['id']}/report", raw=True)
    assert pdf.startswith(b'%PDF-')
    api.ok('DELETE', f"/api/grades/{grade['id']}", status=204)
    api.ok('DELETE', f"/api/assignments/{assignment['id']}", status=204)
    api.ok('DELETE', f"/api/courses/{free['id']}", status=204)
    api.ok('DELETE', f"/api/courses/{paid['id']}", status=204)
    report = {'base': args.base, 'utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'assets': asset_report, 'rootRedirect': '/ui/courses.html', 'etagConditionalRequests': '304', 'phpSourceBlocked': True, 'missingAssetNotRewritten': True, 'studentAndAdminContracts': 'passed', 'checkoutIdempotency': True, 'twoPaymentRetries': True, 'purchaseId': purchase['id'], 'fileChecksumVerified': True, 'pdfBytes': len(pdf), 'interactiveBrowserVerified': False}
    (root / 'frontend.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
