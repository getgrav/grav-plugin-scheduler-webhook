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
        if (!str_starts_with($route, '/scheduler/')) {
            return;
        }
        
        // Get scheduler config
        $config = $this->grav['config']->get('scheduler.modern', []);
        
        // Check if modern scheduler is enabled
        if (!($config['enabled'] ?? false)) {
            $this->sendJsonResponse(['error' => 'Modern scheduler is not enabled'], 404);
            return;
        }
        
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
        
        // Get and validate token
        $configuredToken = $config['webhook']['token'] ?? null;
        $providedToken = $this->getAuthToken();
        
        if ($configuredToken && $providedToken !== $configuredToken) {
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
        
        try {
            // Process webhook trigger
            // Check if scheduler has modern features
            $modernConfig = $this->grav['config']->get('scheduler.modern', []);
            if (($modernConfig['enabled'] ?? false) && method_exists($scheduler, 'processWebhookTrigger')) {
                $result = $scheduler->processWebhookTrigger($providedToken, $jobId);
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
                            'output' => substr($job->getOutput(), 0, 1000)
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
            // Check if scheduler has modern features
            $modernConfig = $this->grav['config']->get('scheduler.modern', []);
            if (($modernConfig['enabled'] ?? false) && method_exists($scheduler, 'getHealthStatus')) {
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
        // Check Authorization header
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        
        if ($authHeader && preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }
        
        // Check X-Webhook-Token header
        $webhookToken = $headers['X-Webhook-Token'] ?? $headers['x-webhook-token'] ?? null;
        if ($webhookToken) {
            return $webhookToken;
        }
        
        // Check query parameter (less secure, but convenient for testing)
        return $_GET['token'] ?? null;
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