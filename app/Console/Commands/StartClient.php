<?php

declare(strict_types=1);

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
use Throwable;

use function Laravel\Prompts\search;

#[AsCommand('chat:client', 'Starts the TUI chat client.')]
class StartClient extends Command implements PromptsForMissingInput
{
    protected $signature = 'chat:client {username} {room} {--server=127.0.0.1:2785}';

    protected $description = 'Start the TUI chat client';

    private ?string $username;

    private ?string $room;

    private Connection $connection;

    private array $messages = [];

    private int $maxMessages = 50;

    private bool $isConnected = false;

    private string $inputBuffer = '';

    private int $terminalHeight = 24;

    private int $terminalWidth = 80;

    public function handle(): int
    {
        $this->username = $this->argument('username');
        $this->room = Room::whereKey($this->argument('room'))->orWhere('slug', $this->argument('room'))->value('slug');

        if (is_null($this->room)) {
            $this->components->error('The room you provided does not exist. Please provide a valid room.');

            return self::FAILURE;
        }

        $server = $this->option('server');

        if (is_null($this->username)) {
            $this->components->error('Please provide a valid username.');

            return self::FAILURE;
        }

        $loop = Loop::get();

        $connector = new Connector($loop);

        $this->components->info("Connecting to server at $server...");

        $connector->connect("tcp://$server")
            ->then(function ($connection) use ($loop) {
                $this->connection = $connection;
                $this->isConnected = true;
                $this->setupTerminal();
                $this->setupConnection($connection, $loop);
                $this->joinRoom();
            })
            ->catch(function (Throwable $error) {
                $this->components->error('Failed to connect to server: '.$error->getMessage());
                $this->components->info('Make sure the chat server has been started using the command: <info>php artisan chat:server</info>');

                return self::FAILURE;
            });

        $loop->run();

        return self::SUCCESS;
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
        system('stty -icanon -echo');

        $size = explode(' ', shell_exec('stty size') ?? '24 80');
        $this->terminalHeight = (int) ($size[0] ?? 24);
        $this->terminalWidth = (int) ($size[1] ?? 80);

        $this->trap([SIGINT, SIGTERM], function () {
            $this->cleanup();
            exit(0);
        });

        $this->redrawScreen();
    }

    private function redrawScreen(): void
    {
        // Move cursor to home position and clear screen
        echo "\033[H\033[J";

        // Draw header
        $title = '🗨️  TUI Chat Client';
        $subtitle = "Room: #{$this->room} | User: {$this->username}";

        echo str_repeat('═', $this->terminalWidth).PHP_EOL;
        echo center($title, $this->terminalWidth).PHP_EOL;
        echo center($subtitle, $this->terminalWidth).PHP_EOL;
        echo str_repeat('═', $this->terminalWidth).PHP_EOL;

        // Calculate how many lines we have for messages
        // Header = 4 lines, Input area = 3 lines (separator, input, hint)
        $messageLines = $this->terminalHeight - 7;

        // Display messages
        $messagesToShow = array_slice($this->messages, -$messageLines);
        foreach ($messagesToShow as $message) {
            $this->renderMessage($message);
        }

        // Fill remaining space with blank lines
        $blankLines = $messageLines - count($messagesToShow);
        for ($i = 0; $i < $blankLines; $i++) {
            echo PHP_EOL;
        }

        // Draw input area separator
        $separatorLine = $this->terminalHeight - 2;
        echo "\033[$separatorLine;1H".str_repeat('─', $this->terminalWidth);

        // Draw hint line
        $hintLine = $this->terminalHeight - 1;
        $hint = 'Press Enter to send • /help for commands';
        echo "\033[$hintLine;1H".center($hint, $this->terminalWidth);

        // Position cursor at the start of the last line and draw input box
        echo "\033[$this->terminalHeight;1HMessage: ".$this->inputBuffer;

        // Move cursor to the correct position (last line, after "Message: " + buffer)
        $cursorColumn = 10 + mb_strlen($this->inputBuffer); // "Message: " is 9 chars + space
        echo "\033[{$this->terminalHeight};{$cursorColumn}H";

        // Ensure output is flushed
        flush();
    }

    private function renderMessage(array $message): void
    {
        $timestamp = $message['timestamp'] ?? date('H:i:s');
        $username = $message['username'] ?? 'Unknown';
        $content = $message['message'] ?? '';
        $type = $message['type'] ?? 'chat';
        $isOwn = ($message['_isOwn'] ?? false);

        switch ($type) {
            case 'system':
                echo "🔔 [$timestamp] $content".PHP_EOL;
                break;

            case 'chat':
                if ($isOwn) {
                    echo "📤 [$timestamp] You: $content".PHP_EOL;
                } else {
                    echo "💬 [$timestamp] $username: {$content}".PHP_EOL;
                }
                break;

            default:
                echo "📨 [$timestamp] $username: $content".PHP_EOL;
        }
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
            $this->newLine();
            $this->components->error('Connection to the server closed.');
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

        $stdin->on('data', function ($data) {
            $char = $data;

            if ($char === "\n" || $char === "\r") {
                // Enter pressed - send message
                if (! empty(trim($this->inputBuffer))) {
                    $this->handleUserInput(trim($this->inputBuffer));
                }
                $this->inputBuffer = '';
                $this->redrawScreen();

            } elseif ($char === "\x7f" || $char === "\x08") {
                // Backspace pressed
                if (strlen($this->inputBuffer) > 0) {
                    $this->inputBuffer = substr($this->inputBuffer, 0, -1);
                    $this->redrawScreen();
                }

            } elseif ($char === "\x03") {
                // Ctrl+C pressed
                $this->cleanup();
                exit(0);

            } elseif (ord($char) >= 32 && ord($char) <= 126) {
                // Printable character
                $this->inputBuffer .= $char;
                $this->redrawScreen();
            }
        });
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
                $this->messages = [];
                $this->redrawScreen();
                break;

            case '/help':
                $this->displayMessage([
                    'type' => 'system',
                    'message' => "\n\nAvailable Commands:\n/quit, /exit - Leave the chat\n/clear - Clear message history\n/room [name] - Change room or show current room\n/help - Show this help\n",
                    'timestamp' => date('H:i:s'),
                ]);
                break;

            case '/room':
                if (isset($parts[1])) {
                    $this->changeRoom(trim($parts[1]));
                } else {
                    $this->displayMessage([
                        'type' => 'system',
                        'message' => "Current room: {$this->room}",
                        'timestamp' => date('H:i:s'),
                    ]);
                }
                break;

            default:
                $this->displayMessage([
                    'type' => 'system',
                    'message' => "Unknown command: {$cmd}. Type /help for available commands.",
                    'timestamp' => date('H:i:s'),
                ]);
        }
    }

    private function changeRoom(string $newRoom): void
    {
        if ($newRoom === $this->room) {
            $this->displayMessage([
                'type' => 'system',
                'message' => "You're already in room '{$newRoom}'",
                'timestamp' => date('H:i:s'),
            ]);

            return;
        }

        $this->sendLeaveMessage();
        $this->room = $newRoom;
        $this->messages = [];
        $this->joinRoom();
        $this->redrawScreen();

        $this->displayMessage([
            'type' => 'system',
            'message' => "Switched to room '{$newRoom}'",
            'timestamp' => date('H:i:s'),
        ]);
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
        if ($isOwn) {
            $message['_isOwn'] = true;
        }

        $this->messages[] = $message;

        if (count($this->messages) > $this->maxMessages) {
            $this->messages = array_slice($this->messages, -$this->maxMessages);
        }

        $this->redrawScreen();
    }

    private function cleanup(): void
    {
        system('stty icanon echo');

        if ($this->isConnected) {
            $this->sendLeaveMessage();
            $this->connection->close();
        }

        $this->components->info('👋 Goodbye!');
    }
}
