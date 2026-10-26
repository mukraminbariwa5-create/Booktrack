# BookTrack public demo on Render (free)

This runs BookTrack as a **public portfolio demo** in one container: PHP 8.3 and Apache, with the demo library in SQLite. It's an alternative to [DEPLOY.md](../DEPLOY.md) (shared PHP hosting).

- **Fresh data on every start.** The demo library (books, members, loans, fines) is rebuilt each time the container starts.
- **Demo mode is on** ([`docker/config.local.php`](config.local.php)): one-click "Sign in as librarian / member" buttons, demo accounts protected, uploads off.
- **Free plan sleep.** Render's free plan sleeps after 15 minutes without visitors and takes about a minute to wake on the next visit. Waking up also resets the demo.

## Put it online

1. Sign up at [render.com](https://render.com) with your GitHub account.
2. **New** → **Blueprint**, pick the **Booktrack** repository, and confirm. Render reads [`render.yaml`](../render.yaml) and builds the [`Dockerfile`](../Dockerfile).
3. When it says **Live**, open the address Render gives you (like `https://booktrack-demo.onrender.com`).

Every push to `main` deploys again automatically. The **Demo container** job in GitHub Actions builds and checks this image on every push.
