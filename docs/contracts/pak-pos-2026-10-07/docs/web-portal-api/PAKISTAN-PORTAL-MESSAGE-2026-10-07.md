# Portal team's message about the Pakistan till (received 2026-10-07)

The owner pasted this on 2026-10-07 and said: "installer alag hi rakhna hai, same mat rakhna" (the Pakistan
installer stays its own, never one shared with the UK). It is the portal team's text, kept as written. The
file they mention, `pak-pos-public-key-handover.json`, came from the owner later that day (not in git). What it means for the till, and what
is and is not decided, is in PROGRESS.md (pak-pos branch).

---

Assalam o Alaikum till team,

Portal ki taraf se update aur Pakistan ke liye hamari zarooratein, sab ek jagah.

1) Haalat
- UK portal (https://retail-v2-portal.sspos.co.uk) live hai, customers onboard ho rahe hain. 0.1.51 aur 0.1.52 ke packs lag chuke hain.
- Pakistan portal bhi ban gaya hai: https://pak-pos.sspos.co.uk
  - UK wala hi code, setting COUNTRY=PK.
  - Alag database, alag server setup aur alag licence signing key. UK ke saath kuch share nahi.
  - Sync aur licence ka contract bilkul wahi hai: v1.4.1, wahi endpoints, wahi headers.
  - Portal par Rs (poore rupay, lakh grouping "Rs 1,25,000"), Karachi time, GST, NTN/STRN, aur manual billing (cash, bank, JazzCash, Easypaisa) tayyar hain.

2) Abhi chahiye: Pakistan key ka certificate
Saath mein file hai: pak-pos-public-key-handover.json (kid k9fa21394, Ed25519; sirf public key, koi secret nahi).
UK ki tarah owner ki root key se is ka signer certificate (SSPOSCERT1...) bana kar wapas bhej dein. Hum ise licence:keys:import-cert se lagayenge. Is ke baghair Pakistan portal ke licence tokens par signerCert nahi hoga.

3) Pakistan ki till: kya chahiye
a) FBR POS integration (sab se zaroori; Tier-1 retailers ke liye qanooni):
   - POS ID
   - har sale real-time FBR ko, aur FBR invoice number wapas
   - receipt par FBR number, QR aur logo
   - net na ho to queue mein rakhna aur baad mein bhejna
   - refund aur return reporting
   - Rs 1 POS fee
   Kaam shuru karne se pehle FBR ke latest technical docs le lein.
b) Currency aur tax:
   - Rs, poore rupay ki rounding
   - GST 18% standard, aur item-wise rate (exempt / zero / reduced)
   - Third Schedule (printed retail price / MRP) wali cheezon par tax qeemat ke andar
   - receipt par dukaan ka NTN/STRN; customer ka NTN ya CNIC optional
c) Tenders: JazzCash, Easypaisa, Raast QR aur card. Udhaar / customer account pehle se hai.
d) Receipt: Urdu ya dono zabanon wali receipt (Urdu font). Reminders WhatsApp/SMS par (baad mein bhi chalega).
e) Settings: time zone Asia/Karachi, Pakistan ka date format, aur Pakistani barcodes ke liye product library (library.url setting pehle se hai).
Shuru mein sirf retail. Restaurant ka provincial tax (PRA/SRB) baad mein.

4) Mashwara: till mein ise kaise banayen (hum ne portal par aise kiya)
- Ek hi code, alag copy (fork) nahi. Ek "country profile" (GB / PK) ho jo ye sab tay kare:
  - currency aur rounding
  - tax ka nizam
  - receipt ka design aur zaban
  - tenders ki list
  - time zone
  - FBR module (sirf PK mein on)
- UK ki hifazat: UK live hai.
  - Profile na mile to default UK ho.
  - UK ke maujooda tests kabhi na badlen; badalna pare to iska matlab UK badal gaya.
  - Har hissa chhoti release mein aaye.
- Country licence se aaye: dukaandar khud na chune. Hum chahte hain ke Pakistan portal licence token aur licence/activate / validate ke reply mein country: "PK" bheje (UK portal "GB"), aur till usi ke mutabiq profile lagaye. Ye additive field hoga (§17.11 ki tarah). Aap ko theek lage to hum portal par laga dete hain; aap batayen field ka naam aur jagah (payload ke andar ya reply mein, ya dono).

5) Installer
Hamara mashwara: ek hi code se do installer, jo har release mein saath banen:
- SSPOS-Setup-UK.exe → portal https://retail-v2-portal.sspos.co.uk
- SSPOS-Setup-PK.exe → portal https://pak-pos.sspos.co.uk, aur Rs / GST / FBR ki settings pehle se
Har mulk ka apna download page aur update channel ho. Agar PK installer par UK ki key daali jaye (ya ulta), to till licence ke country se saaf bata de ke ye key is mulk ki nahi.
Portal ki welcome email mein download link har portal ki apni setting hai. PK installer ka link aate hi laga denge.
Agar aap ek hi installer rakhna chahte hain (setup mein mulk chunna, ya key se pehchanna), to bata dein; portal ki taraf koi farq nahi parta.

6) Contract ke naye fields (aap pack mein bhejein; portal inhein store karke reports mein dikhayega)
- Company: country, currency
- Branch: posId (FBR), ntn, strn
- Sale: fbrInvoiceNumber, FBR status (sent / pending / failed), QR data, FBR fee
- Product: GST rate type, aur Third Schedule / MRP flag (agar product par ho)
- enums.json: naye tenders (jazzCash, easypaisa, raast…) aur FBR status
- Licence token / reply: country (upar 4)

7) Pichle khule sawal
a) devices/deactivate: tokenSha256 na mile to hum 403 device.token_mismatch bhejte hain. Please ise error-codes.json mein shamil kar dein, aur naye fields wali release ka number bata dein.
b) Hamare yahan ek branch ki ek hi sync key hai, jo main aur extra tills dono ki hai. Extra till ke licence reply mein apiKey bhejna chahiye ya bilkul nahi? (Reply mein companyId/branchId ab hamesha shop ke hain.)

8) Testing
- UK: go-live se pehle ek dukaan par poore din ka end-to-end test karna hai: sale, refund, Z report, customer account aur advance, stock, extra till join, licence suspend par till lock, Direct Debit. Aap ke liye kaunsa din theek hai?
- Pakistan: aap ki pehli PK build aate hi hum pak-pos portal par ek test business bana denge, aur saath mil kar sync, licence aur FBR test karenge.

Aap se sawal:
a) Certificate kab tak?
b) FBR aur Pakistan ki till ka andazan waqt?
c) Do installer theek hain?
d) Licence mein country field theek hai? Naam aur jagah?
e) FBR ke liye aap ko hum se kya chahiye (FBR account, sandbox, test POS ID)?

Wassalam — portal team
