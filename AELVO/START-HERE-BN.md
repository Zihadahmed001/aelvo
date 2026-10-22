> **২২ অক্টোবর ২০২৬ latest:** Merchant-managed product sale price (only inherited variants), manual Featured/New Arrival badges, coupon/sale non-stacking default, primary image sorting, and hexadecimal gallery ID fix যুক্ত হয়েছে। Schema এখন ৪০টি additive migration। MySQL/browser tests এখনও হয়নি। নিচের ইতিহাস আগের code snapshot-এর বর্ণনা।

> **২২ অক্টোবর ২০২৬ continuation:** এবার variant-level BDT price override (Admin product editor → storefront variant picker → Bag/checkout → staff order) যুক্ত হয়েছে; পুরোনো order prices অপরিবর্তিত। `app/Schema.php`-এ এখন ৩৮টি additive migration। Source-level PHP/JS এবং offline checks চালানো হয়েছে, তবে MySQL/browser/provider acceptance হয়নি। Latest detail `RELEASE-NOTES.md`-এর ওপরের অংশে আছে। নিচের আগের checkpoint/history তথ্য historical।

> ২১ অক্টোবর continuation: নিরাপদ installer migration ledger, SHA-256 migration tracking ও read-only Owner schema status implementation যোগ হয়েছে। Existing untracked database স্বয়ংক্রিয়ভাবে upgrade হবে না। MySQL ও browser validation এখনও হয়নি।

> সর্বশেষ cumulative development source: **২১ অক্টোবর ২০২৬**। Schema এখন **২৮টি** (সর্বশেষ `staff-sessions.sql`)। BulkSMSBD, SEO routes/taxonomy, suppliers/inventory এবং custom CMS page, নিরাপদ bilingual rich-content blocks, staff-only preview ও CMS FAQ source implementation যুক্ত হয়েছে; support inbox, operational reports এবং courier booking/recorded COD remittance source implementation যুক্ত হয়েছে; full Master V5, approved PHP design parity এবং MySQL/browser/provider/production acceptance **অসম্পূর্ণ**। বিস্তারিত `cpanel/PROGRESS.md`, `RELEASE-NOTES.md` ও `handoff/STATUS-AND-BACKLOG.md`।

> সর্বশেষ continuation: ১৪ অক্টোবর ২০২৬। নতুন customer features ও schema 14-এর জন্য handoff/STATUS-AND-BACKLOG.md পড়ুন। নিচের ১৩ অক্টোবরের handoff বর্ণনা historical; নতুন code এখনও untested।

# AELVO — বর্তমান source ও অন্য account-এ কাজ চালানোর নির্দেশনা

তারিখ: ১৩ অক্টোবর ২০২৬। এটি অসম্পূর্ণ development handoff, final production release নয়।

## কতটুকু কাজ হয়েছে?

Code implementation-এর পরিধি দেখে আনুমানিক **৪৫–৫৫%**। এটি audited/weighted measurement নয়। Design preview অনেক দূর এগিয়েছে, কিন্তু PHP backend-এর design parity, provider integrations ও পুরো testing বাকি। এই শতাংশ দিয়ে production readiness বোঝানো হচ্ছে না।

## নতুন ChatGPT/Codex account-এ কী দেবেন?

1. `AELVO-Current-Source-and-Handoff-2026-10-13.zip` upload করুন।
2. `AELVO-CONTINUE-PROMPT.md` ফাইলের সম্পূর্ণ লেখা copy করে message হিসেবে দিন। এই ফাইল ZIP-এর `AELVO/handoff/CONTINUE-PROMPT.md` পথেও আছে।
3. বলুন ZIP extract করে code ও instructions পড়তে। Code/file editing করতে পারে এমন environment ব্যবহার করুন। শুধু text chat হলে ZIP-এর code নিজে edit/test করার ক্ষমতা নাও থাকতে পারে।
4. নতুন account আগের chat, private preview বা workspace নিজে থেকে পাবে না। এই ZIP সেই নির্ভরতা দূর করার জন্য তৈরি।
5. একই design রাখতে `dist/`-এর layout, CSS, assets ও interaction modules reference হিসেবে ব্যবহার করতে হবে। `cpanel/`-এর বর্তমান functional layout final approved design নয়।

কোনো prompt একাই সঠিক final implementation নিশ্চিত করতে পারে না। নতুন account-কে actual code পড়তে, বাকি কাজ শেষ করতে এবং PHP/MySQL/browser-এ যাচাই করতে হবে।

## ZIP-এর প্রধান জিনিস

- `dist/`: approved frontend/demo, soft sage/mint colour, warm background, hover feedback ও hero overlap correction-সহ। Refresh-এ demo state reset হয়।
- `cpanel/`: বর্তমান PHP 8.2+/PDO/MySQL source, migrations ও tests। সব features complete নয়; tests এই snapshot-এ চালানো হয়নি।
- `handoff/MASTER-SPEC-V5.txt`: মূল পূর্ণ specification ও SEO scope।
- `handoff/APPROVED-DESIGN-AND-RULES.md`: আগের design ও business rules; পরের colour/navigation পরিবর্তনের জন্য `DESIGN-LOCK.md` দেখুন।
- `handoff/STATUS-AND-BACKLOG.md`: code-এ কী আছে, কী বাকি, জানা সীমাবদ্ধতা।
- `handoff/CONTINUE-PROMPT.md`: নতুন account-এর বিস্তারিত নির্দেশনা।
- `handoff/ACCEPTANCE-CHECKLIST.md`: implementation শেষে যাচাইয়ের তালিকা।
- `cpanel/INSTALL.md`: XAMPP/cPanel development setup।
- `FILE-MANIFEST-SHA256.json`: source files-এর size ও checksum।

## এখন শুধু design দেখতে চাইলে

ZIP extract করে `AELVO` folder `C:\xampp\htdocs\`-এ রাখুন। Apache চালিয়ে `http://localhost/AELVO/dist/` খুলুন। এটি frontend demo। Backend চালানোর নিয়ম আলাদা—`cpanel/INSTALL.md` অনুসরণ করতে হবে।

## এই handoff-এর সীমা

কোনো real API key, database dump, customer records বা live deployment নেই। Browser এবং নতুন PHP/MySQL runtime testing হয়নি। আগের ৫০টি frontend check-এর ফল historical; তা PHP backend-এর pass result নয়। তোমার আগের নির্দেশ অনুযায়ী implementation শেষে full testing করতে হবে।
