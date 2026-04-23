# 🚀 প্রোডাকশন সেটআপ - সহজ নির্দেশনা

## মাত্র 5 মিনিটে Production Ready করুন!

### প্রয়োজনীয় তথ্য:
- **সার্ভার IP**: 65.21.174.100
- **ব্যবহারকারী নাম**: tdhuedhn  
- **পাসওয়ার্ড**: 9qnTd3.9ApPC5
- **প্রজেক্ট পথ**: /home/tdhuedhn/lgdhaka/ht_docs

---

## ধাপ 1: আপনার কম্পিউটারে Terminal খুলুন

### Windows এর জন্য:
- PowerShell খুলুন (Windows + X, তারপর PowerShell)
- নিচের কমান্ড লিখুন

### Mac/Linux এর জন্য:
- Terminal খুলুন
- নিচের কমান্ড লিখুন

---

## ধাপ 2: সার্ভারে লগইন করুন

```bash
ssh tdhuedhn@65.21.174.100
```

যখন পাসওয়ার্ড চাইবে: `9qnTd3.9ApPC5` লিখুন

---

## ধাপ 3: প্রজেক্ট ফোল্ডারে যান

```bash
cd /home/tdhuedhn/lgdhaka/ht_docs
```

---

## ধাপ 4: সেটআপ স্ক্রিপ্ট চালান

```bash
bash scripts/production-setup.sh --fix
```

---

## ধাপ 5: অপেক্ষা করুন!

স্ক্রিপ্ট নিজেই সবকিছু চেক এবং ঠিক করবে।

আপনি এমন মেসেজ দেখবেন:

```
✅ SYSTEM IS PRODUCTION READY!
```

---

## হয়ে গেছে! 🎉

এখন আপনার সিস্টেম production এ ব্যবহারের জন্য প্রস্তুত!

---

## পরবর্তী কাজ (선택사항)

### GitHub Webhook সেটআপ করুন (স্বয়ংক্রিয় deployment এর জন্য):

1. আপনার GitHub রেপোজিটরিতে যান
2. Settings → Webhooks খুলুন
3. "Add webhook" ক্লিক করুন
4. এই তথ্য পূরণ করুন:
   - Payload URL: `https://lgdhaka.co/deploy.php`
   - Content type: `application/json`
   - Secret: `9qnTd3.9ApPC5`
   - Events: "Push events" চয়ন করুন
5. "Add webhook" ক্লিক করুন

এখন যখন আপনি `git push` করবেন, automatic deployment হবে! 🚀

---

## কোনো সমস্যা হলে?

### সমস্যা 1: Password কাজ করছে না
- নিশ্চিত করুন যে Caps Lock বন্ধ আছে
- পাসওয়ার্ড আবার চেষ্টা করুন: `9qnTd3.9ApPC5`

### সমস্যা 2: Permission denied 
এই কমান্ড চালান:
```bash
bash scripts/production-setup.sh --fix
```

### সমস্যা 3: প্রজেক্ট ফোল্ডার খুঁজে পাচ্ছি না
এই কমান্ড চালান:
```bash
find /home/tdhuedhn -name "deploy.php" 2>/dev/null
```

### সমস্যা 4: স্ক্রিপ্ট এক্সিকিউট হচ্ছে না
এই কমান্ড চালান:
```bash
chmod +x scripts/production-setup.sh
bash scripts/production-setup.sh --fix
```

---

## Webhook Test করুন (Optional)

Webhook সঠিকভাবে কাজ করছে কিনা তা পরীক্ষা করতে:

```bash
curl https://lgdhaka.co/deploy.php?token=9qnTd3.9ApPC5
```

আপনি এমন response পাবেন:
```json
{
  "status": "success",
  "message": "Deployment completed successfully"
}
```

---

## Logs দেখুন

Deployment কেমন চলছে তা দেখতে:

```bash
tail -20 /home/tdhuedhn/lgdhaka/ht_docs/storage/logs/webhooks.log
```

---

## আরো তথ্য

বিস্তারিত গাইড এখানে পাবেন:
- [PRODUCTION_SETUP_GUIDE.md](PRODUCTION_SETUP_GUIDE.md) - সম্পূর্ণ গাইড
- [QUICK_COMMANDS.md](QUICK_COMMANDS.md) - কমান্ড রেফারেন্স
- [DEPLOYMENT_TROUBLESHOOTING.md](DEPLOYMENT_TROUBLESHOOTING.md) - সমস্যার সমাধান

---

## সারসংক্ষেপ

✅ Production setup script তৈরি হয়েছে  
✅ Deploy handler আপডেট হয়েছে  
✅ সম্পূর্ণ documentation প্রস্তুত  
✅ Automatic deployment সিস্টেম setup আছে  

আপনি সবকিছু করতে প্রস্তুত! 🚀

---

## চূড়ান্ত চেকলিস্ট

- [ ] সার্ভারে SSH দিয়ে লগইন করেছি
- [ ] প্রজেক্ট ফোল্ডারে এসেছি
- [ ] `bash scripts/production-setup.sh --fix` চালিয়েছি
- [ ] Success মেসেজ দেখেছি
- [ ] GitHub webhook configure করেছি (optional)
- [ ] Webhook test করেছি (optional)

সব চেক হলে ✅ আপনার প্রোডাকশন সেটআপ সম্পূর্ণ!

---

**সাহায্যের জন্য আমাকে জানান!** 😊
