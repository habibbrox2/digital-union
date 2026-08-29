# Live Chat Notification System — Handoff

এই নথিতে বর্তমান live chat notification implementation, deployment checklist এবং পরবর্তী কাজের তালিকা রাখা হলো।

## বর্তমানে implement করা হয়েছে

### Visitor chat

- Chat শুরু করার আগে visitor-এর নাম ও ইউনিয়ন নির্বাচন বাধ্যতামূলক।
- ইউনিয়ন তালিকা /api/chat/unions endpoint থেকে আসে।
- Request-এ validated union_id পাঠানো হয়।
- Existing conversation-এর ইউনিয়ন পরিবর্তন করা যায় না।
- Registration অসম্পূর্ণ থাকলে frontend send API call করে না।

### Notification routing

- role_id = 2 এবং একই ইউনিয়নের Secretary notification পান।
- role_id = 3 এবং একই ইউনিয়নের Chairman notification পান।
- role_id = 1 Global Administrator/Superadmin notification পান।
- Administrator/Superadmin-এর email-এ visitor message notification পাঠানো হয়।
- Text message ও file upload—উভয়ের জন্য routing সক্রিয়।
- FCM payload-এ visitor-এর আসল message না পাঠিয়ে privacy-safe generic alert পাঠানো হয়।

### Multi-device ও device management

- একজন admin user-এর একাধিক FCM token আলাদাভাবে সংরক্ষিত হয়।
- Browser/platform/last active তথ্য সংরক্ষণ করা হয়।
- Invalid token cleanup করা হয়।
- Chat Settings-এ active device list, single revoke এবং revoke-all যোগ হয়েছে।
- Device API current user-এর device-এই সীমাবদ্ধ।

### Authorization ও in-app notification

- Chairman/Secretary শুধু নিজেদের ইউনিয়নের conversation দেখতে পারেন।
- Administrator/Superadmin সব ইউনিয়নের conversation দেখতে পারেন।
- Unread count এবং global toast/FAB union-aware করা হয়েছে।
- Existing toast, sound, desktop notification এবং conversation link flow রাখা হয়েছে।

### Database ও migration

- chat_sessions.union_id
- fcm_tokens.revoked_at
- fcm_tokens.device_info
- chat_notification_log

যোগ করা হয়েছে। Existing visitor Web Push-এর chat_push_subscriptions table রাখা হয়েছে।

ChatService-এর SCHEMA_VERSION 3 থেকে 4 করা হয়েছে, তাই পরবর্তী request-এ auto-migration চলবে।

### Compatibility fixes

- ChatService email path-এর database dependency ঠিক করা হয়েছে।
- User schema অনুযায়ী status = active ব্যবহার করা হয়েছে।
- AuthService-এ safe login/current-user helper যোগ হয়েছে।
- Firebase messaging service worker ready হওয়ার পরে initialize হয়।
- Admin chat page-এ Push চালু করুন button যোগ হয়েছে।

## গুরুত্বপূর্ণ ফাইল

- controllers/ChatController.php — chat API, routing ও device API
- models/ChatModel.php — schema, queries, tokens ও devices
- modules/Services/ChatService.php — session, email ও migration
- modules/Services/PushService.php — FCM delivery
- modules/Services/AuthService.php — login/current-user helper
- public/assets/js/chat.js — visitor chat ও union selection
- templates/chat/admin.twig — admin push permission ও token registration
- templates/settings/chat.twig — active device management UI
- config/firebase.php ও config/fcm.php — Firebase/FCM configuration

## Production deployment checklist

1. Production database backup নিন।
2. Deploy-এর পর /chat/admin একবার খুলুন, যাতে schema version 4 migration চলে।
3. chat_schema_version = 4 হয়েছে কি না যাচাই করুন।
4. chat_sessions.union_id, fcm_tokens, chat_notification_log এবং chat_push_subscriptions যাচাই করুন।
5. .env-এ FCM_ENABLED=true এবং protected FIREBASE_SERVICE_ACCOUNT_PATH সেট করুন।
6. Firebase service-account JSON public directory বা Git repository-তে রাখবেন না।
7. Credential আগে expose হয়ে থাকলে Firebase credential rotate করুন।
8. Production site HTTPS-এ চালান।
9. Admin user /chat/admin খুলে Push চালু করুন button-এ click করে browser permission Allow করুন।
10. Permission আগে Block করা থাকলে browser Site Settings থেকে Notifications Allow করুন।

## অবশ্যই যে testগুলো করতে হবে

- ইউনিয়ন নির্বাচন ছাড়া chat message পাঠানো যাবে না।
- Union A-এর message Union B-এর Chairman/Secretary না পান।
- সংশ্লিষ্ট Chairman, Secretary এবং Global Administrator notification পান।
- Administrator online/offline দুই অবস্থায় email পান।
- একই user-এর দুই বা ততোধিক device-এ push আসে।
- Single revoke-এর পরে শুধু ওই device-এ push বন্ধ হয়।
- Revoke-all-এর পরে সব device-এ push বন্ধ হয়।
- Invalid FCM token cleanup হয়।
- Chairman/Secretary অন্য ইউনিয়নের conversation URL access করতে পারেন না।
- Text message ও file upload notification পরীক্ষা করুন।
- Permission denied/service worker unavailable/FCM unavailable অবস্থায় chat functionality চালু থাকে।

## পরবর্তী recommended কাজ

### জরুরি

- Synchronous email-এর পরিবর্তে email queue/worker যোগ করা।
- প্রতিটি recipient ও provider response আলাদাভাবে notification log-এ রাখা।
- queued, sent, failed, invalid_token delivery status যোগ করা।
- Production monitoring ও alert যোগ করা।
- সব API error response consistent JSON করা।

### Security

- Firebase client config ও VAPID key environment/config endpoint থেকে সরবরাহ করা।
- Admin reply, close ও upload endpoint-এ union access check সম্পূর্ণ করা।
- Device revoke-এ confirmation modal যোগ করা।
- Token ও notification log retention policy নির্ধারণ করা।

### UX ও testing

- Chat Settings-এ Granted/Denied/Unavailable permission status দেখানো।
- Readable device name দেখানো, যেমন Chrome on Windows।
- Notification test button যোগ করা।
- PHPUnit test যোগ করা recipient resolution, union authorization ও token revoke-এর জন্য।
- Browser integration test যোগ করা union selection, permission ও device flow-এর জন্য।
- Scheduled cleanup job যোগ করা expired token ও পুরনো log-এর জন্য।

## Known limitations

- Real-time delivery বর্তমানে polling + FCM নির্ভর; WebSocket/SSE নেই।
- Browser permission browser policy ও user action-এর ওপর নির্ভরশীল।
- Push body privacy কারণে generic রাখা হয়েছে।
- Email delivery এখনও synchronous; queue যোগ করলে reliability বাড়বে।
- পুরনো session-এর union_id null থাকতে পারে; এসব session-এর data migration প্রয়োজন হতে পারে।

## Validation status

শেষ implementation-এর পরে PHP syntax, JavaScript syntax এবং git diff check সফল হয়েছে।
Automated browser tool unavailable থাকায় browser end-to-end test চালানো হয়নি; staging/production-এ উপরের checklist অনুযায়ী manual test করতে হবে।
