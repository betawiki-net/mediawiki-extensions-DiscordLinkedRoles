<?php

namespace MediaWiki\Extension\DiscordLinkedRoles;

use JobQueueGroup;
use JobSpecification;
use MediaWiki\Config\Config;
use MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountStore;
use MediaWiki\User\Hook\UserGroupsChangedHook;

/**
 * Handles MediaWiki hook callbacks for the DiscordLinkedRoles extension.
 */
class HookHandlers implements UserGroupsChangedHook {

	public function __construct(
		private readonly LinkedAccountStore $linkedAccountStore,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly Config $config
	) {
	}

	/**
	 * Called when a user's group membership changes.
	 * If the changed groups intersect the configured tracked groups, and the
	 * user has a linked Discord account, queue a background sync job.
	 *
	 * @inheritDoc
	 */
	public function onUserGroupsChanged(
		$user,
		$added,
		$removed,
		$performer,
		$reason,
		$oldUGMs,
		$newUGMs
	): void {
		$trackedGroups = $this->config->get( 'DiscordLinkedRolesReportedGroups' );
		if ( !$trackedGroups ) {
			return;
		}

		// $added and $removed are plain string[] of group names.
		$changedGroups = array_merge( $added, $removed );
		if ( !array_intersect( $changedGroups, $trackedGroups ) ) {
			return;
		}

		$userId = $user->getId();
		if ( !$userId || $this->linkedAccountStore->getByUserId( $userId ) === null ) {
			return;
		}

		$this->jobQueueGroup->lazyPush( new JobSpecification(
			'discordLinkedRolesSyncUser',
			[ 'userId' => $userId ]
		) );
	}
}
