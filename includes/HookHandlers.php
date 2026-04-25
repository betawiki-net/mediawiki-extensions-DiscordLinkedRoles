<?php

namespace MediaWiki\Extension\DiscordLinkedRoles;

use JobQueueGroup;
use JobSpecification;
use MediaWiki\Config\Config;
use MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountStore;
use MediaWiki\Extension\DiscordLinkedRoles\Sync\RoleConnectionSyncService;
use MediaWiki\RenameUser\Hook\RenameUserCompleteHook;
use MediaWiki\User\Hook\UserGroupsChangedHook;
use MediaWiki\User\UserIdentity;

/**
 * Handles MediaWiki hook callbacks for the DiscordLinkedRoles extension.
 */
class HookHandlers implements UserGroupsChangedHook, RenameUserCompleteHook {

	public function __construct(
		private readonly LinkedAccountStore $linkedAccountStore,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly Config $config,
		private readonly RoleConnectionSyncService $syncService
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

		$this->queueSyncJobIfLinkedAccount( (int)$user->getId() );
	}

	/**
	 * Keep Discord platform_username and metadata in sync after user renames.
	 *
	 * @inheritDoc
	 */
	public function onRenameUserComplete( int $uid, string $old, string $new ): void {
		$this->queueSyncJobIfLinkedAccount( $uid );
	}

	/**
	 * UserMerge hook: when one account is merged into another, sync the target
	 * account's metadata so Discord sees current groups and username.
	 *
	 * @param UserIdentity $fromUser
	 * @param UserIdentity $toUser
	 */
	public function onMergeAccountFromTo( $fromUser, $toUser ): void {
		if ( $toUser instanceof UserIdentity ) {
			$this->queueSyncJobIfLinkedAccount( (int)$toUser->getId() );
		}
	}

	/**
	 * UserMerge hook: sync immediately before account deletion so metadata is
	 * updated while the user identity still exists.
	 *
	 * @param UserIdentity $deletedUser
	 */
	public function onDeleteAccount( $deletedUser ): void {
		if ( !$deletedUser instanceof UserIdentity ) {
			return;
		}

		$userId = (int)$deletedUser->getId();
		if ( !$userId || $this->linkedAccountStore->getByUserId( $userId ) === null ) {
			return;
		}

		$this->syncService->clearUserRoleConnection( $deletedUser );
	}

	private function queueSyncJobIfLinkedAccount( int $userId ): void {
		if ( !$userId || $this->linkedAccountStore->getByUserId( $userId ) === null ) {
			return;
		}

		$this->jobQueueGroup->lazyPush( new JobSpecification(
			'discordLinkedRolesSyncUser',
			[ 'userId' => $userId ]
		) );
	}
}
