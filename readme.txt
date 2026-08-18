=== Moksa Points for WooCommerce ===
Contributors: moksa0923
Tags: points, rewards, loyalty, store credit, woocommerce
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
WC requires at least: 10.7
WC tested up to: 10.9
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A value engine for WooCommerce: an idempotent points and store-credit ledger with earn rules, redemption, a checkout wallet, and Abilities/MCP.

== Description ==

Moksa Points for WooCommerce is the value engine of the Moksa platform: a single, idempotent ledger that is the one source of truth for every customer's points and store-credit balance. Companion plugins (coupons, membership) read the balance through a documented API and never store their own, so value can never drift between plugins.

Every balance change is written once through a de-duplicated ledger entry keyed by a stable reference, so a retried checkout, a double-fired hook or a concurrent request can never mint or double-spend value. Redemption and checkout debits are guarded by a per-user lock, and refunds and cancellations proportionally reverse the points and credit they originally granted.

Points is modular — enable only what you need from the plugin's own "Moksa Points → Settings" screen. On first install a **safe additive core** is enabled (the points ledger, the "My points" account page, and purchase / coupon earning); **value-moving modules that change checkout** (points & store-credit redemption, cashback, gift cards) and the **AI / Abilities / MCP** surface stay **off until you turn them on**. The plugin is HPOS and Block Checkout compatible and, on uninstall, removes only its own `moksafopoi_` prefixed data.

= Earning and spending =

* **Earn on purchase** — award points per amount spent, based on the amount actually paid, with per-product and per-category overrides and optional exclusions and a daily earn cap.
* **Bonus points** — daily check-in, product review and birthday bonuses.
* **Redeem** — let customers spend points on rewards from a catalogue, on redeem codes, or as a discount at checkout.
* **Checkout wallet** — apply points or store credit as a discount during classic or block checkout.
* **Store credit and cashback** — a top-up wallet and percentage cashback that credit real, spendable value.
* **Gift cards** — sell fixed-value gift cards that credit the recipient's balance.
* **Buy with points** — a one-click "redeem for this product" button on the product page.
* **Point transfer** — let members send points to each other, guarded against overdraft.
* **Referral rewards** — reward a member when a friend they referred completes a qualifying order.
* **Points packs** — sell points for money, exactly like a gift card sells store credit. Purchased points are spendable but do not raise a member's tier unless you opt in.
* **Per-payment-method bonus** — extra points for paying with the method that costs your store less.
* **WooCommerce Subscriptions** — renewals can be excluded from earning or given their own multiplier, and a renewal never counts as a first purchase.

= Display, engagement and admin =

* **Live earn preview** — show "you will earn N points" on the cart and product pages.
* **My Points page** — a WooCommerce My Account tab with the balance, tier, quests, how-to-earn guide, rewards mall, history and redeem codes.
* **Tier ladder** — show a member's current tier and the distance to the next one ("2,400 points to Gold"). Tiers are reached on lifetime points earned, so spending never demotes anyone; when a membership plugin is installed its tiers are used instead.
* **Quests** — missions such as "complete 3 orders" or "leave a review" that pay out once when finished, with progress shown to the member.
* **Leaderboard and badges** — optional gamification for top earners.
* **Editor blocks** — the balance, tier, quests, how-to-earn guide, leaderboard and redemption mall as Gutenberg blocks.
* **Expiry** — optional FIFO point expiry (the oldest unused batch is consumed first) with a scheduled reminder email before points lapse.
* **Campaigns** — time-boxed earn multipliers, including recurring schedules (every weekend, the 5th of every month, an annual sale) with a daily time window.
* **Ledger browser** — an admin ledger with filters and a CSV export.
* **Reports** — an issued / redeemed / expired / outstanding overview, plus a liability and breakage forecast with reward-pricing suggestions derived from real member balances.
* **CSV balance import** — seed existing balances when migrating from another plugin. Idempotent per batch code, with a preview that writes nothing.
* **Ledger compaction** — replace very old entries with one opening-balance row per member. Balances are verified per member before anything is deleted, and nothing newer than a year is ever touched.
* **Outbound webhooks** — push points events to an ESP, CRM or automation platform. Signed, queued, and never blocking checkout.
* **LINE notifications** — push earned / redeemed / expiring messages over the LINE Messaging API to members who have linked their LINE account.
* **Customer REST API** — read-only endpoints for a headless storefront or app. They serve the logged-in customer only; there is no user_id parameter, so one member can never read another.

= Abilities, AI and MCP (optional) =

Every read and value action is registered as a capability-checked WordPress Ability, reachable from the WordPress Abilities API, an in-dashboard AI assistant (WordPress 7.0 AI Client) and an optional self-built MCP server. These are off by default and every ability and REST route checks the current user's `manage_woocommerce` capability. Destructive abilities stay disabled over external MCP unless you separately opt in.

== External services ==

Out of the box this plugin connects to nothing and makes no outbound HTTP requests. Three optional features, each off by default, can contact a service you choose:

1. AI assistant (in the dashboard). When you enable it and configure a provider in WordPress's AI Client (WordPress 7.0+), the natural-language text you type in the admin is sent to the AI model provider you configured. The plugin bundles no AI SDK and includes no API keys. Which provider receives the text, and the terms and privacy policy that apply, depend entirely on the AI Client connector you set up.

2. Outbound webhooks. When you enable webhooks and enter an endpoint URL, the plugin sends a JSON POST to THAT URL — a URL you supply, not one of ours — each time a subscribed points event happens (points earned, redeemed, expiring soon, quest completed). The payload contains the customer's user ID, their balance, and the event details; their e-mail address is included only if you additionally tick "Include the member e-mail". Nothing is sent until you provide a URL and select at least one event. Because the destination is your own choice, the applicable terms and privacy policy are those of whichever service you point it at.

3. LINE notifications. When you enable LINE notifications and enter a LINE Messaging API channel access token, the plugin sends a message to the LINE Messaging API (https://api.line.me/v2/bot/message/push) for members who have linked their LINE account. The request contains the member's LINE user ID and the message text. Service: LINE Messaging API, operated by LY Corporation — terms: https://terms.line.me/line_terms/ , privacy policy: https://line.me/en/terms/policy/ . Nothing is sent until you provide a token and select at least one event, and members who have not linked LINE are skipped entirely.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/moksa-points-for-woocommerce`, or install it from the Plugins screen.
2. Activate it. WooCommerce must be active.
3. Open **Moksa Points → Settings** and switch on the modules you want. A safe earning core is enabled on first install; checkout-changing modules (points / store-credit redemption, cashback, gift cards) and the AI / MCP surface stay off until you enable them.

== Frequently Asked Questions ==

= Does another plugin store the balance? =

No. This plugin is the single source of truth for points and store-credit balances. Companion plugins read the balance through a documented API and never keep their own copy.

= Can the same order award points twice? =

No. Every ledger entry is written once, keyed by a stable reference, so a retried or double-fired event cannot mint or double-spend value. Redemption and checkout debits are additionally guarded by a per-user lock.

= What happens to points on a refund or cancellation? =

Refunds and cancellations proportionally reverse the points and store credit the order originally granted.

= Does it work without the AI features? =

Yes. All points, wallet and reward features work on a standard WooCommerce store with no AI configuration. The AI assistant, Abilities and MCP are optional and off by default.

== Changelog ==

= 1.0.0 =
* Initial release.
* Ledger: idempotent points and store-credit entries, per-user locks on every debit, proportional reversal on refund and cancellation, an admin browser with CSV export, and a liability / breakage forecast computed from the rows.
* Earning: per-amount rules with a daily cap and per-product / per-category overrides, sign-up, first-order, check-in, review, birthday and anniversary bonuses, referral rewards, per-payment-method multipliers, and WooCommerce Subscriptions awareness.
* Spending: redemption catalogue with stock and per-customer limits, checkout wallet (classic and block), buy-with-points, redeem codes, gift cards, points packs and member-to-member transfer.
* Members: My Points page, tier ladder, quests, leaderboard, badges, a how-to-earn guide, a shareable achievement card and six editor blocks.
* Operations: FIFO expiry with reminder e-mails, recurring campaign schedules, CSV balance import, ledger compaction, outbound webhooks, LINE Messaging API notifications and a customer-scoped REST API.
* Optional Abilities / AI / MCP exposure, off by default, with an hourly rate limit and an audit log on external tool calls.
* Ships a complete Traditional Chinese (Taiwan) translation.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
