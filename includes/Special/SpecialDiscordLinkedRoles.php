<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Special;

use MediaWiki\Extension\DiscordLinkedRoles\Crypto\RefreshTokenEncryptor;
use MediaWiki\Extension\DiscordLinkedRoles\Discord\DiscordOAuthClient;
use MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountRecord;
use MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountStore;
use MediaWiki\Extension\DiscordLinkedRoles\Sync\RoleConnectionSyncService;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Utils\MWTimestamp;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Special page for the DiscordLinkedRoles extension.
 *
 * Handles the full OAuth link lifecycle:
 *  - Shows current connection status (disconnected, connected, connected-with-error).
 *  - ?action=connect  — generates state, stores in session, redirects to Discord.
 *  - ?code=…&state=… — validates state, exchanges code, enforces 1:1, persists row.
 *  - POST wpAction=disconnect — refreshes token, clears remote connection, revokes, deletes row.
 */
class SpecialDiscordLinkedRoles extends SpecialPage {

	private LoggerInterface $logger;
	private TemplateParser $templateParser;
	private array $pendingMessages = [];

	public function __construct(
		private readonly LinkedAccountStore $linkedAccountStore,
		private readonly RefreshTokenEncryptor $encryptor,
		private readonly DiscordOAuthClient $oauthClient,
		private readonly RoleConnectionSyncService $syncService
	) {
		parent::__construct( 'DiscordLinkedRoles', '', /* $listed */ true );
		$this->logger = LoggerFactory::getInstance( 'DiscordLinkedRoles' );
		$this->templateParser = new TemplateParser( dirname( __DIR__, 2 ) . '/templates' );
	}

	/** @inheritDoc */
	public function getDescription() {
		return $this->msg( 'discordlinkedroles-specialpage-title' );
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->requireLogin();
		$this->setHeaders();
		$out = $this->getOutput();
		$out->addModules( 'ext.discordLinkedRoles.special' );

		$config = $this->getConfig();
		if ( !$config->get( 'DiscordLinkedRolesClientId' ) ) {
			$out->addHTML( $this->renderTemplate( [
				'notConfigured' => true,
				'widgetTitle' => $this->msg( 'discordlinkedroles-specialpage-title' )->text(),
				'notConfiguredText' => $this->msg( 'discordlinkedroles-not-configured' )->text(),
			] ) );
			return;
		}

		$request = $this->getRequest();

		// OAuth callback: Discord redirects back with ?code=…&state=…
		if ( $request->getVal( 'code' ) !== null ) {
			$this->handleCallback();
			return;
		}

		// Connect initiation: generate state and redirect to Discord
		if ( $request->getVal( 'action' ) === 'connect' ) {
			$this->handleConnect();
			return;
		}

		// Disconnect: must be a POST with CSRF token
		if ( $request->wasPosted() && $request->getVal( 'wpAction' ) === 'disconnect' ) {
			$this->handleDisconnect();
			return;
		}

		// Manual resync: must be a POST with CSRF token
		if ( $request->wasPosted() && $request->getVal( 'wpAction' ) === 'resync' ) {
			$this->handleResync();
			return;
		}

		$this->showStatus();
	}

	// ------------------------------------------------------------------
	// Action handlers
	// ------------------------------------------------------------------

	/**
	 * Initiate the OAuth flow: generate a session-bound state value and
	 * redirect the user to the Discord authorization endpoint.
	 */
	private function handleConnect(): void {
		$user = $this->getUser();

		if ( $this->linkedAccountStore->getByUserId( $user->getId() ) !== null ) {
			$this->addMessage( 'error', 'discordlinkedroles-error-wiki-already-linked' );
			$this->showStatus();
			return;
		}

		$state = bin2hex( random_bytes( 16 ) );
		$session = $this->getRequest()->getSession();
		$session->setSecret( 'discordLinkedRolesState', $state );
		$session->save();

		$config = $this->getConfig();
		$params = http_build_query( [
			'client_id'     => $config->get( 'DiscordLinkedRolesClientId' ),
			'redirect_uri'  => $this->buildCallbackUrl(),
			'response_type' => 'code',
			'scope'         => 'identify role_connections.write',
			'state'         => $state,
		] );

		$this->getOutput()->redirect( 'https://discord.com/oauth2/authorize?' . $params );
	}

	/**
	 * Handle the OAuth callback from Discord.
	 * Validates state, exchanges code, enforces 1:1 mapping, and persists the link.
	 */
	private function handleCallback(): void {
		$request = $this->getRequest();
		$user    = $this->getUser();

		$code  = $request->getVal( 'code', '' );
		$state = $request->getVal( 'state', '' );

		// Validate state to prevent CSRF / confused-deputy attacks
		$session       = $request->getSession();
		$expectedState = $session->getSecret( 'discordLinkedRolesState' );
		$session->remove( 'discordLinkedRolesState' );

		if ( !$expectedState || !hash_equals( (string)$expectedState, $state ) ) {
			$this->logger->warning( 'Discord OAuth callback: state mismatch', [
				'user' => $user->getName(),
			] );
			$this->addMessage( 'error', 'discordlinkedroles-error-state-mismatch' );
			$this->showStatus();
			return;
		}

		try {
			$tokenResponse = $this->oauthClient->exchangeCode( $code, $this->buildCallbackUrl() );
			$discordUser   = $this->oauthClient->fetchCurrentUser( $tokenResponse->getAccessToken() );

			$discordUserId   = $discordUser['id'];
			$discordUsername = $discordUser['global_name'] ?? $discordUser['username'] ?? '';

			// Enforce 1:1: Discord account must not be linked to another wiki user
			if ( $this->linkedAccountStore->getByDiscordUserId( $discordUserId ) !== null ) {
				$this->oauthClient->revokeToken( $tokenResponse->getRefreshToken() );
				$this->addMessage( 'error', 'discordlinkedroles-error-discord-already-linked' );
				$this->showStatus();
				return;
			}

			// Enforce 1:1: wiki user must not already have a linked Discord account
			if ( $this->linkedAccountStore->getByUserId( $user->getId() ) !== null ) {
				$this->oauthClient->revokeToken( $tokenResponse->getRefreshToken() );
				$this->addMessage( 'error', 'discordlinkedroles-error-wiki-already-linked' );
				$this->showStatus();
				return;
			}

			$encryptedToken = $this->encryptor->encrypt( $tokenResponse->getRefreshToken() );
			$now            = MWTimestamp::now( TS_MW );
			$record         = new LinkedAccountRecord(
				$user->getId(),
				$discordUserId,
				$discordUsername,
				$encryptedToken,
				$tokenResponse->getScope(),
				$now,
				$now,
				null,
				null
			);
			$this->linkedAccountStore->insert( $record );

			// Inline post-link sync: push current group metadata to Discord immediately.
			$this->syncService->syncUser( $user );

			$this->logger->info( 'Discord account linked', [
				'user'            => $user->getName(),
				'discordUsername' => $discordUsername,
			] );

			$this->addMessage( 'success', 'discordlinkedroles-connect-success', $discordUsername );

		} catch ( RuntimeException $e ) {
			$this->logger->error( 'Discord OAuth callback failed', [
				'user'      => $user->getName(),
				'exception' => $e->getMessage(),
			] );
			$this->addMessage( 'error', 'discordlinkedroles-error-generic' );
		}

		$this->showStatus();
	}

	/**
	 * Handle the manual resync action.
	 * Recomputes current group metadata and pushes it to Discord.
	 */
	private function handleResync(): void {
		$user    = $this->getUser();
		$request = $this->getRequest();

		if ( !$user->matchEditToken( $request->getVal( 'wpEditToken', '' ) ) ) {
			$this->addMessage( 'error', 'discordlinkedroles-error-generic' );
			$this->showStatus();
			return;
		}

		if ( $this->linkedAccountStore->getByUserId( $user->getId() ) === null ) {
			$this->showStatus();
			return;
		}

		$this->syncService->syncUser( $user );

		// Re-read the record to reflect the updated sync result.
		$record = $this->linkedAccountStore->getByUserId( $user->getId() );
		if ( $record !== null && $record->getLastSyncError() === null ) {
			$this->addMessage( 'success', 'discordlinkedroles-sync-success' );
		} else {
			$this->addMessage( 'error', 'discordlinkedroles-error-generic' );
		}

		$this->showStatus();
	}

	/**
	 * Handle the disconnect action.
	 * Clears the remote role connection, revokes the authorization, and deletes the local row.
	 */
	private function handleDisconnect(): void {
		$user    = $this->getUser();
		$request = $this->getRequest();

		if ( !$user->matchEditToken( $request->getVal( 'wpEditToken', '' ) ) ) {
			$this->addMessage( 'error', 'discordlinkedroles-error-generic' );
			$this->showStatus();
			return;
		}

		$record = $this->linkedAccountStore->getByUserId( $user->getId() );
		if ( $record === null ) {
			$this->showStatus();
			return;
		}

		$this->syncService->disconnectUser( $user );

		$this->linkedAccountStore->deleteByUserId( $user->getId() );

		$this->logger->info( 'Discord account unlinked', [ 'user' => $user->getName() ] );

		$this->addMessage( 'success', 'discordlinkedroles-disconnect-success' );
		$this->showStatus();
	}

	// ------------------------------------------------------------------
	// UI helpers
	// ------------------------------------------------------------------

	/**
	 * Render the current connection status panel and action buttons.
	 */
	private function showStatus(): void {
		$out  = $this->getOutput();
		$user = $this->getUser();

		$record = $this->linkedAccountStore->getByUserId( $user->getId() );
		$templateData = [
			'messages' => $this->pendingMessages,
			'notConfigured' => false,
			'widgetTitle' => $this->msg( 'discordlinkedroles-specialpage-title' )->text(),
		];

		$this->pendingMessages = [];

		if ( $record === null ) {
			$templateData += [
				'isDisconnected' => true,
				'statusHtml' => $this->msg( 'discordlinkedroles-status-disconnected' )->escaped(),
				'connectFormAction' => $this->getPageTitle()->getLocalURL(),
				'connectLabel' => $this->msg( 'discordlinkedroles-connect' )->text(),
			];
		} else {
			$pageUrl = $this->getPageTitle()->getLocalURL();
			$lastSyncError = $record->getLastSyncError();
			$lastSyncAt = $record->getLastSyncAt();
			$lastSyncAtFormatted = $lastSyncAt !== null
				? $this->getLanguage()->userTimeAndDate( $lastSyncAt, $user )
				: null;
			$templateData += [
				'isConnected' => true,
				'statusHtml' => $this->msg( 'discordlinkedroles-status-connected', $record->getDiscordUsername() )->parse(),
				'pageUrl' => $pageUrl,
				'csrfToken' => $user->getEditToken(),
				'resyncLabel' => $this->msg( 'discordlinkedroles-resync' )->text(),
				'disconnectLabel' => $this->msg( 'discordlinkedroles-disconnect' )->text(),
				'hasSyncError' => $lastSyncError !== null,
				'syncErrorText' => $lastSyncError !== null
					? $this->msg( 'discordlinkedroles-last-sync-error', $lastSyncError )->text()
					: '',
				'hasSyncInfo' => $lastSyncError === null && $lastSyncAt !== null,
				'syncInfoText' => $lastSyncAtFormatted !== null
					? $this->msg( 'discordlinkedroles-last-sync-at', $lastSyncAtFormatted )->text()
					: '',
			];
		}

		$out->addHTML( $this->renderTemplate( $templateData ) );
	}

	/**
	 * Queue a status message for rendering.
	 *
	 * @param string $type    'success' or 'error'
	 * @param string $msgKey  i18n message key
	 * @param mixed  ...$params
	 */
	private function addMessage( string $type, string $msgKey, ...$params ): void {
		$class = $type === 'success'
			? 'cdx-message cdx-message--block cdx-message--success'
			: 'cdx-message cdx-message--block cdx-message--error';

		$this->pendingMessages[] = [
			'class' => $class,
			'html' => $this->msg( $msgKey, ...$params )->parse(),
		];
	}

	/**
	 * Render the Mustache layout for the special page.
	 *
	 * @param array $templateData Data for the Mustache template.
	 */
	private function renderTemplate( array $templateData ): string {
		return $this->templateParser->processTemplate( 'SpecialDiscordLinkedRoles', $templateData );
	}

	/**
	 * Build the absolute HTTPS callback URL for this special page.
	 */
	private function buildCallbackUrl(): string {
		return $this->getPageTitle()->getFullURL( '', false, PROTO_HTTPS );
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'users';
	}
}
