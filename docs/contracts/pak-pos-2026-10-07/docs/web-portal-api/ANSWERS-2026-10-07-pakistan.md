# Jawab — Pakistan ki till, Pak POS (portal team ke 2026-10-07 ke dono messages par)

Aap ka message: `PAKISTAN-PORTAL-MESSAGE-2026-10-07.md`. Yeh jawab **Pakistan ki line (branch `pak-pos`)** ke code se likha gaya
hai; UK ki till (SSPOS 3, `main`) mein in mein se kuch nahi badla. Jo cheez ban chuki hai us ke saath "BAN GAYA" likha hai, jo
nahi bani us ke saath "ABHI NAHI". Abhi koi Pakistan release nahi hui — pehli build aane par bata denge.

Pakistan ki till ab apna product hai: naam **Pak POS**, apna installer aur apne versions (1.0.0 se — SSPOS 3 ke 0.1.x se
in ka koi taluq nahi). Aaj ki sari tabdeeliyan `UPCOMING-CHANGES.md` ke aakhir mein hain ("pak-pos only" wali lines).

## Aap ke paanch sawal

**a) Certificate kab tak?** **31 December 2027 tak** (owner ka faisla; naam "Pak POS portal"). Public key ki file mil gayi (kid
`k9fa21394`, check ho gayi: key 32 bytes, PEM wohi key, kid theek). Certificate owner ki root key se banta hai — owner khud
bana kar is jawab ke saath bhej rahe hain (generator → "Approve a signer"). Yaad rahe: certificate wapas nahi hota, sirf apni
meyaad par khatam hota hai — 2027 ke aakhir se pehle naya maang lein, warna us ke baad bani keys till qubool nahi karegi.

**b) FBR aur Pakistan ki till ka waqt?** FBR chhor kar neeche "BAN GAYA" wali cheezen tayyar hain; pehli build (Pak POS
1.0.0) ki tareekh alag se batayenge. FBR ka andaza tabhi sahi hoga jab e) wali cheezen mil jayen.

**c) Do installer theek hain?** Haan — owner ka faisla bhi yehi hai: Pakistan ka installer apna, UK ka apna; ek "mulk chuno"
wala installer nahi banega. Farq: hum ne ise ek hi code ke do installer nahi balkay **alag branch** (`pak-pos`) rakha hai, apni
release, apna update feed, apne version number. Aap ke liye is se kuch nahi badalta: do download link, do update channel.

**d) Licence mein country field?** Haan. **BAN GAYA** (contract `docs/web-portal-api.md` §17.18):
- naam `country`, ISO 3166-1 alpha-2 baray huroof mein: Pakistan portal `PK`, UK portal `GB`;
- jagah: **signed token ke payload ke andar** (till isi par bharosa karti hai) aur `licence/activate` aur `licence/validate`
  ke reply mein top level par bhi (wohi value);
- Pakistan ki till doosre mulk ki key refuse karti hai: code `licence.other_country`, "This key is for the United Kingdom. This
  till is for Pakistan — ask your dealer for a key for Pakistan.", kuch save nahi hota; pehle se rakhi hui aisi key till lock
  kar deti hai (`lock.reason` = `wrongCountry`);
- jis key mein `country` nahi (purani keys, hamare generator ki keys) wo har till par chalti hai — aap jab chahen field laga
  dein, kuch nahi tootega;
- ek had: e-mail wali key (token nahi) doosre mulk ki till par type ho to wo us till ke apne portal par jati hai jo use
  jaanta hi nahi → `key.not_found`. Agar wahan bhi saaf message chahiye to dono mulkon ki keys ki shakal alag rakh dein aur
  qaida bata dein.
- UK ki till (0.1.60) abhi `country` nahi parhti (us ke liye anjaan field).

**e) FBR ke liye hum se kya chahiye?** FBR POS integration ke **latest technical documents**, **sandbox account**, ek **test POS
ID**, aur ek **namoona invoice** (request aur response dono) jo FBR qabool karta ho. In ke baghair hum FBR ka koi hissa nahi
banayenge — andaze par tax ka kaam nahi hoga.

## Pichle do sawal

**7a) `device.token_mismatch`** — ho chuka hai, till 0.1.53 se (`licensing/samples/error-codes.json`; till ka message: "The
licence server does not recognise this till's licence. Ask your dealer to deactivate it on the portal."). Aap ke paas packs
sirf 0.1.52 tak hain — `portal-pack-0.1.53-2026-10-06.zip` aur `ANSWERS-2026-10-06-b.md` saath bhej rahe hain.

**7b) Extra till ke licence reply mein `apiKey`?** Code dekh kar: extra till **kabhi sync nahi karti** — sirf shop ki main (ya
akeli) till bhejti hai (`Application/Sync/SyncSenderGate`), aur sync key ka box extra till par band hai
(`ConnectSyncKeyHandler`). Reply mein `apiKey` aaye to extra till use rakh leti hai magar istemal nahi karti. Is liye: **jis
till ke baare mein aap ko pata ho ke main nahi hai, us ke reply mein `apiKey` na bhejein** (ya `null`) — ek secret ki ek copy
kam. Agar activate ke waqt pata na chal sake to bhej dena bhi theek hai; kuch nahi tootta.

## Pakistan ki till — kya ban gaya (branch `pak-pos`, release abhi nahi)

| Aap ki list | Haalat |
|---|---|
| Portal ka address | **BAN GAYA** — build `https://pak-pos.sspos.co.uk` se baat karti hai. |
| Licence `country` | **BAN GAYA** — upar d). |
| Rs | **BAN GAYA** — sign Settings se, shuru mein `Rs`; likhawat "Rs 1,25,000" (lakh grouping; poori raqam `.00` ke baghair, paisay hon to dikhte hain "Rs 1,250.50"). |
| Poore rupay ki rounding | **BAN GAYA, cash par** — "Round cash" Pakistan mein shuru se on: cash customer poore rupay deta hai (Rs 437.50 → Rs 438), farq receipt par "Cash rounding" aur books mein cash over/short. Lines, tax, card aur wallet ki raqam round **nahi** hoti — hisaab paisay tak barabar rehta hai. Har raqam ko poore rupay karna (tax samait) **ABHI NAHI**: us se pehle yeh faisla chahiye ke GST line par poore rupay mein ho ya 2 decimal mein. |
| GST 18%, item-wise rate | **BAN GAYA** — naya shop: Standard A 18%, Zero C, Exempt E, Out of scope O; reduced rate shop khud add karta hai (Settings → GST & tax). Har jagah lafz GST. |
| Third Schedule / MRP | **ABHI NAHI** (FBR ke saath). Qeematein pehle se tax samait hain. |
| Dukaan ka NTN / STRN receipt par | **BAN GAYA** — koi naya field nahi: STRN = `shop.vat_number` / `Company.vatNumber` / `Branch.vatNumber`, NTN = `shop.company_number` / `Company.companyNumber`. Receipt ke header mein "STRN …", footer mein "NTN …", har report PDF ke sar par "STRN …". |
| Customer ka NTN / CNIC | **ABHI NAHI.** |
| JazzCash, Easypaisa, Raast QR | **BAN GAYA** — payment card par "Wallet" key (customer apne phone se deta hai, cashier "Received" dabata hai; transaction ID ikhtiyari, receipt par "Ref …"). Har wallet apna `PaymentType` (naam `JazzCash`, `Easypaisa`, `Raast QR`), `SalePayment.providerRef` = type ki hui transaction ID, ledger account 1245 "Phone wallet clearing". Refund usi wallet par wapas. Tender ka koi enum wire par nahi hai (`enums.json` mein bhi nahi) — naam se milayen. Card bhi pehle se hai. |
| Urdu / do-zabani receipt | **ABHI NAHI** (aap ne khud "baad mein" kaha). |
| Time zone | **BAN GAYA** — Asia/Karachi, build se tay (setting ya PC se nahi). Jo bhi shop-local date push hoti hai wo Karachi ka din hai. |
| Date format | dd/MM/yyyy — koi tabdeeli nahi. |
| Product library | **ABHI NAHI** — setting maujood hai, Pakistan ki library ka address chahiye. |
| FBR | **ABHI NAHI** — upar e). |
| First-run | **BAN GAYA** — Pakistani phone (0300…, +92…), postcode ikhtiyari, STRN jaisa type ho. |
| UK ka VAT return (9 boxes) | Pakistan ki till mein nahi dikhta. |

## Contract ke naye fields (aap ka point 6)

- **Company `country`, `currency`**: ABHI NAHI bheje ja rahe — licence ka `country` kaafi hai; currency sign ek setting hai
  (`shop.currency_symbol`). Chahiye to batayen kis row par.
- **Branch `posId`, sale ke FBR fields, product ka GST rate type / Third Schedule flag, FBR status enum**: FBR ke saath.
- **Branch `ntn`, `strn`**: naye fields nahi — upar wali mapping.
- **`enums.json` mein naye tenders**: wahan tender ka enum hai hi nahi; payment ka tareeqa `PaymentType.name` /
  `SalePayment.paymentTypeName` se pehchana jata hai.
- Har tabdeeli ki tareekh-waar line: `UPCOMING-CHANGES.md` (2026-10-07 wali lines, "pak-pos line only").

## Testing (aap ka point 8)

- UK ka poore din ka test: din alag se batayenge.
- Pakistan: pehli build aate hi bata denge; tab test business bana lein.

## Aap ka doosra message aur checklist (2026-10-07)

Aap ka doosra message: `PAKISTAN-PORTAL-MESSAGE-2026-10-07-b.md`. Neeche wali cheezen usi din Pakistan ki till mein ban gayin
(commits `9fc1f0e5`, `ec02c8b3`, `813c952d`; release abhi nahi). UK ki till mein kuch nahi badla.

### 1) Pakistan mein band / chhupa — BAN GAYA

| Aap ki list | Pakistan ki till par |
|---|---|
| Deposit return (DRS) | Deposit kabhi charge ya print nahi hota. Product ka "Deposit return item" khana aur deposit ki raqam, DRS ki settings (scheme, start date, deposit, return point), receipt aur label ki DRS settings, aur products list ka DRS filter screen par nahi. "DRS payment type": till aisa koi payment type banati hi nahi (`PaymentType.isDrsRefund` hamesha false). |
| Lottery | Product ka Lottery khana, "Points on lottery" setting aur age rule "Lottery (18+)" pickers mein nahi. |
| Alcohol licensing | Licensed hours ka card (Settings → Compliance) nahi, aur till in ghanton par sale nahi rokti. Staff ka "Personal licence holder" khana nahi. "Alcohol duty report" till mein kabhi tha hi nahi. |
| HFSS aur vape duty | Product ke dono khane nahi; offers par HFSS ki rok aur offer ka "HFSS safe" khana nahi. |
| Pharmacy (NHS) | Module on nahi ho sakta aur us ki settings nahi dikhtin — Pakistan (DRAP) wala banne tak. |

Wire par: **koi field, row ya enum nahi hata.** Wohi columns jate hain, bas default par rehte hain (`isDepositItem`, `isLottery`,
`isHfss`, `vapeDutyApplies` false; `licensedHoursJson` khaali; `isPersonalLicenceHolder` false). Aap ki taraf se in mein koi value
aaye to till use rakh leti hai magar qaida band hi rehta hai. Tafseel: `UPCOMING-CHANGES.md` (2026-10-07, "UK laws and schemes").

### 2) Aap ke sawal

- **Age ke qaide.** BAN GAYA (owner ka faisla): Pakistan mein ek hi qaida — **18**. Till ke pickers mein sirf `None` aur
  `Over18`; Challenge 25, generational tobacco (2009 / 2027), nicotine, knives / fireworks / solvents ke alag qaide aur 16
  wale (energy drinks, paracetamol) Pakistan ki till par nahi. Cashier se seedha poocha jata hai "Customer looks over 18?";
  refusal `Over18` ke tehat likha jata hai. Purani ya import hui row mein UK ka koi 18 wala qaida ho to till use sada 18
  samajhti hai, 16 wala ho to kuch nahi poochti. Aap bhi Pakistani dukaan ke liye sirf None / Over18 dikhayein; koi enum ya
  schema change nahi.
- **Raqmon ke start values.** BAN GAYA: paise wali settings Pakistan mein rupay ki raqmon par shuru hoti hain (discount par
  manager PIN 500 se upar, float 5,000, safe drop 50,000 se upar …) — poori list `UPCOMING-CHANGES.md` ki aakhri line mein.
  Agar portal in settings ka default dikhata hai to Pakistani dukaan ko rupay wala dikhayein.
- **Paisa.** Cash rounding poore rupay mein — BAN GAYA (pehle jawab mein). Keypad: "Keypad prices in pence" setting Pakistan ki
  till par hai hi nahi aur kabhi lagu nahi hoti — BAN GAYA.
- **"9 par khatam".** BAN GAYA: poore rupay jo 9 par khatam hon, **hamesha upar** — 123.40 → 129, 129 → 129, 130 → 139. Aap ki
  misaal se "upar" samjha hai; agar portal "qareeb tareen" (123.40 → 119) karta hai to bata dein, ek line ka farq hai.

### 3) Contract ke sawal

- **`Branch.nation`.** Value **`Pakistan`** (owner ka faisla) — BAN GAYA: Pakistan ki till par bani ya save hui har dukaan
  `Pakistan` bhejti hai; province abhi nahi (jab provincial tax banega tab). Field free string hi hai, koi enum nahi. Till par
  "Nation" ka khana (shop ka form, Calendar) nahi dikhta. Aap bhi Pakistani dukaan ke liye `Pakistan` rakhein; purani row mein
  `England` ho to till use parh leti hai aur agli save par `Pakistan` kar deti hai.
- **Staff `preferred_culture`.** Till teen zabanen janti hai: `en-GB` (English), `ur` (Urdu; `ur-PK` ko bhi Urdu parhti hai) aur
  `pa-Arab-PK` (Punjabi). `en-PK` nahi janti. Paisay aur tareekh ki shakal mulk se aati hai, zaban se nahi — is liye English
  ke liye `en-GB` hi theek hai. Default `en-GB` rakhein; Urdu ya Punjabi har staff ke liye alag se chuni ja sakti hai.
- **Public holidays.** Till khud koi chhutti nahi banati (UK ki bhi nahi): calendar ka event sirf Calendar screen par type hota
  hai ya portal se aata hai. Pakistan ki chhuttiyan aap events ki shakal mein bhej dein (nation khaali). Till par in ka naam
  "Public holiday" dikhta hai.

### 4) Waise hi rahenge

Newspapers / magazines, minimum wage bands, compliance licence types, calendar, supplier ka "Direct Debit": till mein bhi koi
tabdeeli nahi.

### Checklist ke baaqi nukte

| Nukta | Haalat |
|---|---|
| Manfi raqam "-Rs 5" | **BAN GAYA** (pehle se). |
| Customer ka phone: +92, 0092 aur 0 ek hi number | **BAN GAYA** — talash, duplicate check aur import teeno mein. |
| UK ki misalein aur idaron ke naam | Screen ke jo texts UK ka naam lete the (card surcharge, bag charge, `example.co.uk`, "Bank holiday", ".x9") ab Pakistan ke lafzon mein — **BAN GAYA**. Help ki tasveeren aur training mode ke namoona products abhi UK ke hain — **ABHI NAHI**. |
| Address mein shehar zaroori | **ABHI NAHI** (dukaan ka postcode ikhtiyari ho chuka hai). |
| Third Schedule / MRP, customer NTN / CNIC, province, Urdu receipt | **ABHI NAHI.** |
| FBR | **ABHI NAHI.** Owner ka faisla: FBR ek setting ke peeche banega jo **shuru mein off** hogi — jab tak dukaan on na kare, till FBR ko kuch nahi bhejti aur receipt par FBR ka kuch nahi chhapta. Banane ke liye wohi chahiye: FBR ke latest documents, sandbox, test POS ID. |
| Minimum unit price (Scotland / Wales ka alcohol qanoon) | Aap ki list mein nahi tha, magar ye bhi UK ka qanoon hai: Pakistan ki till par **BAN GAYA** — us ki dono settings nahi dikhtin aur alcohol ki line par koi floor nahi lagta. Wire par kuch nahi badla. |
