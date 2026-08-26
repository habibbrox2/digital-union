from playwright.sync_api import sync_playwright
import time

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
        print(f"\n{'='*60}")
        print(f"Testing: {url}")
        print('='*60)
        
        try:
            page.goto(url, wait_until="networkidle", timeout=30000)
            page.wait_for_timeout(3000)
            
            content = page.content()
            
            person_cards = page.query_selector_all('.person-card')
            union_headers = page.query_selector_all('.union-header')
            person_images = page.query_selector_all('.person-image')
            person_names = page.query_selector_all('.person-details h4')
            designations = page.query_selector_all('.designation')
            
            print(f"[OK] Page loaded successfully")
            print(f"  - Person cards: {len(person_cards)}")
            print(f"  - Union headers: {len(union_headers)}")
            print(f"  - Person images: {len(person_images)}")
            print(f"  - Person names: {len(person_names)}")
            print(f"  - Designations: {len(designations)}")
            
            if len(person_cards) > 0:
                print(f"\n  First union: {union_headers[0].inner_text()[:50] if union_headers else 'N/A'}")
                print(f"  First person: {person_names[0].inner_text()[:50] if person_names else 'N/A'}")
                print(f"  First designation: {designations[0].inner_text()[:50] if designations else 'N/A'}")
                screenshot_path = f"D:\\xampp-server\\lgdhaka\\scripts\\{slug}_screenshot.png"
                page.screenshot(path=screenshot_path, full_page=False)
                print(f"  Screenshot saved: {screenshot_path}")
            else:
                print(f"  [FAIL] No person cards found!")
                screenshot_path = f"D:\\xampp-server\\lgdhaka\\scripts\\{slug}_error.png"
                page.screenshot(path=screenshot_path, full_page=True)
                print(f"  Error screenshot saved: {screenshot_path}")
                
        except Exception as e:
            print(f"[ERROR] Error loading page: {e}")
    
    browser.close()
    
print("\n" + "="*60)
print("Browser testing completed!")
print("="*60)
