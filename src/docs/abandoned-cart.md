# Abandoned Cart Setup

Craft Commerce has no native "cart abandoned" event — carts are simply purged after they expire. The plugin detects abandonment via a console command that you schedule with cron.

### 1. Enable in settings

Go to **Settings → Plugins → Angie Chat** and enable "Abandoned Cart Recovery".

### 2. Add the cron job

Add this line to your server's crontab (run `crontab -e`):

```
0,30 * * * * /usr/bin/php /path/to/your-craft-site/craft angie-chat/carts/check-abandoned
```

Replace `/path/to/your-craft-site/` with the absolute path to your Craft installation.

**Recommended interval:** every 30 minutes.

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
