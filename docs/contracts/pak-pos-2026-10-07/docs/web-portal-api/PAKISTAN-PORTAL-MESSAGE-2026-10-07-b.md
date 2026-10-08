# Portal team's second message about the Pakistan till (received 2026-10-07, the morning)

The owner pasted this on 2026-10-07 while the Pakistan work of the night was being finished; it follows
`PAKISTAN-PORTAL-MESSAGE-2026-10-07.md`. It is the portal team's text, kept as written. What it means for the till, what
is already done and what waits for the owner is in PROGRESS.md (pak-pos branch).

---

Assalam o Alaikum till team,

Pichle message (Pakistan) ke saath ek aur hissa. Portal par Pakistan (COUNTRY=PK) ke liye hum ne UK ke qanoon wale features ke ye faisle kiye hain. Till ke Pakistan profile mein bhi yahi hone chahiye, taake dono jagah ek jaisa ho.

1) Pakistan mein band / chhupa (portal par ho gaya, till par bhi chahiye):
- Deposit return scheme (DRS): product "Deposit return item", DRS payment type, receipt par bottle deposit lines, branch "Deposit return point".
- Lottery: product flag, "points on lottery", lottery age rule.
- Alcohol licensing: licensing hours, alcohol duty reports, staff "Personal licence holder", branch "Licensed hours".
- HFSS aur vaping duty: product rules aur offers mein HFSS.
- Pharmacy (NHS prescription, charges, exemptions, GSL/P/POM): Pakistani (DRAP) version banne tak band.
Portal ki taraf: agar till ye fields bheje to hum unhein waise hi store karte hain, kuch reject nahi karte.

2) Pakistan ka apna version chahiye (aap batayen):
- Age wale qaide: UK ka tobacco generational ban (1 Jan 2009 ke baad paida hone wale), Challenge 25, aur energy drinks/paracetamol 16. Pakistan ke qaide kya honge? Tab tak hum portal par waise hi rakh rahe hain.
- Paisa: Pakistan mein poore rupay hain. 5p cash rounding aur pence wala keypad (till settings) Pakistan mein kaise chalega? Hamara mashwara: rounding poore rupay mein ho, aur keypad mein paisa na ho.
- Qeemat "9 par khatam": portal par Pakistan mein poore rupay jo 9 par khatam hon (123.40 → 129). Till mein bhi koi aisa rule ho to yahi rakhein.

3) Contract ke sawal:
- Branch.nation: required string hai, aur column ka default "england" hai. Pakistan mein hum ye field chhupa rahe hain, is liye Pakistani dukaan ke liye "england" hi ja raha hai. Pakistan ke liye kya bhejein: khaali, "pakistan", ya province (Punjab, Sindh, Khyber Pakhtunkhwa, Balochistan, Islamabad, Gilgit-Baltistan, AJK)? Agar province chahiye to enum mein naye values add kar dein.
- Staff preferred_culture ka default "en-GB" hai. Pakistan mein kya ho: "en-PK", ya Urdu ke liye "ur-PK"?
- Public holidays: UK ke bank holidays ki jagah Pakistan ki chhuttiyan (calendar events) kaise aayengi?

4) Waise hi rahenge (koi tabdeeli nahi):
Newspapers aur magazines, minimum wage bands (rates till ka data), compliance licence types, calendar, aur supplier payment method "Direct Debit".

Wassalam — portal team
