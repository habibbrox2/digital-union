#!/usr/bin/env bash
# test_chat_flow.sh — end-to-end test of the live chat visitor + push API flow.
# Uses curl against the local PHP dev server. Post-requests carry the CSRF token
# extracted from the homepage meta tag (same as csrf.js does).
BASE="http://127.0.0.1:8088"
JAR="$(mktemp)"
PASS=0
FAIL=0

# curl helpers that keep the session cookie so the CSRF token matches the session
CGET() { curl -s --max-time 10 -b "$JAR" -c "$JAR" "$@"; }
CPOST() { curl -s --max-time 10 -b "$JAR" -c "$JAR" "$@"; }
CCODE() { curl -s -o /dev/null -w "%{http_code}" --max-time 10 -b "$JAR" -c "$JAR" "$@"; }

check() {
  local label="$1" expected="$2" actual="$3"
  if [ "$expected" = "$actual" ]; then
    PASS=$((PASS+1)); echo "  PASS  $label"
  else
    FAIL=$((FAIL+1)); echo "  FAIL  $label (expected $expected, got $actual)"
  fi
}

uuid() {
  # Proper RFC-4122 v4 UUID (validates against the server's session_id regex)
  local h=$(cat /dev/urandom | tr -dc 'a-f0-9' | head -c 32)
  echo "${h:0:8}-${h:8:4}-4${h:13:3}-a${h:17:3}-${h:20:12}"
}

echo "== 1. CSRF token =="
CSRF=$(CGET "$BASE/" | grep -o 'name="csrf_token" content="[a-f0-9]*"' | head -1 | sed 's/.*content="//;s/"//')
echo "  token: ${CSRF:0:8}..."
if [ -z "$CSRF" ]; then echo "  FAIL  could not extract CSRF token from homepage"; FAIL=$((FAIL+1)); else PASS=$((PASS+1)); fi

echo
echo "== 2. GET /api/chat/settings =="
SETTINGS=$(curl -s --max-time 10 "$BASE/api/chat/settings")
echo "$SETTINGS" | grep -q '"chat_enabled"' && { PASS=$((PASS+1)); echo "  PASS  settings payload has chat_enabled"; } || { FAIL=$((FAIL+1)); echo "  FAIL  settings: $(echo $SETTINGS | head -c 120)"; }

echo
echo "== 3. GET /api/chat/admin/status (public) =="
STATUS=$(curl -s --max-time 10 "$BASE/api/chat/admin/status")
echo "$STATUS" | grep -q '"online"' && { PASS=$((PASS+1)); echo "  PASS  admin status ok"; } || { FAIL=$((FAIL+1)); echo "  FAIL  status: $STATUS"; }

echo
echo "== 4. POST /api/chat/send (new session) =="
SID=$(uuid)
# Note: Bengali payloads are sent as \uXXXX escapes (pure ASCII) because Git Bash
# on Windows converts raw UTF-8 argument bytes to `?`. The server decodes them fine.
SEND=$(CPOST -X POST "$BASE/api/chat/send" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-TOKEN: $CSRF" \
  -d "{\"session_id\":\"$SID\",\"visitor_name\":\"\\u099f\\u09c7\\u09b8\\u09cd\\u099f\\u09ad\\u09bf\\u099c\\u09bf\\u099f\\u09b0\",\"visitor_union_name\":\"\\u099f\\u09c7\\u09b8\\u09cd\\u099f\\u0987\\u0989\\u09a8\\u09bf\\u09af\\u09bc\\u09a8\",\"message\":\"\\u0986\\u09ae\\u09be\\u09b0 \\u099c\\u09a8\\u09cd\\u09ae \\u09a8\\u09bf\\u09ac\\u09a8\\u09cd\\u09a7\\u09a8 \\u0995\\u09b0\\u09a4\\u09c7 \\u099a\\u09be\\u0987\"}")
echo "$SEND" | grep -q '"status":"success"' && { PASS=$((PASS+1)); echo "  PASS  send ok"; } || { FAIL=$((FAIL+1)); echo "  FAIL  send: $SEND"; }
SIG=$(echo "$SEND" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["data"]["session_sig"] ?? "";')
MID=$(echo "$SEND" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["data"]["id"] ?? 0;')
echo "  session=$SID sig=${SIG:0:8}... msgId=$MID"
[ -n "$SIG" ] && { PASS=$((PASS+1)); echo "  PASS  session signed"; } || { FAIL=$((FAIL+1)); echo "  FAIL  no session sig"; }

echo
echo "== 5. GET /api/chat/messages (with sig) =="
MSG=$(CGET "$BASE/api/chat/messages?session_id=$SID&session_sig=$SIG")
echo "$MSG" | grep -q '"sender_type":"visitor"' && { PASS=$((PASS+1)); echo "  PASS  messages returned"; } || { FAIL=$((FAIL+1)); echo "  FAIL  messages: $(echo $MSG | head -c 150)"; }
# Verify the Bengali message round-tripped correctly (stored as proper UTF-8)
echo "$MSG" | php -r '
$d = json_decode(stream_get_contents(STDIN), true);
$found = false;
foreach (($d["data"] ?? []) as $m) {
    // PHP uses \u{XXXX} syntax for Unicode code points
    if (($m["sender_type"] ?? "") === "visitor" && mb_strpos($m["message"] ?? "", "\u{099c}\u{09a8}\u{09cd}\u{09ae}") !== false) $found = true;
}
echo $found ? "UTF8_OK" : "UTF8_BAD";
' | grep -q UTF8_OK && { PASS=$((PASS+1)); echo "  PASS  bengali message stored as UTF-8"; } || { FAIL=$((FAIL+1)); echo "  FAIL  bengali message corrupted"; }

echo
echo "== 6. GET /api/chat/messages (bad sig -> 403) =="
CODE=$(CCODE "$BASE/api/chat/messages?session_id=$SID&session_sig=deadbeef")
check "bad sig rejected" "403" "$CODE"

echo
echo "== 7. POST /api/chat/send (wrong sig -> 403) =="
CODE=$(CCODE -X POST "$BASE/api/chat/send" \
  -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $CSRF" \
  -d "{\"session_id\":\"$SID\",\"session_sig\":\"deadbeef\",\"visitor_name\":\"x\",\"message\":\"হাই\"}")
check "wrong-sig send rejected" "403" "$CODE"

echo
echo "== 8. POST /api/chat/send (no CSRF -> 403) =="
CODE=$(CCODE -X POST "$BASE/api/chat/send" \
  -H "Content-Type: application/json" \
  -d "{\"session_id\":\"$SID\",\"session_sig\":\"$SIG\",\"visitor_name\":\"x\",\"message\":\"হাই\"}")
check "missing CSRF rejected" "403" "$CODE"

echo
echo "== 9. GET /api/chat/unread/count =="
COUNT=$(CGET "$BASE/api/chat/unread/count?session_id=$SID&session_sig=$SIG")
echo "  unread=$COUNT"
[ "$COUNT" = "0" ] && { PASS=$((PASS+1)); echo "  PASS  unread count ok"; } || { FAIL=$((FAIL+1)); echo "  FAIL  unread=$COUNT"; }

echo
echo "== 10. POST /api/chat/typing + GET =="
T=$(CPOST -X POST "$BASE/api/chat/typing" \
  -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $CSRF" \
  -d "{\"session_id\":\"$SID\",\"session_sig\":\"$SIG\"}")
echo "$T" | grep -q '"status":"success"' && { PASS=$((PASS+1)); echo "  PASS  typing set"; } || { FAIL=$((FAIL+1)); echo "  FAIL  typing: $T"; }
# /api/chat/admin/typing needs an admin session, so verify the DB directly
# that visitor_typing_at was recorded (within the last 5 seconds).
php -r '
$env = [];
foreach (file(".env") as $l) { $l=trim($l); if (!$l || strpos($l,"#")===0) continue; if (strpos($l,"=")===false) continue; list($k,$v)=explode("=",$l,2); $env[trim($k)]=trim($v); }
$m = new mysqli($env["DB_HOST"], $env["DB_USER"], $env["DB_PASS"], $env["DB_NAME"], (int)($env["DB_PORT"]??3306));
$stmt = $m->prepare("SELECT visitor_typing_at > NOW() - INTERVAL 5 SECOND AS t FROM chat_sessions WHERE session_id = ?");
$stmt->bind_param("s", $argv[1]); $stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
echo ($row && (int)$row["t"] === 1) ? "TYPING_OK" : "TYPING_BAD";
$m->close();
' "$SID" | grep -q TYPING_OK && { PASS=$((PASS+1)); echo "  PASS  visitor typing recorded"; } || { FAIL=$((FAIL+1)); echo "  FAIL  visitor typing not recorded"; }

echo
echo "== 11. POST /api/chat/offline =="
OFF=$(CPOST -X POST "$BASE/api/chat/offline" \
  -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $CSRF" \
  -d '{"name":"\u099f\u09c7\u09b8\u09cd\u099f","phone":"01700000000","email":"t@example.com","message":"\u0985\u09ab\u09bf\u09b8\u09c7\u09b0 \u09b8\u09ae\u09af\u09bc \u0995\u09a4?"}')
echo "$OFF" | grep -q '"status":"success"' && { PASS=$((PASS+1)); echo "  PASS  offline message saved"; } || { FAIL=$((FAIL+1)); echo "  FAIL  offline: $OFF"; }

echo
echo "== 12. GET /api/chat/push/vapid-key =="
VK=$(CGET "$BASE/api/chat/push/vapid-key")
echo "  $VK"
echo "$VK" | grep -q '"enabled":true' && { PASS=$((PASS+1)); echo "  PASS  push enabled"; } || { FAIL=$((FAIL+1)); echo "  FAIL  push enabled flag"; }
echo "$VK" | grep -q '"public_key":"[^"]\{20,\}"' && { PASS=$((PASS+1)); echo "  PASS  vapid public key present"; } || { FAIL=$((FAIL+1)); echo "  FAIL  no public key"; }

echo
echo "== 13. POST /api/chat/push/subscribe (valid browser-shaped sub) =="
P256="BMVhx8R_hYFHJ2x6hv2_NdOD88U_dLRk6CQ0hZ-KpQd2cA4m7nR2RljxZxwD3sQ0wQeUvYp6fJQ1m9NkA3b2iM8"
AUTH="aXJ0b3hDZ2lSdG9sR2hvYg"
SUB=$(CPOST -X POST "$BASE/api/chat/push/subscribe" \
  -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $CSRF" \
  -d "{\"session_id\":\"$SID\",\"session_sig\":\"$SIG\",\"subscription\":{\"endpoint\":\"https://fcm.googleapis.com/fcm/send/test-$SID\",\"keys\":{\"p256dh\":\"$P256\",\"auth\":\"$AUTH\"}}}")
echo "  $SUB"
echo "$SUB" | grep -q '"status":"success"' && { PASS=$((PASS+1)); echo "  PASS  subscribed"; } || { FAIL=$((FAIL+1)); echo "  FAIL  subscribe: $SUB"; }

echo
echo "== 14. POST /api/chat/push/subscribe (garbage keys -> 400) =="
SUB2=$(CCODE -X POST "$BASE/api/chat/push/subscribe" \
  -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $CSRF" \
  -d "{\"session_id\":\"$SID\",\"session_sig\":\"$SIG\",\"subscription\":{\"endpoint\":\"not-a-url\",\"keys\":{\"p256dh\":\"x\",\"auth\":\"y\"}}}")
check "garbage subscription rejected" "400" "$SUB2"

echo
echo "== 15. POST /api/chat/push/unsubscribe =="
UNSUB=$(CPOST -X POST "$BASE/api/chat/push/unsubscribe" \
  -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $CSRF" \
  -d "{\"session_id\":\"$SID\",\"session_sig\":\"$SIG\",\"endpoint\":\"https://fcm.googleapis.com/fcm/send/test-$SID\"}")
echo "$UNSUB" | grep -q '"status":"success"' && { PASS=$((PASS+1)); echo "  PASS  unsubscribed"; } || { FAIL=$((FAIL+1)); echo "  FAIL  unsubscribe: $UNSUB"; }

echo
echo "== 16. GET /api/chat/faq =="
FAQ=$(CGET "$BASE/api/chat/faq")
echo "$FAQ" | grep -q '"status":"success"' && { PASS=$((PASS+1)); echo "  PASS  faq ok"; } || { FAIL=$((FAIL+1)); echo "  FAIL  faq: $(echo $FAQ | head -c 100)"; }

echo
echo "== 17. Session timeout expiry (old activity -> session_expired) =="
# Simulate an expired session: insert a session with old visitor activity
OLD_SID=$(uuid)
php -r '
$env = [];
foreach (file(".env") as $l) { $l=trim($l); if (!$l || strpos($l,"#")===0) continue; if (strpos($l,"=")===false) continue; list($k,$v)=explode("=",$l,2); $env[trim($k)]=trim($v); }
$m = new mysqli($env["DB_HOST"], $env["DB_USER"], $env["DB_PASS"], $env["DB_NAME"], (int)($env["DB_PORT"]??3306));
$sid = $argv[1];
$m->query("INSERT INTO chat_sessions (session_id, visitor_name, status, created_at, updated_at) VALUES (\"$sid\", \"ওল্ড\", \"active\", NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY)");
$m->query("INSERT INTO chat_messages (session_id, message, sender_type, is_read, created_at) VALUES (\"$sid\", \"পুরনো বার্তা\", \"visitor\", 0, NOW() - INTERVAL 1 DAY)");
echo "seeded old session\n";
' "$OLD_SID"
EXP=$(CGET "$BASE/api/chat/messages?session_id=$OLD_SID&session_sig=x")
echo "$EXP" | grep -q '"session_expired":true' && { PASS=$((PASS+1)); echo "  PASS  expired session detected"; } || { FAIL=$((FAIL+1)); echo "  FAIL  expired: $EXP"; }

echo
echo "====================================="
echo "RESULT: $PASS passed, $FAIL failed"
echo "====================================="
[ "$FAIL" -eq 0 ]
