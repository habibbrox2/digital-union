import urllib.request
import re

pages = [
    'chairman',
    'secretary',
    'computer_operator',
    'member',
    'village_police',
    'udc',
]

for slug in pages:
    url = f'http://lgdhaka.local/{slug}'
    print(f"\nTesting: {url}")
    
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req, timeout=10) as response:
            html = response.read().decode('utf-8')
        
        person_cards = len(re.findall(r'person-card', html))
        union_headers = len(re.findall(r'union-header', html))
        person_names = len(re.findall(r'person-details.*?<h4>(.*?)</h4>', html, re.DOTALL))
        
        print(f"  Person cards: {person_cards}")
        print(f"  Union headers: {union_headers}")
        print(f"  Person names: {person_names}")
        
        if person_cards > 0:
            print(f"  Status: OK - Data is showing")
        else:
            print(f"  Status: FAIL - No data found")
            
    except Exception as e:
        print(f"  Error: {e}")
