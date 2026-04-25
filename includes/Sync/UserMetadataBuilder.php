<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Sync;

use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;

/**
 * Builds the per-user role-connection metadata payload from effective group membership.
 */
class UserMetadataBuilder {

	/**
	 * @param UserGroupManager $userGroupManager
	 * @param string[] $groups Ordered list of wiki group names from config.
	 */
	public function __construct(
		private readonly UserGroupManager $userGroupManager,
		private readonly array $groups
	) {
	}

	/**
	 * Compute the metadata key→value map for a user.
	 *
	 * Each value is '1' when the user belongs to the corresponding group
	 * and '0' when they do not, using Discord boolean metadata semantics.
	 *
	 * @return array<string, string>
	 */
	public function buildMetadata( UserIdentity $user ): array {
		$effectiveGroups = $this->userGroupManager->getUserEffectiveGroups( $user );
		$metadata = [];
		foreach ( $this->groups as $group ) {
			$key            = MetadataDefinitionBuilder::groupToKey( $group );
			$metadata[$key] = in_array( $group, $effectiveGroups, true ) ? '1' : '0';
		}
		return $metadata;
	}
}
