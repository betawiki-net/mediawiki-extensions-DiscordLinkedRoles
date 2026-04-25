<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Special;

use MediaWiki\Extension\DiscordLinkedRoles\Crypto\RefreshTokenEncryptor;
use MediaWiki\Extension\DiscordLinkedRoles\Discord\DiscordOAuthClient;
use MediaWiki\Extension\DiscordLinkedRoles\Discord\DiscordRoleConnectionClient;
use MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountRecord;
use MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountStore;
use MediaWiki\Extension\DiscordLinkedRoles\Sync\RoleConnectionSyncService;
use MediaWiki\Html\Html;
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

	public function __construct(
		private readonly LinkedAccountStore $linkedAccountStore,
		private readonly RefreshTokenEncryptor $encryptor,
		private readonly DiscordOAuthClient $oauthClient,
		private readonly DiscordRoleConnectionClient $roleConnectionClient,
		private readonly RoleConnectionSyncService $syncService
	) {
		parent::__construct( 'DiscordLinkedRoles', '', /* $listed */ true );
		$this->logger = LoggerFactory::getInstance( 'DiscordLinkedRoles' );
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
			$out->addHTML(
				Html::element( 'div', [ 'class' => 'ext-discordlinkedroles-not-configured' ],
					$this->msg( 'discordlinkedroles-not-configured' )->text()
				)
			);
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
			$this->getOutput()->addHTML(
				$this->buildMessageHtml( 'error', 'discordlinkedroles-error-wiki-already-linked' )
			);
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
		$out     = $this->getOutput();
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
			$out->addHTML( $this->buildMessageHtml( 'error', 'discordlinkedroles-error-state-mismatch' ) );
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
				$out->addHTML( $this->buildMessageHtml( 'error', 'discordlinkedroles-error-discord-already-linked' ) );
				$this->showStatus();
				return;
			}

			// Enforce 1:1: wiki user must not already have a linked Discord account
			if ( $this->linkedAccountStore->getByUserId( $user->getId() ) !== null ) {
				$this->oauthClient->revokeToken( $tokenResponse->getRefreshToken() );
				$out->addHTML( $this->buildMessageHtml( 'error', 'discordlinkedroles-error-wiki-already-linked' ) );
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

			$out->addHTML(
				$this->buildMessageHtml( 'success', 'discordlinkedroles-connect-success', $discordUsername )
			);

		} catch ( RuntimeException $e ) {
			$this->logger->error( 'Discord OAuth callback failed', [
				'user'      => $user->getName(),
				'exception' => $e->getMessage(),
			] );
			$out->addHTML( $this->buildMessageHtml( 'error', 'discordlinkedroles-error-generic' ) );
		}

		$this->showStatus();
	}

	/**
	 * Handle the manual resync action.
	 * Recomputes current group metadata and pushes it to Discord.
	 */
	private function handleResync(): void {
		$out     = $this->getOutput();
		$user    = $this->getUser();
		$request = $this->getRequest();

		if ( !$user->matchEditToken( $request->getVal( 'wpEditToken', '' ) ) ) {
			$out->addHTML( $this->buildMessageHtml( 'error', 'discordlinkedroles-error-generic' ) );
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
			$out->addHTML( $this->buildMessageHtml( 'success', 'discordlinkedroles-sync-success' ) );
		} else {
			$out->addHTML( $this->buildMessageHtml( 'error', 'discordlinkedroles-error-generic' ) );
		}

		$this->showStatus();
	}

	/**
	 * Handle the disconnect action.
	 * Clears the remote role connection, revokes the authorization, and deletes the local row.
	 */
	private function handleDisconnect(): void {
		$out     = $this->getOutput();
		$user    = $this->getUser();
		$request = $this->getRequest();

		if ( !$user->matchEditToken( $request->getVal( 'wpEditToken', '' ) ) ) {
			$out->addHTML( $this->buildMessageHtml( 'error', 'discordlinkedroles-error-generic' ) );
			$this->showStatus();
			return;
		}

		$record = $this->linkedAccountStore->getByUserId( $user->getId() );
		if ( $record === null ) {
			$this->showStatus();
			return;
		}

		try {
			$refreshToken  = $this->encryptor->decrypt( $record->getEncryptedRefreshToken() );
			$tokenResponse = $this->oauthClient->refreshToken( $refreshToken );

			// Clear the role-connection payload on Discord before revoking
			$this->roleConnectionClient->clearUserRoleConnection( $tokenResponse->getAccessToken() );

			// Revoke the refresh token returned by the refresh grant
			$this->oauthClient->revokeToken( $tokenResponse->getRefreshToken() );

		} catch ( RuntimeException $e ) {
			// Remote cleanup failure is logged but must not block local cleanup
			$this->logger->warning( 'Discord disconnect remote cleanup failed', [
				'user'      => $user->getName(),
				'exception' => $e->getMessage(),
			] );
		}

		$this->linkedAccountStore->deleteByUserId( $user->getId() );

		$this->logger->info( 'Discord account unlinked', [ 'user' => $user->getName() ] );

		$out->addHTML( $this->buildMessageHtml( 'success', 'discordlinkedroles-disconnect-success' ) );
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

		if ( $record === null ) {
			$connectUrl = $this->getPageTitle()->getLocalURL( [ 'action' => 'connect' ] );
			$out->addHTML(
				Html::element( 'div',
					[ 'class' => 'ext-discordlinkedroles-status ext-discordlinkedroles-disconnected' ],
					$this->msg( 'discordlinkedroles-status-disconnected' )->text()
				) .
				Html::rawElement( 'div', [ 'class' => 'ext-discordlinkedroles-actions' ],
					Html::element( 'a',
						[
							'href'  => $connectUrl,
							'class' => 'cdx-button cdx-button--action-progressive',
						],
						$this->msg( 'discordlinkedroles-connect' )->text()
					)
				)
			);
		} else {
			$out->addHTML(
				Html::rawElement( 'div',
					[ 'class' => 'ext-discordlinkedroles-status ext-discordlinkedroles-connected' ],
					$this->msg( 'discordlinkedroles-status-connected', $record->getDiscordUsername() )->parse()
				)
			);

			if ( $record->getLastSyncError() !== null ) {
				$out->addHTML(
					Html::element( 'div', [ 'class' => 'ext-discordlinkedroles-sync-error' ],
						$this->msg( 'discordlinkedroles-last-sync-error', $record->getLastSyncError() )->text()
					)
				);
			} elseif ( $record->getLastSyncAt() !== null ) {
				$out->addHTML(
					Html::element( 'div', [ 'class' => 'ext-discordlinkedroles-sync-info' ],
						$this->msg( 'discordlinkedroles-last-sync-at', $record->getLastSyncAt() )->text()
					)
				);
			}

			$pageUrl = $this->getPageTitle()->getLocalURL();
			$out->addHTML(
				Html::openElement( 'div', [ 'class' => 'ext-discordlinkedroles-actions' ] ) .
				Html::openElement( 'form', [ 'method' => 'post', 'action' => $pageUrl ] ) .
				Html::hidden( 'wpAction', 'resync' ) .
				Html::hidden( 'wpEditToken', $user->getEditToken() ) .
				Html::element( 'button',
					[ 'type' => 'submit', 'class' => 'cdx-button cdx-button--action-progressive' ],
					$this->msg( 'discordlinkedroles-resync' )->text()
				) .
				Html::closeElement( 'form' ) .
				Html::openElement( 'form', [ 'method' => 'post', 'action' => $pageUrl ] ) .
				Html::hidden( 'wpAction', 'disconnect' ) .
				Html::hidden( 'wpEditToken', $user->getEditToken() ) .
				Html::element( 'button',
					[ 'type' => 'submit', 'class' => 'cdx-button cdx-button--action-destructive' ],
					$this->msg( 'discordlinkedroles-disconnect' )->text()
				) .
				Html::closeElement( 'form' ) .
				Html::closeElement( 'div' )
			);
		}
	}

	/**
	 * Build an inline success or error message element.
	 *
	 * @param string $type    'success' or 'error'
	 * @param string $msgKey  i18n message key
	 * @param mixed  ...$params
	 */
	private function buildMessageHtml( string $type, string $msgKey, ...$params ): string {
		$class = $type === 'success'
			? 'ext-discordlinkedroles-msg-success'
			: 'ext-discordlinkedroles-msg-error';
		return Html::rawElement( 'div', [ 'class' => $class ],
			$this->msg( $msgKey, ...$params )->parse()
		);
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
