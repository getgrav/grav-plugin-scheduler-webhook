<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Scheduler\ModernScheduler;
use RocketTheme\Toolbox\Event\Event;

/**
 * Scheduler Webhook Plugin
 * 
 * Provides HTTP endpoints for the Modern Scheduler:
 * - /scheduler/webhook - Trigger scheduler via webhook
 * - /scheduler/health - Check scheduler health status
 * 
 * @package    Grav\Plugin
 * @author     Trilby Media, LLC
 * @license    MIT License
 */
class SchedulerWebhookPlugin extends Plugin
{
    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            return;
        }

        // Enable the main event we are interested in
        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 0],
        ]);
    }

    /**
     * Handle scheduler routes
     */
    public function onPagesInitialized(Event $event)
    {
        $uri = $this->grav['uri'];
        $route = $uri->path();
        
        // Check if this is a scheduler route
        if (!str_starts_with((string) $route, '/scheduler/')) {
            return;
        }
        
        // Modern scheduler features are part of Grav core (2.0+) and always
        // available, so there is no separate toggle to check here. Each endpoint
        // gates on its own setting (webhook enabled, health enabled) below.

        // Route to appropriate handler
        switch ($route) {
            case '/scheduler/webhook':
                $this->handleWebhook();
                break;
                
            case '/scheduler/health':
                $this->handleHealth();
                break;
                
            default:
                $this->sendJsonResponse(['error' => 'Not found'], 404);
        }
    }
    
    /**
     * Handle webhook endpoint
     */
    protected function handleWebhook()
    {
        $config = $this->grav['config']->get('scheduler.modern', []);
        
        // Check if webhook is enabled
        if (!($config['webhook']['enabled'] ?? false)) {
            $this->sendJsonResponse(['error' => 'Webhook triggers are disabled'], 403);
            return;
        }
        
        // Check request method
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(['error' => 'Method not allowed'], 405);
            return;
        }
        
        // Get and validate token. Fail closed: an enabled webhook with no
        // configured token must not run jobs for anonymous callers. The previous
        // check short-circuited when the configured token was null, so an
        // unconfigured token accepted every request. Require a token to be set,
        // then compare with a timing-safe check. (GHSA-xwv3-2mv2-w33x / CVE-2026-57852)
        $configuredToken = $config['webhook']['token'] ?? null;
        $providedToken = $this->getAuthToken();

        if (!is_string($configuredToken) || $configuredToken === '') {
            $this->sendJsonResponse(['error' => 'Webhook token is not configured'], 403);
            return;
        }

        if (!is_string($providedToken) || !hash_equals($configuredToken, $providedToken)) {
            $this->sendJsonResponse(['error' => 'Invalid authorization token'], 401);
            return;
        }

        // Get scheduler instance
        $scheduler = $this->getScheduler();
        if (!$scheduler) {
            $this->sendJsonResponse(['error' => 'Scheduler not available'], 500);
            return;
        }
        
        // Get job ID from query parameters
        $jobId = $_GET['job'] ?? null;

        // ?mode=due|overdue|all. Left out, core decides: it catches up on missed slots, since a
        // webhook is rarely pinged on the exact minute a job is set for.
        $mode = isset($_GET['mode']) && is_string($_GET['mode']) && $_GET['mode'] !== '' ? $_GET['mode'] : null;

        try {
            // Process webhook trigger via core's modern scheduler when available
            if (method_exists($scheduler, 'processWebhookTrigger')) {
                $result = null === $mode
                    ? $scheduler->processWebhookTrigger($providedToken, $jobId)
                    : $scheduler->processWebhookTrigger($providedToken, $jobId, $mode);
            } else {
                // Fallback for standard scheduler behavior
                if ($jobId) {
                    $job = $scheduler->getJob($jobId);
                    if ($job) {
                        // Force run the job regardless of schedule (manual override)
                        $job->inForeground()->run();
                        $result = [
                            'success' => $job->isSuccessful(),
                            'message' => $job->isSuccessful() ? 'Job executed successfully' : 'Job execution failed',
                            'job_id' => $jobId,
                            'forced' => true,
                            'output' => substr((string) $job->getOutput(), 0, 1000)
                        ];
                    } else {
                        $result = ['success' => false, 'message' => 'Job not found: ' . $jobId];
                    }
                } else {
                    // Run all due jobs
                    $scheduler->run();
                    $result = [
                        'success' => true,
                        'message' => 'Scheduler executed (due jobs only)',
                        'timestamp' => date('c')
                    ];
                }
            }
            
            $statusCode = $result['success'] ? 200 : 400;
            $this->sendJsonResponse($result, $statusCode);
            
        } catch (\Exception $e) {
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Scheduler execution failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle health check endpoint
     */
    protected function handleHealth()
    {
        $config = $this->grav['config']->get('scheduler.modern', []);
        
        // Check if health endpoint is enabled
        if (!($config['health']['enabled'] ?? true)) {
            $this->sendJsonResponse(['error' => 'Health check is disabled'], 403);
            return;
        }
        
        // Get scheduler instance
        $scheduler = $this->getScheduler();
        if (!$scheduler) {
            $this->sendJsonResponse([
                'status' => 'error',
                'message' => 'Scheduler not available'
            ], 500);
            return;
        }
        
        try {
            // Use core's modern scheduler health status when available
            if (method_exists($scheduler, 'getHealthStatus')) {
                // Use enhanced scheduler's health status
                $health = $scheduler->getHealthStatus();
            } else {
                // Fallback for standard scheduler
                $health = $this->getBasicHealthStatus($scheduler);
            }
            
            $this->sendJsonResponse($health);
            
        } catch (\Exception $e) {
            $this->sendJsonResponse([
                'status' => 'error',
                'message' => 'Failed to get health status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get basic health status for standard scheduler
     * 
     * @param mixed $scheduler
     * @return array
     */
    protected function getBasicHealthStatus($scheduler): array
    {
        $lastRunFile = GRAV_ROOT . '/logs/lastcron.run';
        $lastRun = null;
        $lastRunAge = null;
        
        if (file_exists($lastRunFile)) {
            $lastRunContent = file_get_contents($lastRunFile);
            if ($lastRunContent) {
                $lastRun = $lastRunContent;
                $lastRunTime = strtotime($lastRunContent);
                if ($lastRunTime) {
                    $lastRunAge = time() - $lastRunTime;
                }
            }
        }
        
        $status = 'unknown';
        if ($lastRunAge !== null) {
            if ($lastRunAge < 600) { // Less than 10 minutes
                $status = 'healthy';
            } elseif ($lastRunAge < 3600) { // Less than 1 hour
                $status = 'warning';
            } else {
                $status = 'critical';
            }
        }
        
        $jobs = $scheduler->getAllJobs();
        
        return [
            'status' => $status,
            'last_run' => $lastRun,
            'last_run_age' => $lastRunAge,
            'scheduled_jobs' => count($jobs),
            'modern_features' => $this->grav['config']->get('scheduler.modern.enabled', false),
            'webhook_enabled' => $this->grav['config']->get('scheduler.modern.webhook.enabled', false),
            'health_check_enabled' => true,
            'timestamp' => date('c')
        ];
    }
    
    /**
     * Get scheduler instance
     * 
     * @return mixed
     */
    protected function getScheduler()
    {
        // The scheduler should already be initialized by the service provider
        // Just return it directly
        return $this->grav['scheduler'];
    }
    
    /**
     * Get authorization token from request
     * 
     * @return string|null
     */
    protected function getAuthToken(): ?string
    {
        // Normalise request headers to a lowercase-keyed map so lookups are
        // case-insensitive regardless of the SAPI in use.
        $headers = [];
        foreach ($this->getRequestHeaders() as $key => $value) {
            $headers[strtolower($key)] = $value;
        }

        // Prefer the custom X-Webhook-Token header. Unlike Authorization, custom
        // headers are not stripped by Apache/PHP-FPM (FastCGI), so this works on
        // setups where "Authorization: Bearer" never reaches PHP.
        if (!empty($headers['x-webhook-token'])) {
            return trim((string) $headers['x-webhook-token']);
        }

        // Bearer token via the Authorization header. Many FastCGI/PHP-FPM servers
        // drop Authorization unless the vhost or .htaccess forwards it, so also
        // check the forwarded $_SERVER copies some setups expose instead.
        $authHeader = $headers['authorization']
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if ($authHeader && preg_match('/Bearer\s+(.+)$/i', (string) $authHeader, $matches)) {
            return trim($matches[1]);
        }

        // Query parameter (least secure, but convenient for testing or cron URLs).
        return isset($_GET['token']) ? (string) $_GET['token'] : null;
    }

    /**
     * Collect the incoming request headers, working around SAPIs where
     * getallheaders() is unavailable or returns nothing.
     *
     * @return array<string, string>
     */
    protected function getRequestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (!empty($headers)) {
                return $headers;
            }
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (!empty($headers)) {
                return $headers;
            }
        }

        // Fallback: reconstruct headers from the $_SERVER HTTP_* entries.
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
    
    /**
     * Send JSON response and exit
     * 
     * @param array $data
     * @param int $statusCode
     */
    protected function sendJsonResponse(array $data, int $statusCode = 200)
    {
        // Set response headers
        http_response_code($statusCode);
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        
        // Add CORS headers if configured
        $config = $this->grav['config']->get('scheduler.modern.webhook.cors', false);
        if ($config) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, X-Webhook-Token, Content-Type');
        }
        
        // Send response
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
}