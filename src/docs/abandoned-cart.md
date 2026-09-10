# Abandoned Cart Setup

Craft Commerce has no native "cart abandoned" event — carts are simply purged after they expire. The plugin detects abandonment via a console command that you schedule with cron.

### 0. Requirements

- Craft Commerce 4 or 5 (which needs the Craft **Pro** edition)
- The *Abandoned Cart Recovery* add-on active for this website in your Angie Chat dashboard
  (the plugin checks this every 5 minutes; "not active on your current plan" means the
  add-on is missing or was enabled less than 5 minutes ago)
- The **Webhook Secret** from the dashboard pasted into the plugin settings — cart data is
  sent signed, unsigned requests are rejected

### 1. Enable in settings

Go to **Settings → Plugins → Angie Chat** and enable "Abandoned Cart Recovery".

Recovery emails link to `<site>/actions/commerce/cart/load-cart?number=<cart number>` —
Commerce's own action that loads the cart into the shopper's session (works on any
device or browser) and then redirects to your `loadCartRedirectUrl`. Set that to your
cart page in `config/commerce.php`, otherwise shoppers land on the homepage with the
cart loaded:

```php
return ['*' => ['loadCartRedirectUrl' => 'shop/cart']];
```

### 2. Add the cron job

Add this line to your server's crontab (run `crontab -e`):

```
0,30 * * * * /usr/bin/php /path/to/your-craft-site/craft angie-chat/carts/check-abandoned
```

Replace `/path/to/your-craft-site/` with the absolute path to your Craft installation.

**Recommended interval:** every 30 minutes.

The inactivity threshold is Commerce's own `activeCartDuration` (default 1 hour); the
`--threshold` flag only applies when that setting is 0. Angie then waits the website's
*send delay* (dashboard, default 60 minutes) before emailing, so a shopper who comes back
on their own is never emailed.

The command finds carts that are:

- Not completed
- Have at least one item
- Have a customer email address
- Have been inactive longer than Commerce's `activeCartDuration` (default: 1 hour)

Each qualifying cart is queued once (deduplicated for 7 days via Craft's cache) and sent to the Angie Chat API for AI-powered recovery email generation.

### 3. Test it

```bash
php craft angie-chat/carts/check-abandoned
```

### 4. Exit-intent and webhooks

Exit-intent rescue (the widget stops a leaving shopper and quotes their discounts) needs
no Craft setup beyond this plugin — the cart is injected into every page automatically.
Turn it on and edit the message under **Websites → Cart Recovery** in the dashboard.

There you can also switch the channel from our recovery emails to a **webhook** into
your own system (or both). Events are signed; see
[docs/cart-webhooks](https://docs.angiechat.com/cart-webhooks) for the payload and
verification code.
