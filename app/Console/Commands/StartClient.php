<?php

namespace App\Console\Commands;

use App\Models\Room;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\Connection;
use React\Socket\Connector;
use React\Stream\ReadableResourceStream;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\search;

#[AsCommand('chat:client', 'Starts the TUI chat client.')]
class StartClient extends Command implements PromptsForMissingInput
{
    protected $signature = 'chat:client {username} {room} {--server=127.0.0.1:2785}';

    protected $description = 'Start the TUI chat client';

    private string $username;

    private string $room;

    private Connection $connection;

    private array $messages = [];

    private int $maxMessages = 50;

    private bool $isConnected = false;

    public function handle()
    {
        $this->username = $this->argument('username');
        $this->room = Room::whereKey($this->argument('room'))->orWhere('slug', $this->argument('room'))->firstOrFail()->slug;
        $server = $this->option('server');

        if (empty($this->username)) {
            $this->error('Username is required!');

            return 1;
        }

        $loop = Loop::get();

        $this->setupTerminal();

        $connector = new Connector($loop);

        $this->info("🔌 Connecting to server at $server...");

        $connector->connect("tcp://{$server}")
            ->then(function ($connection) use ($loop) {
                $this->connection = $connection;
                $this->isConnected = true;
                $this->setupConnection($connection, $loop);
                $this->joinRoom();
            })
            ->otherwise(function ($error) {
                $this->error('❌ Failed to connect to server: '.$error->getMessage());
                $this->error('Make sure the server is running with: php artisan chat:server');
                exit(1);
            });

        $loop->run();
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'username' => 'What would you like your username to be?',
            'room' => fn () => search(
                label: 'Search for a room:',
                options: fn ($value) => strlen($value) > 0
                    ? Room::where('name', 'like', "%{$value}%")->pluck('name', 'id')->all()
                    : [],
                placeholder: 'E.g. general'
            ),
        ];
    }

    private function setupTerminal(): void
    {
        system('stty -echo');
        system('clear');

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () {
                $this->cleanup();
                exit(0);
            });

            pcntl_signal(SIGTERM, function () {
                $this->cleanup();
                exit(0);
            });
        }

        $this->displayHeader();
    }

    private function displayHeader(): void
    {
        $width = 80;
        $title = '🗨️  TUI Chat Client';
        $subtitle = "Room: #$this->room | User: $this->username";

        $this->line(str_repeat('═', $width));
        $this->line($this->centerText($title, $width));
        $this->line($this->centerText($subtitle, $width));
        $this->line(str_repeat('═', $width));
        $this->line('Commands: /quit to exit, /clear to clear screen, /help for help');
        $this->line(str_repeat('-', $width));
        $this->line('');
    }

    private function centerText($text, $width)
    {
        $padding = ($width - strlen($text)) / 2;

        return str_repeat(' ', floor($padding)).$text.str_repeat(' ', ceil($padding));
    }

    private function setupConnection(Connection $connection, LoopInterface $loop): void
    {
        $connection->on('data', function ($data) {
            $messages = explode(PHP_EOL, trim($data));
            foreach ($messages as $messageData) {
                if (! empty($messageData)) {
                    $this->handleIncomingMessage($messageData);
                }
            }
        });

        $connection->on('close', function () {
            $this->isConnected = false;
            $this->error(PHP_EOL.'❌ Connection to server lost!');
            $this->cleanup();
            exit(1);
        });

        $connection->on('error', function ($error) {
            $this->isConnected = false;
            $this->error(PHP_EOL.'❌ Connection error: '.$error->getMessage());
            $this->cleanup();
            exit(1);
        });

        $this->setupInput($loop);
    }

    private function setupInput(LoopInterface $loop): void
    {
        $stdin = new ReadableResourceStream(STDIN, $loop);
        $inputBuffer = '';

        $stdin->on('data', function ($data) use (&$inputBuffer) {
            $char = $data;

            if ($char === "\n" || $char === "\r") {
                // Enter pressed - send message
                if (! empty(trim($inputBuffer))) {
                    $this->handleUserInput(trim($inputBuffer));
                }
                $inputBuffer = '';
                $this->displayPrompt();

            } elseif ($char === "\x7f" || $char === "\x08") {
                // Backspace pressed
                if (strlen($inputBuffer) > 0) {
                    $inputBuffer = substr($inputBuffer, 0, -1);
                    echo "\x08 \x08"; // Move back, print space, move back again
                }

            } elseif ($char === "\x03") {
                // Ctrl+C pressed
                $this->cleanup();
                exit(0);

            } elseif (ord($char) >= 32 && ord($char) <= 126) {
                // Printable character
                $inputBuffer .= $char;
                echo $char;
            }
        });

        $this->displayPrompt();
    }

    private function displayPrompt(): void
    {
        echo PHP_EOL.'> ';
    }

    private function handleUserInput(string $input): void
    {
        if (str_starts_with($input, '/')) {
            $this->handleCommand($input);
        } else {
            $this->sendChatMessage($input);
        }
    }

    private function handleCommand(string $command): void
    {
        $parts = explode(' ', $command, 2);
        $cmd = strtolower($parts[0]);

        switch ($cmd) {
            case '/quit':
            case '/exit':
                $this->sendLeaveMessage();
                $this->cleanup();
                exit(0);

            case '/clear':
                system('clear');
                $this->displayHeader();
                $this->redisplayMessages();
                break;

            case '/help':
                $this->displayHelp();
                break;

            case '/room':
                if (isset($parts[1])) {
                    $this->changeRoom(trim($parts[1]));
                } else {
                    $this->line("Current room: {$this->room}");
                }
                break;

            default:
                $this->line("Unknown command: {$cmd}. Type /help for available commands.");
        }
    }

    private function displayHelp(): void
    {
        $this->line(PHP_EOL.'📋 Available Commands:');
        $this->line('  /quit, /exit    - Leave the chat');
        $this->line('  /clear          - Clear the screen');
        $this->line('  /room [name]    - Change room or show current room');
        $this->line('  /help           - Show this help message');
        $this->line('');
    }

    private function changeRoom(string $newRoom): void
    {
        if ($newRoom === $this->room) {
            $this->line("You're already in room '{$newRoom}'");

            return;
        }

        $this->sendLeaveMessage();
        $this->room = $newRoom;
        $this->joinRoom();

        system('clear');
        $this->displayHeader();
        $this->line("🚪 Switched to room '{$newRoom}'");
    }

    private function joinRoom(): void
    {
        if (! $this->isConnected) {
            return;
        }

        $message = [
            'type' => 'join',
            'username' => $this->username,
            'room' => $this->room,
        ];

        $this->sendMessage($message);
    }

    private function sendChatMessage(string $content): void
    {
        if (! $this->isConnected) {
            $this->error('Not connected to server!');

            return;
        }

        if (empty(trim($content))) {
            return;
        }

        $message = [
            'type' => 'chat',
            'message' => $content,
        ];

        $this->sendMessage($message);

        $this->displayMessage([
            'type' => 'chat',
            'username' => $this->username,
            'message' => $content,
            'timestamp' => date('H:i:s'),
        ], true);
    }

    private function sendLeaveMessage(): void
    {
        if (! $this->isConnected) {
            return;
        }

        $message = [
            'type' => 'leave',
            'username' => $this->username,
            'room' => $this->room,
        ];

        $this->sendMessage($message);
    }

    private function sendMessage(array $message): void
    {
        if ($this->isConnected) {
            try {
                $this->connection->write(json_encode($message).PHP_EOL);
            } catch (Exception $e) {
                $this->error('Failed to send message: '.$e->getMessage());
            }
        }
    }

    private function handleIncomingMessage(string $data): void
    {
        try {
            $message = json_decode($data, true);

            if (! $message) {
                return;
            }

            $this->displayMessage($message);
        } catch (Exception $e) {
            $this->error('Error handling incoming message: '.$e->getMessage());
        }
    }

    private function displayMessage(array $message, bool $isOwn = false): void
    {
        $this->messages[] = $message;

        if (count($this->messages) > $this->maxMessages) {
            $this->messages = array_slice($this->messages, -$this->maxMessages);
        }

        echo "\r".str_repeat(' ', 80)."\r";

        $timestamp = $message['timestamp'] ?? date('H:i:s');
        $username = $message['username'] ?? 'Unknown';
        $content = $message['message'] ?? '';
        $type = $message['type'] ?? 'chat';

        switch ($type) {
            case 'system':
                $this->line("🔔 [$timestamp] $content");
                break;

            case 'chat':
                if ($isOwn) {
                    $this->line("📤 [$timestamp] You: $content");
                } else {
                    $this->line("💬 [$timestamp] $username: $content");
                }
                break;

            default:
                $this->line("📨 [$timestamp] $username: $content");
        }

        $this->displayPrompt();
    }

    private function redisplayMessages(): void
    {
        foreach ($this->messages as $message) {
            $this->displayMessage($message);
        }
    }

    private function cleanup(): void
    {
        system('stty echo');

        if ($this->isConnected) {
            $this->sendLeaveMessage();
            $this->connection->close();
        }

        $this->line(PHP_EOL.'👋 Goodbye!');
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
