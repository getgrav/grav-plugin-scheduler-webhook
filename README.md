# Scheduler Webhook Plugin

The **Scheduler Webhook** plugin provides HTTP endpoints for Grav's Modern Scheduler, enabling webhook triggers and health monitoring for cloud environments, CI/CD pipelines, and external monitoring systems.

## Features

- 🔗 **Webhook Triggers** - Trigger scheduler via HTTP POST requests
- 💚 **Health Monitoring** - Check scheduler status via HTTP GET requests  
- 🔒 **Token Authentication** - Secure webhook access with bearer tokens
- 🌐 **CORS Support** - Optional cross-origin request support
- ☁️ **Cloud Ready** - Perfect for serverless and containerized deployments

## Installation

### GPM Installation (Preferred)

```bash
bin/gpm install scheduler-webhook
```

### Manual Installation

1. Download the plugin from GitHub
2. Extract to `/user/plugins/scheduler-webhook`
3. Run `bin/grav install` to install dependencies

## Requirements

- Grav v1.7.0 or higher
- PHP 7.3.6 or higher

## Configuration

### 1. Enable the Webhook Trigger

The scheduler's modern features are part of Grav core, so there is no separate "modern scheduler" toggle to turn on. You just need to enable the webhook trigger and set a token.

**Admin Panel:**
1. Navigate to Configuration → Scheduler
2. Click on "Modern Features" tab
3. Enable the webhook trigger and set a token
4. Save configuration

**Manual Configuration:**

Edit `user/config/scheduler.yaml`:

```yaml
modern:
  webhook:
    enabled: true
    token: 'your-secure-token-here'  # Generate with: openssl rand -hex 32
    
  health:
    enabled: true
```

### 2. Enable the Plugin

The plugin is enabled by default once installed. You can configure it via:

**Admin Panel:**
Configuration → Plugins → Scheduler Webhook

**Manual Configuration:**

Edit `user/config/plugins/scheduler-webhook.yaml`:

```yaml
enabled: true
cors: false  # Set to true if you need cross-origin requests
```

## Usage

### Webhook Endpoint

**URL:** `/scheduler/webhook`  
**Method:** POST  
**Authentication:** token (required)

The token can be sent three ways, checked in this order:

1. **`X-Webhook-Token` header (recommended)** — `X-Webhook-Token: your-secure-token-here`
2. **`Authorization` header** — `Authorization: Bearer your-secure-token-here` *(server-dependent — many Apache + PHP-FPM/FastCGI hosts strip this header before it reaches PHP; see the note below)*
3. **`token` query parameter** — `?token=your-secure-token-here`

> **The header is `X-Webhook-Token`** — not `X-API-Token`, which belongs to the separate Grav API plugin. Using the wrong header name is a common cause of a `401 Invalid authorization token`.

> **Why `X-Webhook-Token` is recommended:** many Apache + PHP-FPM/FastCGI setups strip the `Authorization` header before it reaches PHP, which makes Bearer tokens fail with a 401. Custom headers like `X-Webhook-Token` are never stripped, so they work everywhere without server changes. If you prefer Bearer, see [Authentication Failures](#authentication-failures) for the `.htaccess` rule that forwards the header.

#### Trigger All Due Jobs

```bash
curl -X POST https://your-site.com/scheduler/webhook \
  -H "X-Webhook-Token: your-secure-token-here"
```

Response:
```json
{
  "success": true,
  "message": "Scheduler executed",
  "jobs_run": 3,
  "timestamp": "2025-01-24T10:30:00+00:00"
}
```

#### Trigger Specific Job (Force Run)

When you specify a job ID, the webhook will **force-run** that job immediately, regardless of its schedule. This is useful for manual triggers or CI/CD pipelines.

```bash
curl -X POST https://your-site.com/scheduler/webhook?job=backup \
  -H "X-Webhook-Token: your-secure-token-here"
```

Response:
```json
{
  "success": true,
  "message": "Job force-executed successfully",
  "job_id": "backup",
  "forced": true,
  "output": "Backup completed successfully"
}
```

**Note:** This is a manual override - the job runs immediately even if it's not scheduled to run at this time.

### Health Check Endpoint

**URL:** `/scheduler/health`  
**Method:** GET  
**Authentication:** None required (configurable)

```bash
curl https://your-site.com/scheduler/health
```

Response:
```json
{
  "status": "healthy",
  "last_run": "2025-01-24T10:30:00+00:00",
  "last_run_age": 120,
  "queue_size": 0,
  "failed_jobs_24h": 0,
  "scheduled_jobs": 3,
  "modern_features": true,
  "webhook_enabled": true,
  "health_check_enabled": true,
  "timestamp": "2025-01-24T10:32:00+00:00"
}
```

Status values:
- `healthy` - Last run within 10 minutes
- `warning` - Last run within 1 hour
- `critical` - Last run over 1 hour ago
- `unknown` - No run history found

## Integration Examples

### GitHub Actions

`.github/workflows/scheduler.yml`:

```yaml
name: Trigger Grav Scheduler
on:
  schedule:
    - cron: '*/5 * * * *'  # Every 5 minutes
  workflow_dispatch:  # Manual trigger

jobs:
  trigger:
    runs-on: ubuntu-latest
    steps:
      - name: Trigger Scheduler
        run: |
          curl -X POST ${{ secrets.SITE_URL }}/scheduler/webhook \
            -H "X-Webhook-Token: ${{ secrets.SCHEDULER_TOKEN }}" \
            -f  # Fail on HTTP errors
            
      - name: Check Health
        run: |
          curl ${{ secrets.SITE_URL }}/scheduler/health \
            -f  # Fail on HTTP errors
```

### GitLab CI/CD

`.gitlab-ci.yml`:

```yaml
trigger-scheduler:
  stage: deploy
  script:
    - |
      curl -X POST ${SITE_URL}/scheduler/webhook \
        -H "X-Webhook-Token: ${SCHEDULER_TOKEN}" \
        --fail
  only:
    - schedules
```

### AWS Lambda

```python
import json
import urllib3

def lambda_handler(event, context):
    http = urllib3.PoolManager()
    
    response = http.request(
        'POST',
        'https://your-site.com/scheduler/webhook',
        headers={
            'X-Webhook-Token': SCHEDULER_TOKEN
        }
    )
    
    return {
        'statusCode': response.status,
        'body': response.data.decode('utf-8')
    }
```

### Docker/Kubernetes CronJob

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: grav-scheduler
spec:
  schedule: "*/5 * * * *"
  jobTemplate:
    spec:
      template:
        spec:
          containers:
          - name: scheduler-trigger
            image: curlimages/curl:latest
            args:
            - /bin/sh
            - -c
            - |
              curl -X POST https://your-site.com/scheduler/webhook \
                -H "X-Webhook-Token: ${SCHEDULER_TOKEN}" \
                --fail
          restartPolicy: OnFailure
```

### Monitoring with Uptime Kuma

Add a new monitor:
- Monitor Type: HTTP(s)
- URL: `https://your-site.com/scheduler/health`
- Method: GET
- Expected Status Code: 200
- Heartbeat Interval: 60 seconds

### Monitoring with Pingdom

Create a new uptime check:
- Check type: HTTP
- URL: `https://your-site.com/scheduler/health`
- Check for string: `"status":"healthy"`

## Security

### Token Generation

Generate a secure token:

```bash
# Linux/Mac
openssl rand -hex 32

# Or use PHP
php -r "echo bin2hex(random_bytes(32));"
```

### Best Practices

1. **Always use HTTPS** in production
2. **Use strong tokens** (at least 32 characters)
3. **Rotate tokens** periodically
4. **Restrict access** via firewall rules if possible
5. **Monitor access logs** for suspicious activity
6. **Use rate limiting** with a reverse proxy

### Nginx Rate Limiting Example

```nginx
http {
    limit_req_zone $binary_remote_addr zone=scheduler:10m rate=1r/m;
    
    server {
        location /scheduler/webhook {
            limit_req zone=scheduler burst=5;
            proxy_pass http://localhost:8080;
        }
    }
}
```

## Troubleshooting

### Webhook Returns 404

- Ensure the plugin is enabled
- Clear Grav cache: `bin/grav cache`

### Webhook Returns 403

- `Webhook triggers are disabled`: enable `modern.webhook.enabled` in `user/config/scheduler.yaml`.
- `Webhook token is not configured`: set a nonempty `modern.webhook.token` in the same file before sending requests.

### Authentication Failures

If you get `401 Invalid authorization token`:

- Verify the token matches exactly (no extra spaces)
- Check the token is properly configured in `scheduler.yaml`
- **Confirm the header name is `X-Webhook-Token`, not `X-API-Token`** — the latter belongs to the Grav API plugin and this endpoint ignores it
- **If you are using `Authorization: Bearer` and it always 401s, your server is most likely stripping the `Authorization` header** (common on Apache with PHP-FPM/FastCGI). Either switch to the `X-Webhook-Token` header, which is never stripped, or forward the header to PHP by adding this to your Grav `.htaccess`, just after `RewriteEngine On`:

  ```apache
  RewriteCond %{HTTP:Authorization} .
  RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
  ```

### Health Check Shows Critical

- Check cron/systemd timer is configured
- Verify scheduler is running: `bin/grav scheduler --run`
- Check logs: `tail -f logs/grav.log`

### CORS Issues

Enable CORS in plugin configuration:

```yaml
# user/config/plugins/scheduler-webhook.yaml
enabled: true
cors: true
```

## Development

### Testing Webhooks Locally

Use ngrok for local testing:

```bash
# Install ngrok
brew install ngrok  # Mac
# or download from https://ngrok.com

# Expose local site
ngrok http 8080

# Use the ngrok URL for testing
curl -X POST https://abc123.ngrok.io/scheduler/webhook \
  -H "X-Webhook-Token: test-token"
```

### Debug Mode

Enable Grav debugging for detailed logs:

```yaml
# user/config/system.yaml
debugger:
  enabled: true
  
errors:
  display: 1
  log: true
  
log:
  handler: file
  syslog:
    facility: local6
```

## Support

- **Documentation:** [GitHub Wiki](https://github.com/trilbymedia/grav-plugin-scheduler-webhook/wiki)
- **Issues:** [GitHub Issues](https://github.com/trilbymedia/grav-plugin-scheduler-webhook/issues)
- **Discord:** [Grav Discord](https://discord.gg/grav)

## License

MIT License - see [LICENSE](LICENSE) file for details

## Credits

Developed by [Trilby Media](https://trilby.media) for the [Grav CMS](https://getgrav.org) community.