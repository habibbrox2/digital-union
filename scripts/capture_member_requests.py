from playwright.sync_api import sync_playwright
import json
import sys

url = "https://lgdhaka.com/member"
output_file = r"D:\xampp-server\lgdhaka\scripts\member_requests.txt"

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    context = browser.new_context(user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36")
    page = context.new_page()
    
    requests = []
    
    def handle_request(request):
        if "member" in request.url.lower() or "api" in request.url.lower() or "data" in request.url.lower():
            requests.append(f"REQUEST: {request.method} {request.url}")
    
    def handle_response(response):
        if "member" in response.url.lower() or "api" in response.url.lower() or "data" in response.url.lower():
            try:
                ct = response.headers.get("content-type", "")
                if "json" in ct or "javascript" in ct:
                    body = response.text()[:500]
                    requests.append(f"RESPONSE {response.status}: {response.url}\n{body}\n")
            except Exception:
                pass
    
    page.on("request", handle_request)
    page.on("response", handle_response)
    
    page.goto(url, wait_until="networkidle", timeout=60000)
    page.wait_for_timeout(5000)
    
    with open(output_file, "w", encoding="utf-8") as f:
        f.write("\n".join(requests))
    
    print(f"Captured {len(requests)} requests/responses")
    print("\n".join(requests[:20]))
    
    browser.close()
