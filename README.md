# Color War Dashboard (PHP + SQLite)

A lightweight PHP/HTML application for a team-based **Color War** scoreboard with users, teams, and an admin dashboard for managing points. Includes a simple **Integrations** framework with a secure webhook to add/remove points based on events from external systems.

## Features
- Public leaderboard with team totals and individual user rankings
- Admin dashboard with:
  - Teams: create, edit (name, color), delete
  - Users: create, edit, assign to team, deactivate, bulk CSV import
  - Points: award/remove points to users or teams (with reason & source)
  - Integrations: create webhook rules with per-event tokens + default points
  - Audit Log: view last actions (who/what/when)
- Secure session auth, CSRF protection, prepared statements
- SQLite database (single file) with WAL and foreign keys

## Requirements
- PHP 8.1+ with SQLite enabled (`pdo_sqlite`)
- Web server (Apache/Nginx) or PHP built-in server

## Quick Start (Built-in Server)
1. **Initialize the database** (sets org & admin):
   ```bash
   php -S localhost:8000 -t public
   # In your browser, visit http://localhost:8000/setup.php
   # Fill Org + Admin user (email/password)
   ```
2. **Visit the dashboard** at http://localhost:8000/
3. **Open Admin** at http://localhost:8000/admin/login.php (use your admin credentials)

## Integrations
- Create an Integration rule in Admin → Integrations
- It generates a **token** and optional default points per `event` name
- Call the webhook:
  ```bash
  curl -X POST http://localhost:8000/api/webhook.php?token=YOUR_TOKEN     -H "Content-Type: application/json"     -d '{"event":"phishing_report","email":"user@example.com","points":1,"reason":"Reported phishing"}'
  ```
- The server will add (or remove if negative) points for the matched user/team and log to the audit log.

### Webhook JSON fields
- `event` (string, required): event name that must match an enabled integration rule for the provided token.
- `email` (string, optional): for user matching. You can also use `external_user_id`.
- `external_user_id` (string, optional): alternate user matching key.
- `team_slug` (string, optional): fallback if no user is provided; awards to a team instead.
- `points` (int, optional): override the integration's default `points` value for this call.
- `reason` (string, optional): free text explaining why points were awarded/removed.
- `meta` (object, optional): will be stored in the audit log.

> Tip: Use negative points to remove/penalize.

## CSV Import (Admin → Users)
Upload a CSV with headers: `email,name,team_slug,external_user_id`.
- Unknown `team_slug` will be created automatically.
- Existing users are updated (by email).

## Security Notes
- Change the admin password immediately after setup.
- Each Integration has its own token. Treat tokens like secrets.
- CSRF tokens are used for all admin POST actions.
- Sessions use a custom name and `httponly` & `samesite` flags.

## File Structure
```
public/
  index.php        # public leaderboard
  style.css        # minimal styling
  setup.php        # one-time initialization wizard
admin/
  login.php        # admin login
  index.php        # admin dashboard (nav)
  teams.php        # CRUD teams
  users.php        # CRUD users + CSV import
  points.php       # manual award/remove points
  integrations.php # webhook rules
  audit.php        # audit viewer
  logout.php
api/
  webhook.php      # inbound integrations
  award.php        # admin-only award endpoint
  export.php       # CSV exports
lib/
  bootstrap.php    # brings config/db/auth/helpers/csrf together
  config.php
  db.php
  auth.php
  helpers.php
  csrf.php
data/              # SQLite file created after setup
```

## License
MIT
