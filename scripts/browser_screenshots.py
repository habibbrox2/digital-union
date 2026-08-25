from playwright.sync_api import sync_playwright

pages = [
    ('chairman', 'http://lgdhaka.local/chairman'),
    ('secretary', 'http://lgdhaka.local/secretary'),
    ('computer_operator', 'http://lgdhaka.local/computer_operator'),
    ('member', 'http://lgdhaka.local/member'),
    ('village_police', 'http://lgdhaka.local/village_police'),
    ('udc', 'http://lgdhaka.local/udc'),
]

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    context = browser.new_context(user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36")
    page = context.new_page()
    
    for slug, url in pages:
        print(f"Testing: {url}")
        
        try:
            page.goto(url, wait_until="networkidle", timeout=30000)
            page.wait_for_timeout(3000)
            
            person_cards = page.query_selector_all('.person-card')
            union_headers = page.query_selector_all('.union-header')
            
            print(f"  Person cards: {len(person_cards)}")
            print(f"  Union headers: {len(union_headers)}")
            
            if len(person_cards) > 0:
                print(f"  Status: OK")
                screenshot_path = f"D:\\xampp-server\\lgdhaka\\scripts\\{slug}_screenshot.png"
                page.screenshot(path=screenshot_path, full_page=False)
                print(f"  Screenshot: {screenshot_path}")
            else:
                print(f"  Status: FAIL - No data")
                
        except Exception as e:
            print(f"  Error: {e}")
    
    browser.close()
