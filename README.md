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
- Modern Scheduler enabled in Grav configuration
- PHP 7.3.6 or higher

## Configuration

### 1. Enable Modern Scheduler

First, enable the Modern Scheduler in your Grav configuration:

**Admin Panel:**
1. Navigate to Configuration → Scheduler
2. Click on "Modern Features" tab
3. Toggle "Enable Modern Scheduler" to ON
4. Save configuration

**Manual Configuration:**

Edit `user/config/scheduler.yaml`:

```yaml
modern:
  enabled: true
  
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
**Authentication:** Bearer token (optional but recommended)

#### Trigger All Due Jobs

```bash
curl -X POST https://your-site.com/scheduler/webhook \
  -H "Authorization: Bearer your-secure-token-here"
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
  -H "Authorization: Bearer your-secure-token-here"
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
            -H "Authorization: Bearer ${{ secrets.SCHEDULER_TOKEN }}" \
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
        -H "Authorization: Bearer ${SCHEDULER_TOKEN}" \
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
            'Authorization': f'Bearer {SCHEDULER_TOKEN}'
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
                -H "Authorization: Bearer ${SCHEDULER_TOKEN}" \
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
- Check that Modern Scheduler is enabled in configuration
- Clear Grav cache: `bin/grav cache`

### Authentication Failures

- Verify token matches exactly (no extra spaces)
- Check token is properly configured in `scheduler.yaml`
- Ensure Authorization header format: `Bearer YOUR_TOKEN`

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
  -H "Authorization: Bearer test-token"
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