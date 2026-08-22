#!/usr/bin/env bash
# test_chat_admin_flow.sh — admin-side end-to-end test (conversations, reply,
# canned responses, offline admin, settings save, unread totals).
BASE="http://127.0.0.1:8088"
JAR="$(mktemp)"
PASS=0
FAIL=0

check() {
  local label="$1" expected="$2" actual="$3"
  if [ "$expected" = "$actual" ]; then PASS=$((PASS+1)); echo "  PASS  $label";
  else FAIL=$((FAIL+1)); echo "  FAIL  $label (expected $expected, got $actual)"; fi
}
CGET() { curl -s --max-time 10 -b "$JAR" -c "$JAR" "$@"; }
CPOST() { curl -s --max-time 10 -b "$JAR" -c "$JAR" "$@"; }
CCODE() { curl -s -o /dev/null -w "%{http_code}" --max-time 10 -b "$JAR" -c "$JAR" "$@"; }

echo "== 1. Establish admin session =="
ADMIN=$(CGET "$BASE/_test_admin_session.php")
echo "  $ADMIN"
ACSRF=$(echo "$ADMIN" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["csrf_token"] ?? "";')
[ -n "$ACSRF" ] && { PASS=$((PASS+1)); echo "  PASS  admin csrf"; } || { FAIL=$((FAIL+1)); echo "  FAIL  admin csrf"; }

echo
echo "== 2. GET /api/chat/admin/conversations =="
CONVS=$(CGET "$BASE/api/chat/admin/conversations")
echo "  ${CONVS:0:120}"
echo "$CONVS" | grep -q '"status":"success"' && { PASS=$((PASS+1)); echo "  PASS  conversations listed"; } || { FAIL=$((FAIL+1)); echo "  FAIL  convs"; }

# grab a session_id to reply to
TARGET=$(echo "$CONVS" | php -r '
$d = json_decode(stream_get_contents(STDIN), true);
foreach (($d["data"] ?? []) as $c) { if (($c["status"] ?? "") === "active") { echo $c["session_id"]; break; } }')

echo
echo "== 3. GET /api/chat/admin/unread/total =="
TOT=$(CGET "$BASE/api/chat/admin/unread/total")
echo "  ${TOT:0:150}"
echo "$TOT" | grep -q '"total"' && { PASS=$((PASS+1)); echo "  PASS  unread total"; } || { FAIL=$((FAIL+1)); echo "  FAIL  unread total"; }

echo
echo "== 4. GET /api/chat/admin/canned (should work with manage_chat now) =="
CANNED=$(CGET "$BASE/api/chat/admin/canned")
echo "  ${CANNED:0:100}"
echo "$CANNED" | grep -q '"status":"success"' && { PASS=$((PASS+1)); echo "  PASS  canned list"; } || { FAIL=$((FAIL+1)); echo "  FAIL  canned: $(echo $CANNED | head -c 120)"; }

echo
echo "== 5. POST /api/chat/admin/reply (to an active visitor session) =="
if [ -n "$TARGET" ]; then
  REPLY=$(CPOST -X POST "$BASE/api/chat/admin/reply" \
    -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $ACSRF" \
    -d "{\"session_id\":\"$TARGET\",\"message\":\"\\u0986\\u09aa\\u09a8\\u09be\\u09b0 \\u0985\\u09a8\\u09c1\\u09b0\\u09cb\\u09a7 \\u09aa\\u09c7\\u09af\\u09bc\\u09c7\\u099b\\u09bf (\\u099f\\u09c7\\u09b8\\u09cd\\u099f)\"}")
  echo "  ${REPLY:0:140}"
  echo "$REPLY" | grep -q '"status":"success"' && { PASS=$((PASS+1)); echo "  PASS  admin reply sent"; } || { FAIL=$((FAIL+1)); echo "  FAIL  reply: $REPLY"; }
else
  echo "  SKIP  (no active visitor session)"
fi

echo
echo "== 6. GET /api/chat/admin/conversations/{session_id} =="
if [ -n "$TARGET" ]; then
  CONV=$(CGET "$BASE/api/chat/admin/conversations/$TARGET")
  echo "$CONV" | grep -q '"sender_type":"admin"' && { PASS=$((PASS+1)); echo "  PASS  conversation has admin reply"; } || { FAIL=$((FAIL+1)); echo "  FAIL  conv detail: $(echo $CONV | head -c 140)"; }
else
  echo "  SKIP"
fi

echo
echo "== 7. POST /api/chat/admin/offline (list) =="
OFFL=$(CGET "$BASE/api/chat/admin/offline")
echo "$OFFL" | grep -q '"status":"success"' && { PASS=$((PASS+1)); echo "  PASS  offline list"; } || { FAIL=$((FAIL+1)); echo "  FAIL  offline: $(echo $OFFL | head -c 120)"; }

echo
echo "== 8. POST /api/chat/settings/save — toggle OFF + invalid key ignored =="
SAVE=$(CPOST -X POST "$BASE/api/chat/settings/save" \
  -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $ACSRF" \
  -d '{"settings":{"chat_visitor_push_enabled":"0","chat_admin_notify_sound":"0","chat_push_vapid_public_key":"bogus!!bad-key","chat_push_vapid_private_key":"","chat_title":"TITLE-TEST-123"}}')
echo "  $SAVE"
echo "$SAVE" | grep -q '"status":"success"' && { PASS=$((PASS+1)); echo "  PASS  settings saved"; } || { FAIL=$((FAIL+1)); echo "  FAIL  save: $SAVE"; }

echo
echo "== 9. Verify persisted settings (push OFF, invalid key ignored, title set) =="
S2=$(CGET "$BASE/api/chat/settings")
echo "  ${S2:0:160}"
echo "$S2" | grep -q '"chat_visitor_push_enabled":"0"' && { PASS=$((PASS+1)); echo "  PASS  push disabled persisted"; } || { FAIL=$((FAIL+1)); echo "  FAIL  push toggle not persisted"; }
echo "$S2" | grep -q '"bogus!!bad-key"' && { FAIL=$((FAIL+1)); echo "  FAIL  invalid key was saved!"; } || { PASS=$((PASS+1)); echo "  PASS  invalid key rejected"; }
echo "$S2" | grep -q '"chat_title":"TITLE-TEST-123"' && { PASS=$((PASS+1)); echo "  PASS  title saved"; } || { FAIL=$((FAIL+1)); echo "  FAIL  title not saved"; }

echo
echo "== 10. Partial save must PRESERVE chat_enabled (not disable it) =="
SAVE2=$(CPOST -X POST "$BASE/api/chat/settings/save" \
  -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $ACSRF" \
  -d '{"settings":{"chat_visitor_push_enabled":"1","chat_admin_notify_sound":"1","chat_title":"\u09b2\u09be\u0987\u09ad \u099a\u09cd\u09af\u09be\u099f \u09b8\u09b9\u09be\u09af\u09bc\u09a4\u09be"}}')
echo "$SAVE2" | grep -q '"status":"success"' && { PASS=$((PASS+1)); echo "  PASS  partial save ok"; } || { FAIL=$((FAIL+1)); echo "  FAIL  partial save: $SAVE2"; }
S3=$(CGET "$BASE/api/chat/settings")
echo "$S3" | grep -q '"chat_enabled":"1"' && { PASS=$((PASS+1)); echo "  PASS  chat_enabled preserved on partial save"; } || { FAIL=$((FAIL+1)); echo "  FAIL  chat_enabled was changed by partial save!"; }

echo
echo "== 11. VAPID key generation endpoint (admin only) =="
GEN=$(CPOST -X POST "$BASE/api/chat/settings/vapid" \
  -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $ACSRF" \
  -d '{"action":"generate"}')
echo "  ${GEN:0:160}"
echo "$GEN" | grep -q '"private_key_set":true' && { PASS=$((PASS+1)); echo "  PASS  keys exist, no private key leaked"; } || { FAIL=$((FAIL+1)); echo "  FAIL  generate: $GEN"; }
echo "$GEN" | grep -q '"private_key"' && { FAIL=$((FAIL+1)); echo "  FAIL  private key leaked in response!"; } || { PASS=$((PASS+1)); echo "  PASS  private key not in response"; }

echo
echo "====================================="
echo "RESULT: $PASS passed, $FAIL failed"
echo "====================================="
[ "$FAIL" -eq 0 ]
