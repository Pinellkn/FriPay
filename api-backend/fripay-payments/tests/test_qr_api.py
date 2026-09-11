#!/usr/bin/env python3
"""FriPay QR Code API - Full E2E Test Suite"""
import requests
import json
import sys
import io

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

BASE = "http://localhost:8001/api/v1"
TOKEN = "1|fripay_9Ib5GKWRd2pjAjbtci4bGO1lA5fWL4IBA6mrO8vdd6453c29"
HEADERS = {
    "Authorization": f"Bearer {TOKEN}",
    "Content-Type": "application/json",
    "Accept": "application/json",
}

passed = 0
failed = 0

def test(name, expected_status, method, url, **kwargs):
    global passed, failed
    r = getattr(requests, method)(url, **kwargs)
    ok = r.status_code == expected_status
    status = "PASS" if ok else "FAIL"
    if not ok:
        failed += 1
        print(f"  [{status}] {name}: expected {expected_status}, got {r.status_code}")
        try:
            print(f"     Response: {json.dumps(r.json(), indent=2)[:300]}")
        except:
            print(f"     Response: {r.text[:300]}")
    else:
        passed += 1
        print(f"  [{status}] {name}")
    return r

print("=" * 50)
print("  FriPay QR Code - API Test Suite")
print("=" * 50)
print()

# -- Test 1: Generate --
print("--- 1. GENERATION ---")
r = test("POST /qr/generate (5000 XOF)", 201, "post",
         f"{BASE}/qr/generate", headers=HEADERS,
         json={"amount": 5000, "currency": "XOF", "expires_minutes": 30})
data = r.json()
qr_code = data["qr_code"]
uuid = data["uuid"]
print(f"     UUID: {uuid}")
print(f"     Amount: {data['amount']} {data['currency']}")
print(f"     QR size: {len(qr_code)} bytes")
print()

# -- Test 2: Verify (sans auth - hors-ligne) --
print("--- 2. VERIFICATION (sans auth) ---")
r = test("POST /qr/verify (signature valide)", 200, "post",
         f"{BASE}/qr/verify", headers={"Content-Type": "application/json", "Accept": "application/json"},
         json={"qr_content": qr_code})
vdata = r.json()
print(f"     Valid: {vdata['valid']}")
print(f"     Status: {vdata['status']}")
print(f"     Amount: {vdata['amount']}")
print()

# -- Test 2b: Verify tampered QR --
print("--- 2b. VERIFICATION QR FALSIFIE ---")
tampered = qr_code.replace("5000", "99999")
r = test("POST /qr/verify (QR falsifie -> rejete)", 422, "post",
         f"{BASE}/qr/verify", headers={"Content-Type": "application/json", "Accept": "application/json"},
         json={"qr_content": tampered})
tdata = r.json()
print(f"     Valid: {tdata.get('valid', 'N/A')}")
print(f"     Error: {tdata.get('error', 'N/A')}")
print()

# -- Test 3: Status --
print("--- 3. STATUT ---")
r = test(f"GET /qr/.../status", 200, "get",
         f"{BASE}/qr/{uuid}/status", headers=HEADERS)
sdata = r.json()
print(f"     Status: {sdata['status']}")
print(f"     Events: {len(sdata['events'])}")
for e in sdata['events']:
    print(f"       - {e['type']} at {e['timestamp']}")
print()

# -- Test 4: Revoke --
print("--- 4. REVOCATION ---")
r = test("POST /qr/revoke", 200, "post",
         f"{BASE}/qr/revoke", headers=HEADERS,
         json={"uuid": uuid})
rdata = r.json()
print(f"     Message: {rdata.get('message', 'N/A')}")
print(f"     Refund: {rdata.get('refund', 'N/A')}")

# Verify revoked status
r2 = test("GET /qr/.../status (apres revocation)", 200, "get",
          f"{BASE}/qr/{uuid}/status", headers=HEADERS)
print(f"     Status: {r2.json()['status']}")
print()

# -- Test 5: Generate another QR for receive test --
print("--- 5. GENERATION + VALIDATION SELF-TRANSFER ---")
r = test("POST /qr/generate (1000 XOF)", 201, "post",
         f"{BASE}/qr/generate", headers=HEADERS,
         json={"amount": 1000, "expires_minutes": 120})
data2 = r.json()
uuid2 = data2["uuid"]
qr2 = data2["qr_code"]
print(f"     UUID: {uuid2}")

# Self-transfer should fail
r = test("POST /qr/receive (self-transfer -> echec)", 422, "post",
         f"{BASE}/qr/receive", headers=HEADERS,
         json={"qr_content": qr2})
print(f"     Error: {r.json().get('error', 'N/A')}")
print()

# -- Test 6: Unauthorized access --
print("--- 6. TEST SANS AUTH ---")
r = test("POST /qr/generate (sans auth -> 401)", 401, "post",
         f"{BASE}/qr/generate", headers={"Content-Type": "application/json", "Accept": "application/json"},
         json={"amount": 1000})
print()

# -- Test 7: Validation errors --
print("--- 7. VALIDATION ---")
r = test("POST /qr/generate (amount=0 -> 422)", 422, "post",
         f"{BASE}/qr/generate", headers=HEADERS,
         json={"amount": 0})
print()

r = test("POST /qr/generate (amount=99999999 -> 422)", 422, "post",
         f"{BASE}/qr/generate", headers=HEADERS,
         json={"amount": 99999999})
print()

r = test("GET /qr/00000000.../status (not found -> 404)", 404, "get",
         f"{BASE}/qr/00000000-0000-0000-0000-000000000000/status", headers=HEADERS)
print()

# -- Summary --
print("=" * 50)
total = passed + failed
if failed == 0:
    print(f"  ALL TESTS PASSED ({passed}/{total})")
else:
    print(f"  {passed}/{total} PASSED, {failed} FAILED")
print("=" * 50)
sys.exit(0 if failed == 0 else 1)
