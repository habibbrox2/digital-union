import sys
sys.stdout.reconfigure(encoding='utf-8')

with open('templates/chat/settings.twig', 'r', encoding='utf-8') as f:
    content = f.read()

# Find the broken innerHTML lines
idx = content.find('testBtn.innerHTML')
if idx >= 0:
    print("Found at index:", idx)
    print("Raw:", repr(content[idx:idx+150]))

# The sed inserted backslash-escaped quotes that in Twig render become raw quotes
# breaking the JS string. Fix by using single quotes for the outer string.
content = content.replace(
    'testBtn.innerHTML = "<i class=\\"fas fa-spinner fa-spin me-1\\"></i>\u09aa\u09be\u0990\u09be\u099b\u09c7...";',
    "testBtn.innerHTML = '<i class=\"fas fa-spinner fa-spin me-1\"></i>\u09aa\u09be\u0990\u09be\u099b\u09c7...';"
)
content = content.replace(
    'testBtn.innerHTML = "<i class=\\"fas fa-paper-plane me-1\\"></i>\u099f\u09c7\u09b8\u09cd\u099f \u09a8\u09cb\u099f\u09bf\u09ab\u09bf\u0995\u09c7\u09b6\u09a8 \u09aa\u09be\u09a0\u09be\u09a8";',
    "testBtn.innerHTML = '<i class=\"fas fa-paper-plane me-1\"></i>\u099f\u09c7\u09b8\u09cd\u099f \u09a8\u09cb\u099f\u09bf\u09ab\u09bf\u0995\u09c7\u09b6\u09a8 \u09aa\u09be\u09a0\u09be\u09a8';"
)

with open('templates/chat/settings.twig', 'w', encoding='utf-8') as f:
    f.write(content)

print("Fixed!")
