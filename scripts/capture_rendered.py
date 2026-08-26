from playwright.sync_api import sync_playwright

url = "https://lgdhaka.com/member"
output_file = r"D:\xampp-server\lgdhaka\scripts\member_rendered.html"

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    context = browser.new_context(user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36")
    page = context.new_page()
    page.goto(url, wait_until="networkidle", timeout=60000)
    page.wait_for_timeout(8000)
    
    html = page.content()
    with open(output_file, "w", encoding="utf-8") as f:
        f.write(html)
    
    print(f"Saved rendered HTML: {len(html)} chars")
    
    tables = page.query_selector_all("table")
    print(f"Found {len(tables)} tables")
    
    for i, table in enumerate(tables):
        rows = table.query_selector_all("tr")
        print(f"Table {i+1}: {len(rows)} rows")
        if len(rows) > 0:
            print(f"  First row text: {rows[0].inner_text()[:200]}")
    
    browser.close()
