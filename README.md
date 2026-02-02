=== Easy Github Deploy ===
Contributors: jaredlambert
Tags: github, deploy, headless, jamstack, workflow, actions
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Trigger GitHub Actions workflow deployments when WordPress content changes. Perfect for headless WordPress sites.

== Description ==

Easy Github Deploy automatically triggers GitHub Actions workflows when content changes in WordPress. This is ideal for headless WordPress setups where your frontend is built with frameworks like Next.js, Gatsby, Nuxt, or Astro.

= Features =

* **Automatic Deploys** - Trigger deploys when posts, pages, menus, or taxonomies change
* **Manual Deploy Button** - One-click deploy from the WordPress admin
* **Debounce/Batching** - Wait X minutes before deploying to batch multiple changes
* **ACF Integration** - Smart handling of Advanced Custom Fields updates
* **Field Group Batching** - When updating ACF field groups, deploy once instead of per-post
* **Configurable Triggers** - Choose which post types and content changes trigger deploys
* **Deploy History** - Track recent deployments and their status
* **Connection Validation** - Verify your GitHub credentials before saving

= Use Cases =

* Headless WordPress with a static site generator
* JAMstack sites with WordPress as a content API
* Any workflow where WordPress content changes should trigger a build
* Continuous deployment pipelines

= Requirements =

* WordPress 5.8 or higher
* PHP 7.4 or higher
* A GitHub repository with a workflow file configured for `workflow_dispatch`
* A GitHub Personal Access Token with `repo` and `workflow` scopes

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wp-github-deploy/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings → Easy Github Deploy to configure the plugin
4. Enter your GitHub Personal Access Token, repository details, and workflow file
5. Click "Validate" to verify the connection
6. Configure your deploy settings and save

= GitHub Workflow Setup =

Your GitHub workflow file needs to support `workflow_dispatch`. Here's an example:

```yaml
name: Deploy

on:
  workflow_dispatch:
    inputs:
      trigger_source:
        description: 'What triggered this deploy'
        required: false
        type: string

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      
      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          
      - name: Install dependencies
        run: npm ci
        
      - name: Build
        run: npm run build
        
      - name: Deploy
        run: npm run deploy
```

== Frequently Asked Questions ==

= How do I create a GitHub Personal Access Token? =

1. Go to GitHub → Settings → Developer settings → Personal access tokens → Tokens (classic)
2. Click "Generate new token (classic)"
3. Give it a descriptive name like "WordPress Deploy"
4. Select the `repo` and `workflow` scopes
5. Click "Generate token" and copy it immediately

= What is debouncing? =

Debouncing prevents multiple rapid deploys when you're making several changes. If you set a 5-minute debounce, the plugin waits 5 minutes after the last change before triggering a deploy. This batches multiple changes into a single deployment.

= How does ACF field group batching work? =

When you update an ACF field group, WordPress may re-save all posts using that field group. Without batching, this would trigger a deploy for each post. With batching enabled, the plugin detects this pattern and only triggers one deploy after all posts are updated.

= Can I trigger a deploy manually? =

Yes! Click the "Deploy Now" button at the top of the settings page. This bypasses any pending debounced deploy and triggers immediately.

= What happens if a deploy fails? =

Failed deploys are logged in the history with the error message. You can view the full error by hovering over the "Failed" status or checking your GitHub Actions logs.

== Screenshots ==

1. Settings page with GitHub configuration
2. Deploy status and pending deploy countdown
3. Deploy history showing recent deployments

== Changelog ==

= 1.0.0 =
* Initial release
* GitHub Actions workflow dispatch integration
* Automatic deploys on content changes
* Debounce/batching system
* ACF field group update batching
* Manual deploy button
* Deploy history logging
* Connection validation

== Upgrade Notice ==

= 1.0.0 =
Initial release of Easy Github Deploy.

