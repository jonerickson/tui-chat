# tui-chat

A PHP TUI chat application utilizing non-blocking I/O.

## Key Features & Benefits

*   **Real-time Communication:** Enables instant messaging within a terminal environment.
*   **Non-blocking I/O:** Leverages asynchronous operations for enhanced performance and responsiveness.
*   **TUI Interface:** Provides a user-friendly text-based interface.
*   **Lightweight:** Designed for minimal resource consumption.

## Prerequisites & Dependencies

Before you begin, ensure you have the following installed:

*   **PHP:** Version 8.1 or higher.
*   **Node.js:** Version 18 or higher.
*   **Composer:** PHP dependency manager.
*   **npm:** Node package manager (usually bundled with Node.js).
*   **ReactPHP:**  PHP library for event-driven, non-blocking I/O.
*   **Laravel:** Framework used to build the application.

## Installation & Setup Instructions

1.  **Clone the repository:**

    ```bash
    git clone https://github.com/jonerickson/tui-chat.git
    cd tui-chat
    ```

2.  **Setup the project:**

    ```bash
    composer setup
    ```
    This will setup the entire project, installing the necessary dependencies, migrating the database and seeding some basic data.

## Usage Examples

1.  **Start the Server:**

    ```bash
    php artisan chat:server
    ```

    This command will start the TCP socket server.  By default, the server listens on port 2785.

2.  **Start the Client:**

    Open a new terminal window and run the client:

    ```bash
    php artisan chat:client {username} {room}
    ```

    The client will connect to the server.  Enter your username when prompted. After entering a username you will be able to communicate with others in the chat.

## Contributing Guidelines

We welcome contributions to the `tui-chat` project! To contribute, please follow these steps:

1.  Fork the repository.
2.  Create a new branch for your feature or bug fix.
3.  Make your changes and commit them with descriptive commit messages.
4.  Submit a pull request to the `master` branch.

Please adhere to coding standards and provide tests for your changes.

## License Information

License not specified.

## Acknowledgments

This project utilizes the following open-source libraries:

*   [Laravel](https://laravel.com/)
*   [ReactPHP](https://reactphp.org/)
