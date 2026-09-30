# EPOS team ke jawab (portal ke sawalon par) — 2026-09-30

**Email se mile (received by email), 2026-09-30.** Short summary; portal ne yeh sab apply kar diya (docs/DECISIONS.md,
"EPOS answers, portal (2026-09-30)").

1. **Shop move `D` with `payload: null`** — theek hai (envelope `branchId` = purani shop, ya `""`). Lekin `Setting` aur
   `RolePermission` ka `D` **hamesha payload ke saath** aana chahiye (till row ko payload ki keys se dhoondta hai).
   Portal: verify kiya, pull kabhi null payload nahi bhejta; contract guard + test.
2. **Every-shop row (branchId null) ek shop ki ban jaye** — har **doosri** shop ko `D`, envelope `branchId` = **us ki
   apni** shop (`""` nahi; null kabhi nahi — schema string hai). `D` ka payload null ya purani row (branchId null +
   deletedAt) — **kabhi nayi shop ki branchId nahi**, warna doosri tills skip karti hain aur every-shop offer chalta rehta
   hai. Nayi shop ko normal `U` apni `branchId` ke saath.
3. **Portal key via `licence/redeem`, installId se bound** — jaisa hai waisa rakhein (till asal mein yeh karta hi nahi).
   No change.
4. **`cloud/migrate` with a portal licence key jo is PC par abhi activate nahi hui** — reply 409
   `migrate.activate_first`, en-GB message "Enter this licence key under Settings → Licence first." Is case ke liye
   kabhi `activation.code_used` nahi. Code abhi `error-codes.json` mein nahi: proposed by EPOS 2026-09-30, to be added.
5. **Migrate mein `activation.code_*` hi rakhein** (`sync_key.*` nahi). `code_not_found` / `code_expired` / `code_used`
   aur `migrate.already_migrated` apne apne case ke liye. No change.
6. **Main till ka `devices/deactivate`** — hum `transferCode: null` bhejte hain. EPOS dono qubool karta hai: (a) §17.7
   wala one-time `transferCode`, ya (b) null, lekin phir wahi licence key naye PC par activate honi chahiye aur
   `messages[]` owner ko agla qadam bataye (en-GB). `apiKeyRevoked: true` dono surat mein. (a) sirf tab jab transfer code
   hamare bane endpoints (licence/activate, cloud/migrate) se redeem ho sake; warna (b).
   Portal: **(b)** chuna — transfer code sirf `devices/activate` leta hai jo build nahi hota.
