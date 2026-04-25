# DiscordLinkedRoles

A MediaWiki extension that lets users link their wiki account to their Discord account and have their wiki group memberships reported to Discord as [Linked Role](https://discord.com/developers/docs/topics/oauth2#linked-roles) metadata. Server administrators can then create Discord roles that require a user to hold a specific wiki group membership.

## Requirements

- MediaWiki >= 1.43.0
- PHP >= 8.1
- A Discord application with a bot token and OAuth2 credentials

## Installation

1. Clone or extract the extension into `extensions/DiscordLinkedRoles`.
2. Add to `LocalSettings.php`:
   ```php
   wfLoadExtension( 'DiscordLinkedRoles' );
   ```
3. Run database updates:
   ```
   php maintenance/update.php
   ```
4. Configure the extension (see [Configuration](#configuration)).
5. Push metadata definitions to Discord (see [Initial Setup](#initial-setup)).

## Configuration

All settings go in `LocalSettings.php`.

| Variable | Required | Description |
|---|---|---|
| `$wgDiscordLinkedRolesClientId` | Yes | Discord OAuth2 client ID (also used as the application ID in Discord API paths). |
| `$wgDiscordLinkedRolesClientSecret` | Yes | Discord OAuth2 client secret for token exchange and revocation. |
| `$wgDiscordLinkedRolesBotToken` | Yes | Discord bot token used to push role connection metadata definitions. |
| `$wgDiscordLinkedRolesEncryptionKey` | Yes | Base64-encoded 32-byte symmetric key used to encrypt refresh tokens at rest. |
| `$wgDiscordLinkedRolesReportedGroups` | No | Ordered list of up to 5 wiki group names whose membership will be reported to Discord. Default: `[]`. |

### Generating an encryption key

```bash
openssl rand -base64 32
```

### Example configuration

```php
$wgDiscordLinkedRolesClientId     = '123456789012345678';
$wgDiscordLinkedRolesClientSecret = 'your-client-secret';
$wgDiscordLinkedRolesBotToken     = 'Bot your-bot-token';
$wgDiscordLinkedRolesEncryptionKey = 'base64-encoded-32-byte-key=';
$wgDiscordLinkedRolesReportedGroups = [ 'sysop', 'bureaucrat', 'confirmed' ];
```

## Discord Application Setup

1. Go to the [Discord Developer Portal](https://discord.com/developers/applications) and create an application (or use an existing one).
2. Under **OAuth2**, add the redirect URI for your wiki's special page:
   ```
   https://your.wiki/Special:DiscordLinkedRoles
   ```
3. Under **Bot**, create a bot and copy the token into `$wgDiscordLinkedRolesBotToken`.
4. Copy the **Client ID** and **Client Secret** from the OAuth2 page into their respective config variables.

## Initial Setup

After configuring the extension, push the metadata definitions to Discord so it knows which wiki groups the application can report. This must be re-run any time `$wgDiscordLinkedRolesReportedGroups` changes.

```bash
php extensions/DiscordLinkedRoles/maintenance/SyncDiscordRoleMetadata.php
```

## Usage

Logged-in wiki users can visit `Special:DiscordLinkedRoles` to:

- **Connect** their Discord account via OAuth2.
- **Disconnect** a previously linked account (this also revokes the stored OAuth token and clears the remote role connection).
- **Re-sync** their metadata manually if something looks out of date.

Group membership is also kept in sync automatically: when a user's groups change (via the `UserGroupsChanged` hook), a background job (`discordLinkedRolesSyncUser`) is queued to push updated metadata to Discord.

## How It Works

1. The user authorises the application on Discord. An access token and refresh token are returned.
2. The refresh token is encrypted with AES-256-GCM and stored in the `discord_linked_roles_account` database table.
3. When a sync is needed, the stored refresh token is decrypted, a fresh access token is obtained, and the user's current group memberships are pushed to the Discord [role connection endpoint](https://discord.com/developers/docs/resources/user#update-current-user-application-role-connection).
4. A one-to-one constraint is enforced: each Discord account may only be linked to one wiki account.
