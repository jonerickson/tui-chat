<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\Room;
use Exception;
use Illuminate\Console\Command;
use React\EventLoop\Loop;
use React\Socket\Connection;
use React\Socket\SocketServer;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand('chat:server', 'Starts the chat server.')]
class StartServer extends Command
{
    protected $signature = 'chat:server {--port=2785}';

    private array $connections = [];

    private array $rooms = [];

    private array $logMessages = [];

    private int $maxLogMessages = 100;

    private int $terminalHeight = 24;

    private int $terminalWidth = 80;

    private int $rateLimitMessages = 5;

    private int $rateLimitWindow = 10;

    public function handle(): void
    {
        system('stty -icanon -echo');

        $size = explode(' ', shell_exec('stty size') ?? '24 80');
        $this->terminalHeight = (int) ($size[0] ?? 24);
        $this->terminalWidth = (int) ($size[1] ?? 80);

        $this->trap([SIGINT, SIGTERM], function () {
            $this->cleanup();
            exit(0);
        });

        $loop = Loop::get();
        $server = new SocketServer('127.0.0.1:'.$this->option('port') ?? '2785');

        $server->on('connection', function (Connection $connection) use (&$connections) {
            $connectionId = spl_object_hash($connection);
            $this->connections[$connectionId] = [
                'connection' => $connection,
                'username' => null,
                'room' => null,
                'timestamps' => [],
            ];

            $this->log('connection', "New connection: $connectionId");

            $connection->on('data', function ($data) use ($connectionId) {
                $this->handleData($connectionId, trim($data));
            });

            $connection->on('close', function () use ($connectionId) {
                $this->handleDisconnection($connectionId);
            });

            $connection->on('error', function ($error) use ($connectionId) {
                $this->log('error', "Connection error for $connectionId: ".$error->getMessage());
                $this->handleDisconnection($connectionId);
            });
        });

        $this->log('info', "Server listening on port {$this->option('port')}...");
        $this->redrawScreen();

        $loop->run();
    }

    private function handleData(string $connectionId, string $data): void
    {
        try {
            $message = json_decode($data, true);

            if (! $message) {
                $this->log('warning', "Invalid JSON received from {$connectionId}");

                return;
            }

            match ($message['type'] ?? 'chat') {
                'join' => $this->handleJoin($connectionId, $message),
                'chat' => $this->handleMessage($connectionId, $message),
                'leave' => $this->handleDisconnection($connectionId),
                default => $this->log('warning', "Unknown message type from $connectionId: ".($message['type'] ?? 'none'))
            };
        } catch (Exception $e) {
            $this->log('error', "Error handling message from {$connectionId}: ".$e->getMessage());
        }
    }

    private function handleJoin(string $connectionId, array $message): void
    {
        $username = $message['username'] ?? 'Anonymous';
        $room = $message['room'] ?? 'general';

        $this->connections[$connectionId]['username'] = $username;
        $this->connections[$connectionId]['room'] = $room;

        if (! isset($this->rooms[$room])) {
            $this->rooms[$room] = [];
        }

        $this->rooms[$room][] = $connectionId;

        $this->log('join', "$username joined room #$room");

        $joinMessage = [
            'type' => 'system',
            'room' => $room,
            'message' => "$username joined the chat.",
            'timestamp' => date('H:i:s'),
            'username' => 'System',
        ];

        $this->broadcastToRoom($room, $joinMessage, $connectionId);

        $this->sendToConnection($connectionId, [
            'type' => 'system',
            'message' => "Welcome to room #$room!",
            'timestamp' => date('H:i:s'),
            'username' => 'System',
        ]);
    }

    private function handleMessage(string $connectionId, array $message): void
    {
        $connection = $this->connections[$connectionId] ?? null;

        if (! $connection || ! $connection['username'] || ! $connection['room']) {
            $this->log('warning', "Message from unregistered connection: $connectionId");

            return;
        }

        if ($this->isRateLimited($connectionId)) {
            $this->log('warning', "Rate limit exceeded for {$connection['username']}");
            $this->sendToConnection($connectionId, [
                'type' => 'system',
                'message' => "You're sending messages too quickly. Please slow down.",
                'timestamp' => date('H:i:s'),
                'username' => 'System',
            ]);

            return;
        }

        $chatMessage = [
            'type' => 'chat',
            'username' => $connection['username'],
            'room' => $connection['room'],
            'message' => $message['message'] ?? '',
            'timestamp' => date('H:i:s'),
        ];

        try {
            $room = Room::firstOrCreate(['slug' => $connection['room']], [
                'name' => ucfirst($connection['room']),
                'slug' => $connection['room'],
            ]);

            Message::create([
                'room_id' => $room->id,
                'username' => $connection['username'],
                'content' => $chatMessage['message'],
                'sent_at' => now(),
            ]);
        } catch (Exception $e) {
            $this->log('warning', 'Could not save message to database: '.$e->getMessage());
        }

        $this->log('chat', "[{$connection['room']}] {$connection['username']}: ".$chatMessage['message']);
        $this->broadcastToRoom($connection['room'], $chatMessage, $connectionId);
    }

    private function handleDisconnection(string $connectionId): void
    {
        $connection = $this->connections[$connectionId] ?? null;

        if ($connection && $connection['username'] && $connection['room']) {
            $username = $connection['username'];
            $room = $connection['room'];

            if (isset($this->rooms[$room])) {
                $this->rooms[$room] = array_filter($this->rooms[$room], function ($id) use ($connectionId) {
                    return $id !== $connectionId;
                });

                if (empty($this->rooms[$room])) {
                    unset($this->rooms[$room]);
                }
            }

            $this->log('leave', "$username left room #$room");

            $leaveMessage = [
                'type' => 'system',
                'room' => $room,
                'message' => "$username left the chat.",
                'timestamp' => date('H:i:s'),
                'username' => 'System',
            ];

            $this->broadcastToRoom($room, $leaveMessage);
        }

        unset($this->connections[$connectionId]);
    }

    private function broadcastToRoom(string $room, array $message, $excludeConnectionId = null): void
    {
        if (! isset($this->rooms[$room])) {
            return;
        }

        foreach ($this->rooms[$room] as $connectionId) {
            if ($connectionId !== $excludeConnectionId) {
                $this->sendToConnection($connectionId, $message);
            }
        }
    }

    private function sendToConnection($connectionId, $message): void
    {
        if (isset($this->connections[$connectionId])) {
            try {
                $this->connections[$connectionId]['connection']->write(json_encode($message).PHP_EOL);
            } catch (Exception $e) {
                $this->log('error', "Failed to send message to {$connectionId}: ".$e->getMessage());
                $this->handleDisconnection($connectionId);
            }
        }
    }

    private function log(string $type, string $message): void
    {
        $this->logMessages[] = [
            'type' => $type,
            'message' => $message,
            'timestamp' => date('H:i:s'),
        ];

        if (count($this->logMessages) > $this->maxLogMessages) {
            $this->logMessages = array_slice($this->logMessages, -$this->maxLogMessages);
        }

        $this->redrawScreen();
    }

    private function redrawScreen(): void
    {
        // Move cursor to home position and clear screen
        echo "\033[H\033[J";

        // Draw header
        $title = '🗨️  TUI Chat Server';
        $subtitle = "Port: {$this->option('port')} | Connections: ".count($this->connections).' | Rooms: '.count($this->rooms);

        echo str_repeat('═', $this->terminalWidth).PHP_EOL;
        echo center($title, $this->terminalWidth).PHP_EOL;
        echo center($subtitle, $this->terminalWidth).PHP_EOL;
        echo str_repeat('═', $this->terminalWidth).PHP_EOL;

        // Calculate how many lines we have for logs
        // Header = 4 lines, Footer = 1 line
        $logLines = $this->terminalHeight - 5;

        // Display log messages
        $logsToShow = array_slice($this->logMessages, -$logLines);
        foreach ($logsToShow as $log) {
            $this->renderLog($log);
        }

        // Fill remaining space with blank lines
        $blankLines = $logLines - count($logsToShow);
        for ($i = 0; $i < $blankLines; $i++) {
            echo PHP_EOL;
        }

        // Draw footer
        echo str_repeat('─', $this->terminalWidth);

        // Ensure output is flushed
        flush();
    }

    private function renderLog(array $log): void
    {
        $timestamp = $log['timestamp'];
        $type = $log['type'];
        $message = $log['message'];

        $icon = match ($type) {
            'connection' => '📱',
            'join' => '👤',
            'chat' => '💬',
            'leave' => '👋',
            'error' => '❌',
            'warning' => '⚠️',
            default => 'ℹ️',
        };

        echo "{$icon} [{$timestamp}] {$message}".PHP_EOL;
    }

    private function isRateLimited(string $connectionId): bool
    {
        if (! isset($this->connections[$connectionId])) {
            return false;
        }

        $now = time();
        $timestamps = &$this->connections[$connectionId]['timestamps'];

        $timestamps = array_filter($timestamps, fn ($ts) => $ts > $now - $this->rateLimitWindow);
        $timestamps[] = $now;

        return count($timestamps) > $this->rateLimitMessages;
    }

    private function cleanup(): void
    {
        system('stty icanon echo');

        $this->newLine(2);
        $this->components->info('Server shutting down...');
    }
}
