# Raffle Ticket

A daily raffle plugin for WordPress. Logged-in users claim one ticket per day via a button; a winner is drawn automatically at site midnight. Includes a shortcode, an admin settings page, and a public read-only REST API.

## Features

- **One ticket per user per day**, enforced at the database level (not just in PHP), so it holds up under double-clicks or concurrent requests.
- **Automatic daily draw** at the site's configured midnight (Settings → General → Timezone), recalculated after every run so it self-corrects across DST changes.
- **`[raffle_ticket]` shortcode** — claim button plus an optional "My Tickets" history table.
- **Admin settings page** — enable/disable the raffle, customize text, set a minimum entry threshold, force a manual draw, view recent winners.
- **Public REST API** — today's winner, winner history, and aggregate stats.

## Installation

1. In WordPress, go to **Plugins → Add New → Upload Plugin**.
2. Choose `raffle-ticket.zip` and click **Install Now**.
3. Click **Activate**.

On activation, the plugin creates two database tables (`wp_raffle_tickets`, `wp_raffle_winners`) and schedules the first midnight draw via WP-Cron.

## Usage

### `[raffle_ticket]` — claim button + history

Add the shortcode to any page or post:

```
[raffle_ticket]
```

**Attributes:**

| Attribute | Default | Description |
|---|---|---|
| `show_history` | `yes` | Set to `no` to hide the "My Tickets" history table. |
| `history_limit` | `10` | Number of past tickets to show in the history table. |

Example:

```
[raffle_ticket show_history="no"]
```

### What the visitor sees

- **Logged out:** a message plus a login link.
- **Logged in, hasn't claimed today:** an active "Get Today's Ticket" button.
- **Logged in, already claimed:** their ticket number for today, button disabled.
- **History table** (if enabled): past tickets with date, ticket number, and win/loss result.

### `[raffle_ticket_history]` — read-only history, no claim button

A separate, standalone shortcode for places where claiming shouldn't be possible — e.g. a "My Account" page. It only ever shows the user's past tickets and results; there is no button and no way to claim a ticket from here.

```
[raffle_ticket_history]
```

**Attributes:**

| Attribute | Default | Description |
|---|---|---|
| `limit` | `20` | Number of past tickets to show. |

Example:

```
[raffle_ticket_history limit="50"]
```

If the user is logged out, it shows a login prompt instead of history. If they have no past tickets, it shows a simple "you haven't claimed any tickets yet" message rather than an empty table.

## Admin Settings

Go to **Settings → Raffle Ticket**.

- **Enable Raffle** — turn ticket claiming on/off site-wide without deactivating the plugin.
- **Button Text** — text shown on the claim button.
- **Already-Claimed Message** — shown after a user claims their ticket. Use `%d` as a placeholder for the ticket number.
- **Logged-Out Message** — shown to visitors who aren't logged in.
- **Minimum Entries to Draw** — if fewer tickets than this are claimed on a given day, no winner is drawn for that day.
- **Force Draw For Today** — manually trigger today's draw immediately (useful for testing).
- **Recent Winners** — table of past draws, including no-winner days.

## How the Daily Draw Works

WordPress's built-in "daily" cron schedule fires 24 hours after whatever moment it was first scheduled — not at actual midnight, and it drifts across Daylight Saving changes. To avoid that, this plugin schedules a single one-off event for the next midnight in the site's timezone, and each run reschedules the *next* one immediately after. This keeps the draw anchored to real midnight indefinitely.

**Important:** WP-Cron only runs when something visits the site (a page load, an API call, etc.) — there's no real background process unless you've configured one. On a low-traffic site, the draw may fire a few minutes (or longer) after midnight, whenever the next visitor or request arrives, rather than at the exact moment. If you need second-accurate timing, disable WP-Cron's default behavior and trigger `wp-cron.php` from a real system cron job instead:

```php
// In wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```cron
# In your system crontab, e.g. every minute
* * * * * curl -s https://yoursite.com/wp-cron.php >/dev/null 2>&1
```

If no draw is scheduled for any reason (a cleared cron table, a migration, etc.), the plugin re-arms it automatically on the next page load — no manual intervention needed.

## REST API

All endpoints are public (no authentication required) and read-only. They return JSON. User data is limited to `id` and `display_name` — no emails or other personal data.

### `GET /wp-json/raffle/v1/winner/today`

Returns today's winner, or a `drawn: false` message if the draw hasn't run yet.

```json
{
  "raffle_date": "2026-06-19",
  "winner": { "id": 42, "display_name": "Jane D." },
  "ticket_number": 7,
  "total_entries": 23,
  "picked_at": "2026-06-20 00:00:03",
  "drawn": true
}
```

### `GET /wp-json/raffle/v1/winners`

Paginated winner history, most recent first.

**Query params:** `page` (default `1`), `per_page` (default `20`, max `100`)

```json
{
  "page": 1,
  "per_page": 20,
  "winners": [
    { "raffle_date": "2026-06-19", "winner": { "id": 42, "display_name": "Jane D." }, "ticket_number": 7, "total_entries": 23, "picked_at": "2026-06-20 00:00:03" },
    { "raffle_date": "2026-06-18", "winner": null, "ticket_number": null, "total_entries": 0, "picked_at": "2026-06-19 00:00:02" }
  ]
}
```

### `GET /wp-json/raffle/v1/stats`

```json
{
  "today_ticket_count": 23,
  "total_ticket_count": 1840,
  "total_winner_count": 95,
  "raffle_date": "2026-06-19"
}
```

## Database Schema

**`wp_raffle_tickets`**

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT, PK | |
| `user_id` | BIGINT | |
| `raffle_date` | DATE | |
| `ticket_number` | INT | Sequential per day (1, 2, 3…) |
| `created_at` | DATETIME | |

Unique key on `(user_id, raffle_date)` — this is what actually enforces "one ticket per day," at the database layer.

**`wp_raffle_winners`**

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT, PK | |
| `raffle_date` | DATE | Unique — one draw per day |
| `user_id` | BIGINT, nullable | Null if no winner was drawn (below minimum entries) |
| `ticket_number` | INT, nullable | |
| `total_entries` | INT | Entry count for that day, recorded regardless of outcome |
| `picked_at` | DATETIME | |

## Developer Hook

```php
do_action( 'raffle_ticket_winner_drawn', $date, $user_id, $ticket_number );
```

Fires immediately after a winner is recorded. Use this to send notifications, post to Slack, trigger an n8n webhook, etc.

## File Structure

```
raffle-ticket/
├── raffle-ticket.php                          # Plugin bootstrap
├── includes/
│   ├── class-raffle-ticket-db.php             # Schema + data access
│   ├── class-raffle-ticket-cron.php           # Midnight scheduling + draw execution
│   ├── class-raffle-ticket-shortcode.php      # [raffle_ticket] rendering
│   ├── class-raffle-ticket-ajax.php           # Claim button AJAX handler
│   └── class-raffle-ticket-rest.php           # Public REST API
├── admin/
│   └── class-raffle-ticket-admin.php          # Settings page
├── assets/
│   ├── css/raffle-ticket.css
│   └── js/raffle-ticket.js
└── vendor/
    └── plugin-update-checker/                 # Vendored GitHub update library
```

## Updating from GitHub

This plugin checks **GitHub Releases** for updates instead of WordPress.org, using the [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) library (vendored in `vendor/plugin-update-checker/`). Once installed, "Update available" notices appear on the Plugins page exactly like any WordPress.org plugin — no extra setup needed on the WordPress side.

**Source repo:** [arellaeast/Raffle-Ticket-WordPress](https://github.com/arellaeast/Raffle-Ticket-WordPress)

### Publishing a new version

For WordPress to detect a new version, every release must follow this sequence:

1. Bump the `Version:` header in `raffle-ticket.php` (and the `RAFFLE_TICKET_VERSION` constant just below it) to the new version number.
2. Commit and push.
3. On GitHub, create an actual **Release** (Releases → Draft a new release) tagged with the new version (e.g. `v1.2.0`). A pushed tag or commit alone is **not** enough — it must be a Release, since that's what the update checker queries.
4. GitHub auto-generates a source ZIP for the release. As long as the repo's top-level structure matches the plugin's folder layout (it does here), that auto-generated ZIP works as the update package — no manual ZIP upload required.

WordPress checks for updates periodically and whenever an admin visits the Plugins page; there's no need to do anything further once the release is published.

### Notes

- The repo is public, so no access token is required.
- If a release's version tag is *not* higher than the currently installed `Version:` header, WordPress won't offer the update — version numbers must increase.
- The vendored update-checker library only runs in `wp-admin` contexts; it has no effect on front-end performance.

## Requirements

- WordPress 5.0+
- PHP 7.4+
- MySQL/MariaDB with standard `wpdb` access (no special extensions)

## Known Limitations

- WP-Cron timing is "best effort" without a real system cron trigger (see above).
- No built-in admin UI for exporting tickets/winners to CSV.
- No email or webhook notification on winner draw out of the box — use the `raffle_ticket_winner_drawn` action hook to add one.

