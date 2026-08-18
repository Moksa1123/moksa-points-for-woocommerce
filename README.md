# Moksa Points for WooCommerce (`moksafopoi`)

A **points and store-credit value engine** for WooCommerce, built around one idea: there is
exactly **one ledger**, and it is auditable. Every balance change is an immutable, de-duplicated
row, so a retried checkout, a double-fired hook or a concurrent request can never mint or
double-spend value — and every number the plugin shows you can be recomputed from that ledger.

On top of that ledger it adds the things merchants usually pay for — earn rules, a redemption
catalogue, a checkout wallet, tiers, quests, gift cards and points packs — and makes the whole
programme **readable by AI agents** through the WordPress Abilities API and an optional MCP server.

> This repository is the **clean WordPress.org install package** (no dev files; `vendor` is
> `--no-dev`). Drop the `moksa-points-for-woocommerce/` folder into `wp-content/plugins/` and
> activate.

- **Requires:** WordPress 7.0+ · PHP 8.2+ · WooCommerce 10.7+
- **Version:** 1.0.0 · **License:** GPLv3 or later
- **HPOS** and **Block Checkout** compatible
- Every feature is a separate module. On install only a **safe additive core** is on — the ledger,
  the "My points" page, purchase and coupon earning, campaigns, and **Ability registration**
  (every ability capability-checked at `manage_woocommerce`). Anything that **moves value at
  checkout** (redemption, cashback, gift cards) and anything that **opens an outside interface**
  (external MCP, webhooks, LINE, the customer REST API) stays **off until you switch it on**.

---

## Why the ledger matters

| Property | How it is enforced |
|---|---|
| No double-award | Every write goes through `record_once()` with a stable reference and a UNIQUE idempotency key |
| No double-spend | Redemptions and checkout debits take a per-user lock |
| Refund-safe | Refunds and cancellations reverse proportionally, on the same reference |
| Recomputable | Balances are cached in user-meta but always derivable from the rows |
| Auditable | The admin ledger browser filters and exports the raw rows as CSV |

Because the ledger is complete, the plugin can answer questions almost no loyalty plugin can:
**how much do we owe** (outstanding liability), **how much lapses in 30/60/90 days**, and
**what share of issued points expired unused** (breakage) — all arithmetic over existing rows,
never an estimate typed into a settings field.

## Features

### Earning
- Points per amount spent, based on the amount **actually paid**, with per-product and
  per-category overrides, exclusions, and a daily earn cap
- Sign-up, first-order, daily check-in, product review, birthday and membership-anniversary bonuses
- Referral rewards for both sides, with claw-back on refund
- Marketing campaigns with **recurring schedules** — weekly ("double points every weekend"),
  monthly ("bonus day on the 5th") or yearly ("anniversary sale"), plus a daily time window
- Per-payment-method multipliers, so you can steer customers toward the method that costs you less
- WooCommerce Subscriptions aware: renewals can be excluded or boosted, and a renewal never
  counts as a "first order"

### Spending
- Redemption catalogue (coupon / product / store-credit rewards) with stock and per-customer limits
- Points or store-credit discount at checkout (classic and block)
- Buy-with-points, redeem codes, gift cards, points packs and member-to-member transfer
- **FIFO expiry** — the oldest unused batch is consumed first, so nothing is over- or under-burned

### For members
- A "My points" account page: balance, tier, quests, how-to-earn guide, rewards mall and history
- **Tier ladder** — "2,400 points to Gold", based on lifetime earned points so spending never demotes
- **Quests** — "complete 3 orders", "leave a review"; paid out once, on a triple-guarded idempotent write
- Leaderboard, achievement badges and a shareable achievement card
- Six **Gutenberg blocks** wrapping the same surfaces (each renders exactly what its shortcode renders)

### For the shop
- Ledger browser with filters and CSV export; issued / redeemed / expired / outstanding report
- **Liability and breakage forecast** with reward-pricing suggestions derived from real balances
- **CSV import** of existing balances — idempotent per batch code, with a preview that writes nothing
- **Ledger compaction** with a hard one-year floor and per-member balance verification
- Expiry reminder e-mails, plus optional **outbound webhooks** (signed, queued) and
  **LINE Messaging API** notifications
- Customer-scoped **REST API** for headless storefronts — no `user_id` parameter anywhere, so a
  member can only ever read themselves

### Abilities, AI and MCP
Every read and value action is a capability-checked WordPress Ability, reachable from the
Abilities API, an in-dashboard AI assistant (WordPress 7.0 AI Client) and an optional MCP server.

Ability registration is **on by default** — each one is checked against `manage_woocommerce`, so an
agent can never do more than the logged-in user could do by hand. The AI assistant is bundled but
inert until you configure a provider. The **external MCP server is off**; destructive abilities stay
hidden from it unless you separately opt in, and even then they run behind an administrator-level
capability, an hourly rate limit and a full audit log.

## Multilingual

English is the source language. A complete Traditional Chinese (Taiwan) translation ships with
the plugin (`languages/`), covering all 1,136 strings.

## External services

Out of the box the plugin makes **no outbound requests**. Three optional features can contact a
service you choose — the AI provider you configure in WordPress's AI Client, a webhook endpoint
URL you supply, and the LINE Messaging API. See the "External services" section of `readme.txt`
for exactly what is sent in each case.

## License

GPLv3 or later. See [LICENSE](LICENSE).
