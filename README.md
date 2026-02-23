# Sourdough

A complete application framework designed to help you build modern web applications quickly. Sourdough provides all the essential building blocks you need—user authentication, notifications, AI integration, and more—so you can focus on building your unique features instead of reinventing the wheel.

## What is Sourdough?

Sourdough is a starter framework that comes pre-configured with enterprise-grade features. Think of it as a solid foundation for building web applications, with user management, notification systems, and AI capabilities already built in. Everything runs in a single Docker container, making it easy to deploy and manage.

## Key Features

### User Management
Complete authentication system with email/password login, single sign-on (SSO) support for popular services like Google, GitHub, Microsoft, Apple, Discord, and GitLab. Includes two-factor authentication (2FA), password reset, and email verification for secure account management.

### Notifications
Send messages through multiple channels including email, Telegram, Discord, Signal, SMS, push notifications, and in-app notifications. Users can choose their preferred notification methods.

### AI Integration
Connect to multiple AI services (Claude, OpenAI, Gemini, Ollama) and use them individually or combine them in powerful ways. Includes advanced modes for aggregating responses or using multiple AI models together in "council mode" for better results.

### Flexible Storage
Works with SQLite by default (perfect for getting started), but can easily switch to MySQL, PostgreSQL, or Supabase for larger applications.

### Backup & Restore
Built-in backup and restore to protect your data. Create full backups (database, files, settings) from the UI; download, restore, or delete them. Configure retention, scheduling, and remote destinations (S3, SFTP, Google Drive) and optional encryption. All backup settings are manageable in the admin UI (no restart required). See [Backup & Restore documentation](docs/backup.md) for user, admin, and developer guides.

### Simple Deployment
Everything runs in one Docker container, making it easy to deploy anywhere—from your local machine to production servers.

## Getting Started

The easiest way to get started is with Docker:

```bash
# Clone the repository
git clone https://github.com/Sourdough-start/sourdough.git
cd sourdough

# Start the application
docker-compose up -d

# Access the application
open http://localhost:8080
```

That's it! The application will be running and ready to use.

## Documentation

For more information, check out our documentation:

- [User Guide](docs/user/) - Learn how to use Sourdough
- [Developer Guide](docs/dev/) - Technical documentation for developers
- [API Reference](docs/api/) - API endpoints and integration details
- [Backup & Restore](docs/backup.md) - Backup and restore: user guide, admin settings, developer docs, key files, and how to extend

## License

MIT License - see [LICENSE](LICENSE) for details.
