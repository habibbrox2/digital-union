from playwright.sync_api import sync_playwright

url = "https://lgdhaka.com/member"
screenshot_file = r"D:\xampp-server\lgdhaka\scripts\member_screenshot.png"

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    context = browser.new_context(user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36")
    page = context.new_page()
    page.goto(url, wait_until="networkidle", timeout=60000)
    page.wait_for_timeout(10000)
    page.screenshot(path=screenshot_file, full_page=True)
    print(f"Screenshot saved: {screenshot_file}")
    browser.close()
