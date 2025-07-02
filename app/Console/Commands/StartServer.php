<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\Room;
use Exception;
use Illuminate\Console\Command;
use React\EventLoop\Loop;
use React\Socket\Connection;
use React\Socket\SocketServer;
use Symfony\Component\Console\Attribute\AsCommand;

use function Termwind\render;

#[AsCommand('chat:server', 'Starts the chat server.')]
class StartServer extends Command
{
    protected $signature = 'chat:server {--port=2785}';

    private array $connections = [];

    private array $rooms = [];

    public function handle(): void
    {
        $loop = Loop::get();
        $server = new SocketServer('127.0.0.1:'.$this->option('port') ?? '2785');

        $server->on('connection', function (Connection $connection) use (&$connections) {
            $connectionId = spl_object_hash($connection);
            $this->connections[$connectionId] = [
                'connection' => $connection,
                'username' => null,
                'room' => null,
            ];

            $this->info("📱 New connection: $connectionId");

            $connection->on('data', function ($data) use ($connectionId) {
                $this->handleData($connectionId, trim($data));
            });

            $connection->on('close', function () use ($connectionId) {
                $this->handleDisconnection($connectionId);
            });

            $connection->on('error', function ($error) use ($connectionId) {
                $this->error("Connection error for $connectionId: ".$error->getMessage());
                $this->handleDisconnection($connectionId);
            });
        });

        render(<<<HTML
    <div>
        <div class="px-1 bg-blue-600 font-bold">🚀 Server listening on port {$this->option('port')}...</div>
    </div>
HTML);

        $loop->run();
    }

    private function handleData(string $connectionId, string $data): void
    {
        try {
            $message = json_decode($data, true);

            if (! $message) {
                $this->warn("Invalid JSON received from {$connectionId}");

                return;
            }

            match ($message['type'] ?? 'chat') {
                'join' => $this->handleJoin($connectionId, $message),
                'chat' => $this->handleMessage($connectionId, $message),
                'leave' => $this->handleDisconnection($connectionId),
                default => $this->warn("Unknown message type from $connectionId: ".($message['type'] ?? 'none'))
            };
        } catch (Exception $e) {
            $this->error("Error handling message from {$connectionId}: ".$e->getMessage());
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

        $this->info("👤 $username joined room #$room");

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
            $this->warn("Message from unregistered connection: $connectionId");

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
            $this->warn('Could not save message to database: '.$e->getMessage());
        }

        $this->info("💬 [{$connection['room']}] {$connection['username']}: ".$chatMessage['message']);
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

            $this->info("👋 $username left room #$room");

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
                $this->error("Failed to send message to {$connectionId}: ".$e->getMessage());
                $this->handleDisconnection($connectionId);
            }
        }
    }
}
