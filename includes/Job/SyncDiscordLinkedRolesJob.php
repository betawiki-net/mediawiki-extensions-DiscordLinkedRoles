<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Job;

use GenericParameterJob;
use Job;
use MediaWiki\MediaWikiServices;

/**
 * Background job that syncs a wiki user's group membership to their Discord
 * role-connection metadata.
 *
 * The job is idempotent: it always derives the current effective groups from
 * UserGroupManager rather than trusting the stale hook diff parameters.
 */
class SyncDiscordLinkedRolesJob extends Job implements GenericParameterJob {

	public function __construct( array $params ) {
		parent::__construct( 'discordLinkedRolesSyncUser', $params );
		$this->removeDuplicates = true;
	}

	/** @inheritDoc */
	public function run(): bool {
		$userId = (int)( $this->params['userId'] ?? 0 );
		if ( !$userId ) {
			return true;
		}

		$services           = MediaWikiServices::getInstance();
		$userIdentityLookup = $services->getUserIdentityLookup();
		$syncService        = $services->get( 'DiscordLinkedRoles.RoleConnectionSyncService' );

		$user = $userIdentityLookup->getUserIdentityByUserId( $userId );
		if ( $user === null ) {
			return true;
		}

		$syncService->syncUser( $user );
		return true;
	}
}
