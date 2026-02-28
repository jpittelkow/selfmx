# Sourdough User Guide

Welcome to Sourdough! This guide will help you get started with the application.

## Table of Contents

1. [Getting Started](#getting-started)
2. [Account Management](#account-management)
3. [Security Settings](#security-settings)
4. [Notifications](#notifications)
5. [AI/LLM Features](#aillm-features)
6. [Backup & Restore](#backup--restore)
7. [Administration (Admins)](#administration-admins)
   - [Single Sign-On (SSO)](#single-sign-on-sso)
8. [Payments (Fork Operators)](#payments-fork-operators)

---

## Getting Started

### System Requirements

- **Docker**: Docker 20.10+ and Docker Compose 2.0+
- **Browser**: Chrome, Firefox, Safari, or Edge (latest versions)

### Installation

#### Using Docker (Recommended)

```bash
# Pull and run
docker run -d \
  --name sourdough \
  -p 8080:80 \
  -v sourdough_data:/data \
  ghcr.io/sourdough-start/sourdough:latest

# Access at http://localhost:8080
```

#### Using Docker Compose

```bash
# Clone repository
git clone https://github.com/Sourdough-start/sourdough.git
cd sourdough

# Start services
docker-compose up -d

# Access at http://localhost:8080
```

### First-Time Setup

1. Navigate to `http://localhost:8080`
2. Click "Register" to create your account
3. Complete the registration form
4. Verify your email (if email is configured)
5. Login to access the dashboard

---

## Account Management

### Creating an Account

1. Click **Register** on the login page
2. Enter your details:
   - Name
   - Email address
   - Password (min 8 characters)
3. Click **Create Account**
4. Check your email for verification link

### Logging In

#### Email/Password Login
1. Enter your email and password
2. Click **Sign In**
3. If 2FA is enabled, enter your code

#### SSO Login
1. Click the SSO provider button (Google, GitHub, etc.)
2. Authorize the application
3. You'll be redirected to the dashboard

### Profile Settings

Access via **User menu** (click your name in the header) → **My Profile**

- **Name**: Your display name
- **Email**: Your email address (requires verification if changed)
- **Avatar**: Upload a profile picture
- **Timezone**: Your preferred timezone

### Changing Password

1. Go to **User menu** → **Security**
2. In the **Change Password** section, enter your current password
3. Enter and confirm your new password
4. Click **Update Password**

### Deleting Your Account

1. Go to **User menu** → **My Profile**
2. Scroll to **Danger Zone**
3. Click **Delete Account**
4. Confirm by typing your email
5. Click **Permanently Delete**

> **Warning**: This action is irreversible. All your data will be permanently deleted.

---

## Security Settings

Access via **User menu** (click your name in the header) → **Security**. Here you can change your password, manage two-factor authentication, add or remove passkeys, and link or unlink SSO accounts.

### Two-Factor Authentication (2FA)

2FA adds an extra layer of security by requiring a code from your phone.

#### Enabling 2FA

1. Go to **User menu** → **Security**
2. In the **Two-Factor Authentication** section, turn the switch **On** (or click to enable)
3. Scan the QR code with an authenticator app:
   - Google Authenticator
   - Authy
   - 1Password
   - Microsoft Authenticator
4. Enter the 6-digit code from the app
5. **Save your recovery codes** in a secure location

#### Using 2FA

When logging in:
1. Enter your email and password
2. Enter the 6-digit code from your authenticator app
3. Or use a recovery code if you don't have your device

#### Recovery Codes

- 10 one-time codes are generated when you enable 2FA
- Each code can only be used once
- Store them securely (password manager, printed copy)
- Regenerate codes if you've used several

#### Disabling 2FA

1. Go to **User menu** → **Security**
2. In the **Two-Factor Authentication** section, turn the switch **Off**
3. 2FA is now disabled

### Passkeys (when enabled by your administrator)

Passkeys let you sign in with your fingerprint, face, or a hardware security key instead of (or in addition to) your password. They are phishing-resistant and work on supported browsers.

#### Adding a passkey

1. Go to **User menu** → **Security**
2. In the **Passkeys** section, click **Add Passkey**
3. Enter a name (e.g. "MacBook" or "Phone")
4. Follow your device prompt to create the passkey (fingerprint, face, or security key)
5. The passkey is saved and can be used to sign in

#### Signing in with a passkey

1. On the login page, click **Sign in with Passkey**
2. Use your device prompt when asked
3. You'll be signed in

#### Removing a passkey

1. Go to **User menu** → **Security**
2. In the **Passkeys** section, click the delete (trash) icon next to the passkey
3. Confirm removal

**Note**: Passkeys require a modern browser (Chrome, Safari, Edge, Firefox) and HTTPS in production.

### Connected Accounts (SSO)

View and manage linked social accounts:

1. Go to **User menu** → **Security**
2. See **Connected Accounts** section
3. Click **Connect** to link a new provider
4. Click **Disconnect** to unlink a provider

### Active Sessions

View and manage your login sessions:

1. Go to **User menu** → **Security**
2. See **Active Sessions** section (if available)
3. Click **Revoke** to end a specific session
4. Click **Revoke All** to sign out everywhere

---

## Notifications

### In-App Notifications

View and manage notifications from the header and dedicated notifications page:

- **Bell icon**: In the top bar. Shows an unread count badge. Click to open a dropdown with recent notifications.
- **Dropdown**: Lists recent notifications, **Mark all read**, and **View all** to open the full list.
- **Notifications page**: Go to **/notifications** (or click **View all**). Filter by **All** or **Unread**, mark items read, delete selected, and paginate through the list.

When real-time updates are enabled (Reverb/broadcasting), new in-app notifications appear immediately without refreshing.

### Notification Channels

Configure how you receive notifications via **Configuration > Notifications** (admin) or your user settings.

| Channel | Setup Required |
|---------|----------------|
| In-App | None (always enabled) |
| Email | Valid email address |
| Telegram | Bot token + Chat ID |
| Discord | Webhook URL |
| Slack | Webhook URL |
| SMS | Phone number |

### Channel Setup

#### Email
Email notifications use your account email by default.

#### Telegram
1. Start a chat with [@BotFather](https://t.me/botfather)
2. Create a new bot and get the token
3. Start a chat with your bot
4. Enter your Chat ID in settings

#### Discord
1. Go to your Discord server settings
2. Create a webhook in the desired channel
3. Copy the webhook URL
4. Paste it in Sourdough settings

#### Slack
1. Go to your Slack workspace settings
2. Create an incoming webhook
3. Copy the webhook URL
4. Paste it in Sourdough settings

### Notification Types

Choose which events trigger notifications:

- **Security Alerts**: Login from new device, password changed
- **System Updates**: New version available
- **Backup Events**: Backup completed, backup failed
- **AI Usage**: Usage warnings, quota alerts

---

## AI/LLM Features

Access via **Settings > AI**

### Configuring AI Providers

Sourdough supports multiple AI providers. Configure the ones you want to use:

#### Adding a Provider

1. Go to **Configuration > AI** (admin only)
2. Click **Add Provider**
3. Select a provider (Claude, OpenAI, Gemini, or Ollama)
4. **For API-based providers (Claude, OpenAI, Gemini):**
   - Enter your API key
   - Click **Test** to validate the key (shows checkmark if valid)
   - Click **Fetch Models** to load available models from the provider
   - Select a model from the dropdown
5. **For Ollama (local):**
   - Enter the Ollama host URL (e.g., `http://localhost:11434`)
   - Click **Test** to verify connection
   - Click **Fetch Models** to load models installed on your Ollama server
   - Select a model from the dropdown
6. Click **Add Provider** to save

**Model Discovery**: The system automatically fetches available models from each provider's API, so you always see the latest models your API key has access to. Model lists are cached for 1 hour to reduce API calls.

#### Getting API Keys

- **Claude (Anthropic)**: Get an API key from [console.anthropic.com](https://console.anthropic.com)
- **OpenAI**: Get an API key from [platform.openai.com](https://platform.openai.com)
- **Gemini (Google)**: Get an API key from [makersuite.google.com](https://makersuite.google.com)
- **Ollama**: No API key needed; install Ollama locally and enter the server URL

### Operating Modes

#### Single Mode
- Queries one provider directly
- Fastest and most cost-effective
- Best for: General use, cost-conscious users

#### Aggregation Mode
- Queries all enabled providers
- Primary provider synthesizes responses
- Best for: Complex questions, getting multiple perspectives

#### Council Mode
- All providers vote on the response
- Consensus-based final answer
- Best for: Critical decisions, fact verification

### Usage Tracking

View your AI usage:

1. Go to **Settings > AI**
2. See **Usage Statistics**:
   - Total requests
   - Tokens used
   - Estimated cost
   - Usage by provider

---

## Backup & Restore

Access via **Configuration > Backup** (admin users only). Full backup documentation: [Backup & Restore (docs hub)](../backup.md).

The Backup page has two tabs:

- **Backups** – Create, download, restore, and delete backups.
- **Settings** – Configure retention, schedule, and remote destinations (S3, SFTP, Google Drive). Admins with **manage-settings** can edit these; changes take effect without restart.

### Creating a Backup

1. Go to **Configuration > Backup**
2. Open the **Backups** tab
3. Click **Create Backup**
4. Wait for the backup to complete
5. Download the backup file if desired

### What's Included in Backups

- **Database** – All application data (users, settings, notifications, etc.)
- **Uploaded files** – Everything under storage (avatars, attachments, etc.)
- **Application settings** – Stored in the database (sensitive values handled securely)

Backups are ZIP archives with a manifest (version 2.0). See [ADR-007: Backup System Design](../adr/007-backup-system-design.md) for format details.

### Downloading Backups

1. Go to **Configuration > Backup** > **Backups** tab
2. Find the backup in the list (filename, size, date)
3. Click **Download**
4. Save the `.zip` file securely

### Restoring from Backup

1. Go to **Configuration > Backup** > **Backups** tab
2. Either choose an existing backup and click **Restore**, or use **Upload Backup** to select a `.zip` file
3. In the restore dialog, type **RESTORE** to confirm
4. Click **Restore Backup**

> **Warning**: Restoring replaces all current data (database, files, settings). This action cannot be undone.

### Backup Settings (Admins)

Under **Configuration > Backup** > **Settings** tab you can configure:

- **Retention** – How long to keep backups and how many to keep
- **Schedule** – Enable scheduled backups; set frequency (daily/weekly/monthly), time, and destinations
- **S3 / SFTP / Google Drive** – Remote storage credentials; use **Test Connection** after saving to verify
- **Encryption** – Optional password-based encryption for backup files
- **Notifications** – Notify on backup success or failure (when notifications are configured)

Save changes with **Save Changes**. Test Connection uses the saved settings; save before testing if you just edited credentials.

### Scheduled Backups

If enabled by your administrator in **Configuration > Backup** > **Settings**:

- Backups run automatically (daily, weekly, or monthly)
- Retention rules clean up old backups
- Backups can be stored locally and/or remotely (S3, SFTP, Google Drive)

---

## Administration (Admins)

Admin-only configuration is under **Configuration** in the main navigation (requires admin/manager role).

### User Management

Access via **Configuration > Users** (`/configuration/users`).

- **List users**: View all users with pagination and search by name or email. Columns show user, email, status (Active/Disabled, Verified/Unverified), and created date.
- **Create user**: Click **Create User**. Enter name, email, and password. Optionally grant admin privileges. Use **Skip email verification** to create the user as already verified; otherwise a verification email is sent if email is configured.
- **Edit user**: Use the actions menu on a user row to edit name, email, password, and group memberships (admin role is via the admin group).
- **Disable/enable user**: Use **Disable User** or **Enable User** from the actions menu. Disabled users cannot log in. You cannot disable your own account or the last admin.
- **Resend verification email**: For unverified users, use **Resend Verification Email** from the actions menu. Rate limited to once per 5 minutes per user.
- **Reset password**: Use **Reset Password** from the actions menu and enter a new password for the user.
- **Delete user**: Use **Delete** from the actions menu. You cannot delete your own account or the last admin.

### Single Sign-On (SSO)

Administrators can enable SSO providers so users can sign in with their existing accounts (Google, GitHub, Microsoft, etc.).

#### Supported Providers

- Google
- GitHub
- Microsoft (Azure AD / Entra ID)
- Apple
- Discord
- GitLab
- Generic OIDC (Okta, Auth0, Keycloak, etc.)

#### Setting Up an SSO Provider

1. Navigate to **Configuration > SSO**
2. Click **Setup instructions** on the provider card
3. Follow the steps to create an OAuth app in the provider's developer console
4. Copy the redirect URI shown and add it to your OAuth app's allowed redirect URIs
5. Enter the Client ID and Client Secret from the provider
6. Click **Test connection** to verify credentials
7. Enable the provider toggle to show it on the login page
8. Click **Save** to apply changes for that provider

Global options (Enable SSO, Allow account linking, Auto-register, Trust provider email) are in the **Global options** card; save them separately with that card's Save button.

#### Provider-Specific Notes

**Google**

- Create OAuth 2.0 credentials in [Google Cloud Console](https://console.cloud.google.com/apis/credentials)
- Use application type: Web application
- Add the authorized redirect URI from the SSO page

**Microsoft**

- Register an app in [Azure Portal](https://portal.azure.com/) (App registrations)
- Create a client secret under Certificates & secrets
- Add API permissions for OpenID (openid, profile, email)

**Apple**

- Requires [Apple Developer Program](https://developer.apple.com/) membership
- Create an App ID and Services ID for web sign-in
- Generate a JWT client secret using your key (see Apple documentation)

**Generic OIDC**

- Works with Okta, Auth0, Keycloak, and other OIDC-compatible identity providers
- Requires the Issuer URL (discovery endpoint base, e.g. `https://your-tenant.auth0.com/`)
- The Provider name is shown on the login button (e.g. "Enterprise SSO")

---

### Customizing System Emails

Access via **Configuration > Email Templates** (`/configuration/email-templates`).

You can customize the content of system-generated emails (password reset, email verification, welcome, notifications):

1. Open **Configuration > Email Templates**
2. Click a template (e.g. **Password Reset**) to open the editor
3. Edit the **Subject** and **Body** using the rich text editor. Use **Insert variable** to add placeholders such as `{{user.name}}` or `{{reset_url}}` that are replaced when the email is sent
4. Use the **Preview** panel on the right to see how the email will look with sample data
5. Click **Save** to apply changes
6. Use **Send test email** to send a test to an address (requires email to be configured under Configuration > Email)
7. For system templates, **Reset to default** restores the original content

Variables available for each template are listed in the template description. Inactive templates are not used when sending emails; you can disable a template without deleting it.

---

## Payments (Fork Operators)

If you're running a forked instance of Sourdough, you can accept payments by connecting your Stripe account to the platform.

### Connecting Your Stripe Account

1. Go to **Configuration > Stripe**
2. Click **Connect Stripe Account**
3. You'll be redirected to Stripe to authorize your account
4. Complete the Stripe onboarding (business details, bank account)
5. Return to the app — your status will show as "Pending" or "Active"

### Setup States

- **Not Connected** — Click "Connect Stripe Account" to begin
- **Setup Incomplete** — Stripe onboarding is not complete. Click "Complete Setup" to finish
- **Active** — Ready to accept payments. Payouts go to your Stripe account

### Platform Fee

The platform collects a small application fee (shown on the Stripe page) on each payment. This fee is handled automatically by Stripe — no action needed from you.

### Disconnecting

Click **Disconnect** to remove your Stripe account. Existing payments are not affected, but no new payments can be processed. You can reconnect at any time.

### Prerequisites

- A Stripe account (create one at [stripe.com](https://stripe.com) if needed)
- Business identity verification completed on Stripe

---

## Troubleshooting

### Can't Login

- Verify your email and password
- Check if your account is verified
- Check if your account has been disabled (contact your administrator)
- Try resetting your password
- Clear browser cookies and try again

### 2FA Code Not Working

- Ensure your device time is accurate
- Try the next code (codes change every 30 seconds)
- Use a recovery code if needed

### Not Receiving Emails

- Check your spam/junk folder
- Verify your email address is correct
- Contact your administrator

### SSO Login Failed

- Ensure the provider is enabled
- Try logging in with email/password
- Clear browser cookies
- Contact your administrator

---

## Getting Help

- **Documentation**: [docs/](../)
- **Issues**: [GitHub Issues](https://github.com/Sourdough-start/sourdough/issues)
- **Email**: support@example.com
